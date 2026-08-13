#!/usr/bin/env python3
"""Install Kuaiz CMS through real Apache or OpenLiteSpeed HTTP requests."""
from __future__ import annotations

import argparse
import http.client
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.parse
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import php_cms_release  # noqa: E402
from scripts import build_php_cms_installer as installer_builder  # noqa: E402


HOSTS = {
    "apache74-no-rewrite": {
        "dockerfile": ROOT / "tests/host-matrix/apache74-no-webp.Dockerfile",
        "image": "kuaiz/php-cms-host-test:apache74-no-webp",
        "mount": "/var/www",
        "document_root": "html",
        "server_header": "apache",
        "rewrite": False,
        "command": [
            "sh",
            "-c",
            "sed -i 's/AllowOverride All/AllowOverride None/' "
            "/etc/apache2/conf-enabled/kuaiz-cms.conf && exec apache2-foreground",
        ],
    },
    "apache74-restricted-parent": {
        "dockerfile": ROOT / "tests/host-matrix/apache74-no-webp.Dockerfile",
        "image": "kuaiz/php-cms-host-test:apache74-no-webp",
        "mount": "/var/www",
        "document_root": "html",
        "server_header": "apache",
        "parent_writable": False,
    },
    "apache74-no-webp": {
        "dockerfile": ROOT / "tests/host-matrix/apache74-no-webp.Dockerfile",
        "image": "kuaiz/php-cms-host-test:apache74-no-webp",
        "mount": "/var/www",
        "document_root": "html",
        "server_header": "apache",
    },
    "apache74": {
        "dockerfile": ROOT / "tests/host-matrix/apache74.Dockerfile",
        "image": "kuaiz/php-cms-host-test:apache74",
        "mount": "/var/www",
        "document_root": "html",
        "server_header": "apache",
    },
    "apache": {
        "dockerfile": ROOT / "tests/host-matrix/apache.Dockerfile",
        "image": "kuaiz/php-cms-host-test:apache",
        "mount": "/var/www",
        "document_root": "html",
        "server_header": "apache",
    },
    "openlitespeed": {
        "dockerfile": ROOT / "tests/host-matrix/openlitespeed.Dockerfile",
        "image": "kuaiz/php-cms-host-test:openlitespeed",
        "mount": "/var/www/vhosts/localhost",
        "document_root": "html",
        "server_header": "litespeed",
        "php_cli": "/usr/local/lsws/lsphp83/bin/php",
    },
}


class HostMatrixError(RuntimeError):
    """A real-host compatibility assertion failed."""


def _run(command: list[str], *, capture: bool = False) -> subprocess.CompletedProcess:
    return subprocess.run(
        command,
        cwd=ROOT,
        check=True,
        text=True,
        stdout=subprocess.PIPE if capture else None,
        stderr=subprocess.PIPE if capture else None,
    )


def _http(
    port: int,
    path: str,
    fields: dict[str, str] | None = None,
    cookie: str | None = None,
    scheme: str = "https",
) -> tuple[int, dict[str, str], str]:
    body = None if fields is None else urllib.parse.urlencode(fields).encode()
    headers = {
        "Host": "cms-host-matrix.test",
        "X-Forwarded-Proto": scheme,
        "User-Agent": "Kuaiz-CMS-Host-Matrix/1.0",
    }
    if body is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
        headers["Content-Length"] = str(len(body))
    if cookie is not None:
        headers["Cookie"] = cookie
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=15)
    try:
        connection.request("GET" if body is None else "POST", path, body=body, headers=headers)
        response = connection.getresponse()
        payload = response.read().decode("utf-8", "replace")
        response_headers: dict[str, str] = {}
        for key, value in response.getheaders():
            normalized = key.lower()
            response_headers[normalized] = (
                response_headers[normalized] + "\n" + value
                if normalized in response_headers else value
            )
        return response.status, response_headers, payload
    finally:
        connection.close()


def _wait_for_installer(port: int, scheme: str) -> tuple[dict[str, str], str]:
    last = "server did not answer"
    for _ in range(60):
        try:
            status, headers, body = _http(port, "/install.php", scheme=scheme)
            if status == 200:
                return headers, body
            last = f"HTTP {status}: {body[:500]}"
        except OSError as error:
            last = str(error)
        time.sleep(1)
    raise HostMatrixError(f"主机启动或安装器 GET 验证超时：{last}")


def _build_installer(target: Path, install_token: str) -> dict:
    private_key, public_key = php_cms_release.generate_key_pair()
    envelope = installer_builder.release_envelope(issued_at=1785900000)
    release_token = php_cms_release.sign_envelope(envelope, private_key)
    return installer_builder.build_installer(
        target,
        install_token,
        release_token,
        public_key,
    )


def _container_port(name: str) -> int:
    result = _run(["docker", "port", name, "80/tcp"], capture=True)
    line = result.stdout.strip().splitlines()[-1]
    try:
        return int(line.rsplit(":", 1)[1])
    except (IndexError, ValueError) as error:
        raise HostMatrixError(f"无法读取测试容器端口：{line}") from error


def _container_php(
    container: str,
    config: dict,
    source: str,
    *arguments: str,
) -> str:
    """Read private install state as the container root without weakening it."""
    result = _run([
        "docker",
        "exec",
        container,
        config.get("php_cli", "php"),
        "-r",
        source,
        *arguments,
    ], capture=True)
    return result.stdout


def _assert_site(
    host: str,
    port: int,
    root: Path,
    build: dict,
    container: str,
    scheme: str,
) -> dict:
    config = HOSTS[host]
    request = lambda path, fields=None, cookie=None: _http(
        port, path, fields, cookie, scheme
    )
    headers, form = _wait_for_installer(port, scheme)
    if config["server_header"] not in headers.get("server", "").lower():
        raise HostMatrixError(f"没有经过预期 Web 服务器：{headers.get('server', '')}")
    token_match = re.search(
        r'name="form_token" value="([0-9]+\.[a-f0-9]{64}\.[a-f0-9]{64})"',
        form,
    )
    cookie = headers.get("set-cookie", "").split(";", 1)[0]
    if ("安装文件已经检查通过" not in form
            or '<form method="post"' not in form
            or 'name="install_token"' in form
            or 'id="generate-password"' not in form
            or 'id="copy-password"' not in form
            or 'id="site-description-count"' not in form
            or "建议写 50–200 字" not in form
            or 'minlength="12"' not in form
            or "crypto.getRandomValues" not in form
            or token_match is None
            or not cookie.startswith("kuaiz_cms_install_claim=")):
        raise HostMatrixError("安装器没有显示受保护的管理员表单")
    nonce_match = re.search(r'<script nonce="([A-Za-z0-9+/=]+)">', form)
    if (nonce_match is None or (
        "script-src 'nonce-" + nonce_match.group(1) + "'"
    ) not in headers.get("content-security-policy", "")):
        raise HostMatrixError("安装页的密码按钮没有受到页面安全规则保护")
    installer_cookie = headers.get("set-cookie", "").lower()
    if (scheme == "https") != ("; secure" in installer_cookie):
        raise HostMatrixError("安装页的状态 Cookie 与当前访问方式不匹配")

    install_fields = {
        "form_token": token_match.group(1),
        "email": "host-matrix@example.com",
        "display_name": "主机矩阵管理员",
        "password": "Ab3!defghijk",
        "password_confirmation": "Ab3!defghijk",
        "site_name": "快智主机矩阵测试站",
        "site_description": "用于验证独立 CMS 在真实 PHP 主机上的安装、发布、安全与搜索引擎策略。",
        "site_language": "zh-CN",
    }
    too_long_fields = dict(install_fields)
    too_long_fields["site_description"] = "业" * 501
    status, _, too_long = request(
        "/install.php", too_long_fields, cookie=cookie
    )
    if status not in (400, 422) or "业务介绍最多填写 500 字" not in too_long:
        raise HostMatrixError("业务介绍超过 500 字时没有给出明确提醒")

    status, _, installed = request(
        "/install.php", install_fields, cookie=cookie
    )
    if status != 200 or "网站已经装好" not in installed:
        raise HostMatrixError(f"真实 HTTP 安装失败（HTTP {status}）：{installed[:1000]}")
    if (root / config["document_root"] / "install.php").exists():
        raise HostMatrixError("安装成功后安装器没有自动删除")

    rewrite_reload_required = False
    status, admin_headers, admin = request("/admin/")
    if host == "openlitespeed":
        if status == 404:
            rewrite_reload_required = True
            _run([
                "docker", "exec", container,
                "/usr/local/lsws/bin/lswsctrl", "restart",
            ], capture=True)
            last_status = status
            for _ in range(30):
                try:
                    last_status, admin_headers, admin = request("/admin/")
                    if last_status == 401:
                        break
                except OSError:
                    pass
                time.sleep(1)
            status = last_status
    if status != 401 or "登录网站后台" not in admin:
        raise HostMatrixError(f"后台重写路由不可用（HTTP {status}）")
    login_token = re.search(r'name="_login_csrf" value="([a-f0-9]{64})"', admin)
    login_cookie_match = re.search(
        r'(?m)^((?:__Host-)?kuaiz_cms_login_csrf=[a-f0-9]{64})',
        admin_headers.get("set-cookie", ""),
    )
    if login_token is None or login_cookie_match is None:
        raise HostMatrixError("后台登录页没有创建安全登录状态")
    status, login_headers, _ = request("/admin/login/", {
        "_login_csrf": login_token.group(1),
        "username": "host-matrix@example.com",
        "password": "Ab3!defghijk",
    }, cookie=login_cookie_match.group(1))
    if status != 303:
        raise HostMatrixError(f"后台登录失败（HTTP {status}）")
    session_names = (
        ("__Host-kuaiz_cms_session", "__Host-kuaiz_cms_csrf")
        if scheme == "https" else ("kuaiz_cms_session", "kuaiz_cms_csrf")
    )
    login_cookie_lines = login_headers.get("set-cookie", "")
    active_cookies = []
    for name in session_names:
        match = re.search(rf'(?m)^({re.escape(name)}=[a-f0-9]{{64}})', login_cookie_lines)
        if match is None:
            raise HostMatrixError("后台登录没有返回完整会话")
        active_cookies.append(match.group(1))
    if (scheme == "https") != ("; secure" in login_cookie_lines.lower()):
        raise HostMatrixError("后台会话 Cookie 与当前访问方式不匹配")
    status, _, dashboard = request("/admin/", cookie="; ".join(active_cookies))
    if status != 200 or "管理网站内容" not in dashboard:
        raise HostMatrixError("登录后无法打开网站后台")
    for path, marker in (
        ("/admin/account/", "修改登录密码"),
        ("/admin/users/", "管理后台成员"),
        ("/admin/backups/", "网站备份"),
    ):
        status, _, page = request(path, cookie="; ".join(active_cookies))
        if status != 200 or marker not in page:
            raise HostMatrixError(f"新增后台物理路由不可用：{path}（HTTP {status}）")

    status, _, public_list = request("/directory/")
    if status != 200 or "目录条目" not in public_list:
        raise HostMatrixError(f"公开栏目真实入口不可用（HTTP {status}）")
    status, _, public_query_list = request("/?page=directory")
    if status != 200 or "目录条目" not in public_query_list:
        raise HostMatrixError(f"公开栏目通用入口不可用（HTTP {status}）")

    if config.get("rewrite") is False:
        status, redirect_headers, _ = request("/robots.txt")
        if status != 301 or not redirect_headers.get("location", "").endswith("/robots.txt/"):
            raise HostMatrixError(f"robots.txt 真实入口没有正确转向（HTTP {status}）")
        status, headers, robots = request("/robots.txt/")
        if status != 200 or "Disallow: /" not in robots:
            raise HostMatrixError(f"robots.txt 真实入口不可用（HTTP {status}）")
        status, redirect_headers, _ = request("/sitemap.xml")
        if status != 301 or not redirect_headers.get("location", "").endswith("/sitemap.xml/"):
            raise HostMatrixError(f"网站地图真实入口没有正确转向（HTTP {status}）")
        status, _, sitemap = request("/sitemap.xml/")
        if status != 200 or "<urlset" not in sitemap:
            raise HostMatrixError(f"网站地图真实入口不可用（HTTP {status}）")

    if config.get("rewrite") is not False:
        status, headers, robots = request("/robots.txt")
        if status == 301 and headers.get("location", "").endswith("/robots.txt/"):
            status, headers, robots = request("/robots.txt/")
        if status != 200 or "Disallow: /" not in robots:
            raise HostMatrixError("未配置网站没有默认禁止搜索引擎收录")
        if "noindex" not in headers.get("x-robots-tag", ""):
            raise HostMatrixError("robots.txt 缺少 noindex 响应头")
        status, headers, _ = request("/definitely-missing")
        missing_has_noindex = "noindex" in headers.get("x-robots-tag", "")
        if status != 404 or (host != "openlitespeed" and not missing_has_noindex):
            raise HostMatrixError(
                "404 重写或 noindex 响应不正确："
                f"HTTP {status} / {headers.get('x-robots-tag', '')}"
            )
    for protected in ("/.htaccess", "/.user.ini"):
        status, _, _ = request(protected)
        if status not in (403, 404):
            raise HostMatrixError(f"服务器公开了敏感点文件：{protected}")

    private_roots = list(root.glob(".kuaiz-cms-*"))
    private_location = "outside-document-root"
    if config.get("parent_writable") is False:
        private_roots = list((root / config["document_root"]).glob(".kuaiz-cms-*"))
        private_location = "protected-document-root"
    if len(private_roots) != 1:
        raise HostMatrixError("私有内核没有安装在预期的安全位置")
    private_relative = private_roots[0].relative_to(root).as_posix()
    private_container = f"{config['mount'].rstrip('/')}/{private_relative}"
    receipt = json.loads(_container_php(
        container,
        config,
        "$value=file_get_contents($argv[1]);"
        "if ($value === false) { fwrite(STDERR, 'receipt unreadable'); exit(2); }"
        "echo $value;",
        f"{private_container}/install-receipt.json",
    ))
    if receipt.get("payload_sha256") != build["payload_sha256"]:
        raise HostMatrixError("安装回执与已签名载荷不一致")
    if receipt.get("site_base_url") != f"{scheme}://cms-host-matrix.test":
        raise HostMatrixError("安装回执没有绑定实际访问的正式域名")
    if receipt.get("site_language") != "zh-CN":
        raise HostMatrixError("安装回执没有锁定建站时选择的唯一语言")
    if receipt.get("private_location") != private_location:
        raise HostMatrixError("安装回执没有记录实际的私有目录位置")
    if private_location == "protected-document-root":
        for private_path in (
            f"/{private_roots[0].name}/var/cms.sqlite",
            f"/{private_roots[0].name}/cms-manifest.json",
            f"/{private_roots[0].name}/README.md",
        ):
            status, _, _ = request(private_path)
            if status not in (403, 404):
                raise HostMatrixError(f"服务器公开了 CMS 私有文件：{private_path}")
        outer_guard = root / config["document_root"] / ".htaccess"
        saved_outer_guard = root / config["document_root"] / ".outer-guard-test"
        outer_guard.rename(saved_outer_guard)
        try:
            status, _, _ = request(f"/{private_roots[0].name}/README.md")
            if status not in (403, 404):
                raise HostMatrixError("CMS 私有目录自身的访问保护没有生效")
        finally:
            saved_outer_guard.rename(outer_guard)
    database_state = json.loads(_container_php(
        container,
        config,
        "$database=new PDO('sqlite:'.$argv[1]);"
        "$schema=(int)$database->query('PRAGMA user_version')->fetchColumn();"
        "$users=(int)$database->query('SELECT COUNT(*) FROM cms_users')->fetchColumn();"
        "echo json_encode(array('schema'=>$schema,'users'=>$users));",
        f"{private_container}/var/cms.sqlite",
    ))
    schema = database_state["schema"]
    users = database_state["users"]
    if schema != 5 or users != 1:
        raise HostMatrixError("真实主机初始化后的数据库状态不正确")
    return {
        "host": host,
        "scheme": scheme,
        "server": headers.get("server", ""),
        "database_schema_version": schema,
        "admin_users": users,
        "rewrite_reload_required": rewrite_reload_required,
        "payload_sha256": build["payload_sha256"],
        "release_public_key_fingerprint": build["release_public_key_fingerprint"],
    }


def run_host(host: str, *, build_image: bool = True, scheme: str = "https") -> dict:
    if shutil.which("docker") is None:
        raise HostMatrixError("当前环境没有 Docker")
    config = HOSTS[host]
    if build_image:
        _run([
            "docker", "build", "--pull",
            "--file", str(config["dockerfile"]),
            "--tag", config["image"],
            str(config["dockerfile"].parent),
        ])

    temporary = tempfile.TemporaryDirectory(prefix=f"kuaiz-cms-{host}-")
    root = Path(temporary.name)
    document_root = root / config["document_root"]
    document_root.mkdir(mode=0o777)
    os.chmod(root, 0o777)
    os.chmod(document_root, 0o777)
    install_token = "host-matrix-installer-token-0123456789-abcdef"
    installer = document_root / "install.php"
    build = _build_installer(installer, install_token)
    os.chmod(installer, 0o666)
    if config.get("parent_writable") is False:
        os.chmod(root, 0o555)
    container = f"kuaiz-cms-{host}-{secrets.token_hex(5)}"
    started = False
    try:
        command = [
            "docker", "run", "--detach", "--name", container,
            "--publish", "127.0.0.1::80/tcp",
            "--mount", f"type=bind,src={root},dst={config['mount']}",
            config["image"],
        ]
        command.extend(config.get("command", []))
        _run(command, capture=True)
        started = True
        result = _assert_site(
            host,
            _container_port(container),
            root,
            build,
            container,
            scheme,
        )
        return result
    except Exception:
        if started:
            logs = subprocess.run(
                ["docker", "logs", container],
                check=False,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
            ).stdout
            print(f"\n--- {host} container logs ---\n{logs[-12000:]}", file=sys.stderr)
        raise
    finally:
        if started:
            subprocess.run(
                ["docker", "rm", "--force", container],
                check=False,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            subprocess.run(
                [
                    "docker", "run", "--rm", "--entrypoint", "/bin/chmod",
                    "--mount", f"type=bind,src={root},dst={config['mount']}",
                    config["image"], "-R", "a+rwX", config["mount"],
                ],
                check=False,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
        temporary.cleanup()


def main() -> int:
    parser = argparse.ArgumentParser(description="快智 CMS 真实 PHP 主机安装矩阵")
    parser.add_argument("--server", choices=[*HOSTS, "all"], default="all")
    parser.add_argument("--scheme", choices=["http", "https"], default="https")
    parser.add_argument("--skip-build", action="store_true")
    args = parser.parse_args()
    selected = list(HOSTS) if args.server == "all" else [args.server]
    results = [
        run_host(host, build_image=not args.skip_build, scheme=args.scheme)
        for host in selected
    ]
    print(json.dumps({"ok": True, "results": results}, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
