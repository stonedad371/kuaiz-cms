#!/usr/bin/env python3
"""Upgrade the previous signed public source release through real Apache HTTP."""
from __future__ import annotations

import argparse
import hashlib
import http.client
import json
import os
import re
import secrets
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import php_cms_release  # noqa: E402
from scripts import build_php_cms_installer as installer_builder  # noqa: E402


PREVIOUS_VERSION = "0.1.9-dev"
PREVIOUS_SOURCE_URL = (
    "https://cms.kuaiz.net/releases/0.1.9-dev/"
    "kuaiz-cms-community-0.1.9-dev.zip"
)
PREVIOUS_SOURCE_SHA256 = "1ed9a7a97728d19d41ed3511332b47507999c83a1c3557cf83901eb041f2fac7"
IMAGE = "kuaiz/php-cms-host-test:apache"
DOCKERFILE = ROOT / "tests/host-matrix/apache.Dockerfile"
MOUNT = "/var/www"
DOCUMENT_ROOT = "html"


class UpgradeTestError(RuntimeError):
    """A cross-release install, upgrade, or rollback assertion failed."""


def run(command: list[str], *, cwd: Path = ROOT, capture: bool = False) -> subprocess.CompletedProcess:
    return subprocess.run(
        command,
        cwd=cwd,
        check=True,
        text=True,
        stdout=subprocess.PIPE if capture else None,
        stderr=subprocess.PIPE if capture else None,
    )


def fetch_previous(target: Path, source: Path | None) -> None:
    if source is not None:
        if source.is_symlink() or not source.is_file():
            raise UpgradeTestError("上一公开版源码包不存在或类型不安全")
        shutil.copyfile(source, target)
    else:
        request = urllib.request.Request(
            PREVIOUS_SOURCE_URL,
            headers={"User-Agent": "Kuaiz-CMS-Upgrade-Test/1.0"},
        )
        with urllib.request.urlopen(request, timeout=30) as response:
            if response.status != 200:
                raise UpgradeTestError(f"上一公开版下载失败：HTTP {response.status}")
            body = response.read(10 * 1024 * 1024 + 1)
        if len(body) > 10 * 1024 * 1024:
            raise UpgradeTestError("上一公开版源码包大小异常")
        target.write_bytes(body)
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    if digest != PREVIOUS_SOURCE_SHA256:
        raise UpgradeTestError("上一公开版源码包摘要不匹配")


def extract_previous(archive_path: Path, target: Path) -> Path:
    with zipfile.ZipFile(archive_path) as archive:
        names = archive.namelist()
        prefix = f"kuaiz-cms-community-{PREVIOUS_VERSION}/"
        if not names or any(
            not name.startswith(prefix)
            or name.startswith("/")
            or ".." in Path(name).parts
            or stat.S_ISLNK(archive.getinfo(name).external_attr >> 16)
            for name in names
        ):
            raise UpgradeTestError("上一公开版源码包路径不安全")
        archive.extractall(target)
    source = target / prefix.rstrip("/")
    if not (source / "installer-template.php").is_file():
        raise UpgradeTestError("上一公开版缺少安装器模板")
    manifest = json.loads((source / "cms-manifest.json").read_text("utf-8"))
    if manifest.get("version") != PREVIOUS_VERSION:
        raise UpgradeTestError("上一公开版版本号不匹配")
    return source


def build_installer(source: Path, output: Path, private_key: Path, public_key: Path) -> None:
    original_cms = installer_builder.CMS
    original_template = installer_builder.TEMPLATE
    try:
        installer_builder.CMS = source
        installer_builder.TEMPLATE = source / "installer-template.php"
        envelope = installer_builder.release_envelope(issued_at=1785900000)
        token = php_cms_release.sign_envelope(envelope, private_key.read_bytes())
        installer_builder.build_installer(
            output,
            "release-upgrade-test-token-0123456789",
            token,
            public_key.read_bytes(),
        )
    finally:
        installer_builder.CMS = original_cms
        installer_builder.TEMPLATE = original_template


def request_http(
    port: int,
    path: str,
    fields: dict[str, str] | None = None,
    cookie: str | None = None,
) -> tuple[int, dict[str, str], str]:
    body = None if fields is None else urllib.parse.urlencode(fields).encode()
    headers = {
        "Host": "cms-upgrade.test",
        "X-Forwarded-Proto": "https",
        "User-Agent": "Kuaiz-CMS-Upgrade-Test/1.0",
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


def wait_installer(port: int) -> tuple[dict[str, str], str]:
    last = "server did not answer"
    for _ in range(60):
        try:
            status, headers, body = request_http(port, "/install.php")
            if status == 200:
                return headers, body
            last = f"HTTP {status}: {body[:300]}"
        except OSError as error:
            last = str(error)
        time.sleep(1)
    raise UpgradeTestError(f"安装器没有就绪：{last}")


def form_state(headers: dict[str, str], body: str) -> tuple[str, str]:
    token = re.search(
        r'name="form_token" value="([0-9]+\.[a-f0-9]{64}\.[a-f0-9]{64})"',
        body,
    )
    cookie = headers.get("set-cookie", "").split(";", 1)[0]
    if token is None or not cookie.startswith("kuaiz_cms_install_claim="):
        raise UpgradeTestError("安装器没有返回受保护表单")
    return token.group(1), cookie


def container_port(container: str) -> int:
    value = run(["docker", "port", container, "80/tcp"], capture=True).stdout.strip()
    return int(value.splitlines()[-1].rsplit(":", 1)[1])


def container_php(container: str, source: str, *arguments: str) -> str:
    return run([
        "docker", "exec", container, "php", "-r", source, *arguments,
    ], capture=True).stdout


def private_root(host_root: Path) -> Path:
    candidates = [
        path for path in host_root.glob(".kuaiz-cms-*")
        if path.is_dir() and not path.name.startswith(".kuaiz-cms-stage-")
    ]
    if len(candidates) != 1:
        raise UpgradeTestError("没有找到唯一的 CMS 私有目录")
    return candidates[0]


def install_previous(port: int) -> None:
    headers, body = wait_installer(port)
    form_token, cookie = form_state(headers, body)
    status, _, installed = request_http(port, "/install.php", {
        "form_token": form_token,
        "email": "upgrade-owner@example.com",
        "display_name": "升级测试管理员",
        "password": "Upgrade password 2026!",
        "password_confirmation": "Upgrade password 2026!",
        "site_name": "跨版本升级测试站",
        "site_description": "用于确认上一公开版本的数据可以升级，并在失败时完整回滚。",
        "site_language": "zh-CN",
    }, cookie)
    if status != 200 or "网站已经装好" not in installed:
        raise UpgradeTestError(f"上一公开版安装失败：HTTP {status} {installed[:500]}")


def seed_content(container: str, private_container: str, slug: str) -> None:
    result = container_php(
        container,
        "require $argv[1].'/src/Compatibility.php';"
        "require $argv[1].'/src/Database.php';"
        "require $argv[1].'/src/ContentRepository.php';"
        "$pdo=KuaizCmsDatabase::connect($argv[1].'/var/cms.sqlite');"
        "$saved=KuaizCmsContentRepository::save($pdo,'kuaiz.directory','listing',$argv[2],"
        "array('title'=>'Upgrade Sentinel','summary'=>'Preserved across release upgrade',"
        "'phone'=>'+86 10 5555 2026','website'=>'https://example.com/upgrade'),"
        "'test:release-upgrade',true);echo json_encode($saved);",
        private_container,
        slug,
    )
    if json.loads(result).get("status") != "published":
        raise UpgradeTestError("无法在上一公开版创建升级哨兵内容")


def login_is_preserved(port: int) -> None:
    status, headers, body = request_http(port, "/admin/")
    token = re.search(r'name="_login_csrf" value="([a-f0-9]{64})"', body)
    cookie = re.search(
        r'(?m)^(__Host-kuaiz_cms_login_csrf=[a-f0-9]{64})',
        headers.get("set-cookie", ""),
    )
    if status != 401 or token is None or cookie is None:
        raise UpgradeTestError("升级后后台登录页不可用")
    status, _, _ = request_http(port, "/admin/login/", {
        "_login_csrf": token.group(1),
        "username": "upgrade-owner@example.com",
        "password": "Upgrade password 2026!",
    }, cookie.group(1))
    if status != 303:
        raise UpgradeTestError("升级后原管理员密码不可用")


def run_case(previous_installer: Path, current_installer: Path, *, rollback: bool) -> dict:
    temporary = tempfile.TemporaryDirectory(prefix="kuaiz-cms-release-upgrade-")
    host_root = Path(temporary.name)
    document_root = host_root / DOCUMENT_ROOT
    document_root.mkdir(mode=0o777)
    os.chmod(host_root, 0o777)
    os.chmod(document_root, 0o777)
    shutil.copyfile(previous_installer, document_root / "install.php")
    os.chmod(document_root / "install.php", 0o666)
    container = f"kuaiz-cms-upgrade-{secrets.token_hex(5)}"
    started = False
    try:
        run([
            "docker", "run", "--detach", "--name", container,
            "--publish", "127.0.0.1::80/tcp",
            "--mount", f"type=bind,src={host_root},dst={MOUNT}",
            IMAGE,
        ], capture=True)
        started = True
        port = container_port(container)
        install_previous(port)
        private = private_root(host_root)
        private_container = f"{MOUNT}/{private.relative_to(host_root).as_posix()}"
        slug = "rollback-sentinel" if rollback else "upgrade-sentinel"
        seed_content(container, private_container, slug)
        old_receipt = json.loads(container_php(
            container,
            "echo file_get_contents($argv[1].'/install-receipt.json');",
            private_container,
        ))
        old_database_sha256 = container_php(
            container,
            "echo hash_file('sha256',$argv[1].'/var/cms.sqlite');",
            private_container,
        ).strip()

        conflict = document_root / "directory" / slug / "index.php"
        if rollback:
            container_php(
                container,
                "$path=$argv[1].'/directory/'.$argv[2].'/index.php';"
                "if(!is_dir(dirname($path))&&!mkdir(dirname($path),0755,true)){exit(2);}"
                "if(file_put_contents($path,\"<?php echo 'user-owned';\\n\")===false){exit(3);}"
                "chmod($path,0666);",
                f"{MOUNT}/{DOCUMENT_ROOT}",
                slug,
            )

        shutil.copyfile(current_installer, document_root / "install.php")
        os.chmod(document_root / "install.php", 0o666)
        headers, body = wait_installer(port)
        form_token, cookie = form_state(headers, body)
        if "已经找到现有网站" not in body:
            raise UpgradeTestError("当前安装器没有识别上一公开版")
        status, _, result = request_http(
            port,
            "/install.php",
            {"form_token": form_token},
            cookie,
        )
        receipt = json.loads(container_php(
            container,
            "echo file_get_contents($argv[1].'/install-receipt.json');",
            private_container,
        ))
        public_status, _, public_body = request_http(port, f"/?page=directory/{slug}")
        if rollback:
            if status != 400 or "为避免覆盖现有网站" not in result:
                raise UpgradeTestError("人为制造的升级故障没有被安全拒绝")
            if receipt != old_receipt:
                raise UpgradeTestError("升级失败后安装回执没有恢复")
            current_database_sha256 = container_php(
                container,
                "echo hash_file('sha256',$argv[1].'/var/cms.sqlite');",
                private_container,
            ).strip()
            if current_database_sha256 != old_database_sha256:
                raise UpgradeTestError("升级失败后数据库没有恢复")
            if conflict.read_text("utf-8") != "<?php echo 'user-owned';\n":
                raise UpgradeTestError("升级失败覆盖了用户文件")
            if public_status != 200 or "Upgrade Sentinel" not in public_body:
                raise UpgradeTestError("升级失败回滚后原网站不可用")
            return {"case": "rollback", "from": PREVIOUS_VERSION, "preserved": True}

        current_version = json.loads((ROOT / "cms-manifest.json").read_text("utf-8"))["version"]
        if status != 200 or "网站入口已经修复" not in result:
            raise UpgradeTestError(f"跨版本升级失败：HTTP {status} {result[:500]}")
        if receipt.get("version") != current_version or receipt.get("upgraded_at") is None:
            raise UpgradeTestError("升级后的安装回执不正确")
        if public_status != 200 or "Upgrade Sentinel" not in public_body:
            raise UpgradeTestError("升级后已发布内容没有保留")
        login_is_preserved(port)
        return {"case": "upgrade", "from": PREVIOUS_VERSION, "to": current_version, "preserved": True}
    except Exception:
        if started:
            logs = subprocess.run(
                ["docker", "logs", container],
                check=False,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
            ).stdout
            print(logs[-12000:], file=sys.stderr)
        raise
    finally:
        if started:
            subprocess.run(
                ["docker", "rm", "--force", container],
                check=False,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            subprocess.run([
                "docker", "run", "--rm", "--entrypoint", "/bin/chmod",
                "--mount", f"type=bind,src={host_root},dst={MOUNT}",
                IMAGE, "-R", "a+rwX", MOUNT,
            ], check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        temporary.cleanup()


def main() -> int:
    parser = argparse.ArgumentParser(description="快智 CMS 上一公开版真实升级与回滚测试")
    parser.add_argument("--previous-source", type=Path)
    parser.add_argument("--skip-build-image", action="store_true")
    args = parser.parse_args()
    if shutil.which("docker") is None:
        raise UpgradeTestError("当前环境没有 Docker")
    if not args.skip_build_image:
        run([
            "docker", "build", "--pull", "--file", str(DOCKERFILE),
            "--tag", IMAGE, str(DOCKERFILE.parent),
        ])
    with tempfile.TemporaryDirectory(prefix="kuaiz-cms-release-build-") as value:
        build_root = Path(value)
        archive = build_root / "previous.zip"
        fetch_previous(archive, args.previous_source)
        previous_source = extract_previous(archive, build_root / "source")
        private_key = build_root / "release-private.pem"
        public_key = build_root / "release-public.pem"
        run([
            sys.executable,
            "scripts/php_cms_release_control.py",
            "keygen",
            "--private",
            str(private_key),
            "--public",
            str(public_key),
        ])
        previous_installer = build_root / "previous-install.php"
        current_installer = build_root / "current-install.php"
        build_installer(previous_source, previous_installer, private_key, public_key)
        build_installer(ROOT, current_installer, private_key, public_key)
        results = [
            run_case(previous_installer, current_installer, rollback=False),
            run_case(previous_installer, current_installer, rollback=True),
        ]
    print(json.dumps({"ok": True, "results": results}, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
