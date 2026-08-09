import base64
import hashlib
import hmac
import importlib.util
import json
import os
import re
import shutil
import subprocess
import time
from pathlib import Path

import pytest

import php_cms_release


ROOT = Path(__file__).resolve().parents[1]
BUILDER_PATH = ROOT / "scripts" / "build_php_cms_installer.py"
TEMPLATE = ROOT / "installer-template.php"


def _builder_module():
    spec = importlib.util.spec_from_file_location("build_php_cms_installer", BUILDER_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _php_with_cms_extensions() -> str | None:
    php = shutil.which(os.environ.get("KUAIZ_PHP_BINARY", "php"))
    if not php:
        return None
    probe = subprocess.run(
        [
            php,
            "-r",
            "echo in_array('sqlite', PDO::getAvailableDrivers(), true)"
            " && extension_loaded('fileinfo') && extension_loaded('gd')"
            " && function_exists('imagewebp') ? 'yes' : 'no';",
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return php if probe.returncode == 0 and probe.stdout == "yes" else None


def _release_credentials(builder):
    private_key, public_key = php_cms_release.generate_key_pair()
    envelope = builder.release_envelope(issued_at=1785900000)
    release_token = php_cms_release.sign_envelope(envelope, private_key)
    return envelope, release_token, public_key


def _build(builder, output: Path, install_token: str):
    envelope, release_token, public_key = _release_credentials(builder)
    result = builder.build_installer(
        output, install_token, release_token, public_key)
    return result, envelope, public_key


def _form_token(install_token: str) -> str:
    expires = int(time.time()) + 1200
    body = f"{expires}.{hashlib.sha256(os.urandom(32)).hexdigest()}"
    key = hashlib.sha256(install_token.encode()).hexdigest().encode()
    signature = hmac.new(key, body.encode(), hashlib.sha256).hexdigest()
    return f"{body}.{signature}"


def test_single_file_installer_is_closed_and_reproducible(tmp_path):
    builder = _builder_module()
    token = "installer-test-token-0123456789-abcdef"
    first = tmp_path / "first.php"
    second = tmp_path / "second.php"
    envelope, release_token, public_key = _release_credentials(builder)
    first_result = builder.build_installer(first, token, release_token, public_key)
    second_result = builder.build_installer(second, token, release_token, public_key)

    assert first.read_bytes() == second.read_bytes()
    assert first_result["sha256"] == second_result["sha256"]
    assert first_result["file_count"] == len(builder.SOURCE_FILES)
    assert first_result["byte_size"] < 2 * 1024 * 1024
    assert first_result["release_issued_at"] == envelope["issued_at"]
    assert first_result["release_public_key_fingerprint"] == (
        php_cms_release.public_key_fingerprint(public_key))

    source = first.read_text("utf-8")
    assert token not in source
    assert hashlib.sha256(token.encode()).hexdigest() in source
    assert "__KUAIZ_" not in source
    assert "community-php-sqlite-v1" in source
    assert "sodium_crypto_sign_verify_detached" in source
    assert "release_verifier_unavailable" not in source
    assert "installer_template_modified" in source
    assert 'name="site_language"' in source
    assert 'name="site_description"' in source
    assert 'name="form_token"' in source
    assert 'name="install_token"' not in source
    assert 'id="generate-password"' in source
    assert 'id="copy-password"' in source
    assert 'id="site-description-count"' in source
    assert "建议写 50–200 字" in source
    assert "已填写 0 / 500 字" in source
    assert 'minlength="12"' in source
    assert "crypto.getRandomValues" in source
    assert "Math.random" not in source
    assert "默认禁止搜索引擎收录" in source
    assert "existing_install_mismatch" in source
    assert "public_target_conflict" in source
    assert "@unlink(__FILE__)" in source
    assert "curl_exec" not in source
    assert "shell_exec" not in source
    assert "proc_open" not in source
    assert "eval(" not in source

    encoded_match = re.search(
        r"const KUAIZ_CMS_PAYLOAD_BASE64 = '([A-Za-z0-9+/=]+)';", source)
    digest_match = re.search(
        r"const KUAIZ_CMS_PAYLOAD_SHA256 = '([a-f0-9]{64})';", source)
    assert encoded_match and digest_match
    payload_bytes = base64.b64decode(encoded_match.group(1), validate=True)
    assert hashlib.sha256(payload_bytes).hexdigest() == digest_match.group(1)
    payload = json.loads(payload_bytes)
    assert payload["schema"] == "kuaiz-cms-embedded-release/v1"
    assert payload["runtime_profile"] == "community-php-sqlite-v1"
    assert payload["totals"]["file_count"] == len(builder.SOURCE_FILES)
    assert [item["path"] for item in payload["files"]] == list(builder.SOURCE_FILES)
    assert "installer-template.php" not in {item["path"] for item in payload["files"]}
    for item in payload["files"]:
        body = base64.b64decode(item["body_base64"], validate=True)
        assert len(body) == item["byte_size"]
        assert hashlib.sha256(body).hexdigest() == item["sha256"]


def test_installer_builder_rejects_short_install_token(tmp_path):
    builder = _builder_module()
    _, release_token, public_key = _release_credentials(builder)
    with pytest.raises(builder.InstallerBuildError, match="安装表单保护密钥长度不安全"):
        builder.build_installer(
            tmp_path / "installer.php", "too-short", release_token, public_key)


def test_installer_builder_rejects_a_signature_from_another_key(tmp_path):
    builder = _builder_module()
    _, release_token, _ = _release_credentials(builder)
    _, other_public_key = php_cms_release.generate_key_pair()

    with pytest.raises(builder.InstallerBuildError, match="发行签名验证失败"):
        builder.build_installer(
            tmp_path / "installer.php",
            "installer-test-token-0123456789-abcdef",
            release_token,
            other_public_key,
        )


def test_installer_builder_rejects_a_stale_signature_after_template_change(tmp_path):
    builder = _builder_module()
    _, release_token, public_key = _release_credentials(builder)
    changed_template = tmp_path / "changed-installer-template.php"
    changed_template.write_bytes(TEMPLATE.read_bytes() + b"\n")
    builder.TEMPLATE = changed_template

    with pytest.raises(builder.InstallerBuildError, match="当前模板或源码不一致"):
        builder.build_installer(
            tmp_path / "installer.php",
            "installer-test-token-0123456789-abcdef",
            release_token,
            public_key,
        )


def test_cms_release_signature_rejects_changed_content_and_boolean_counts():
    private_key, public_key = php_cms_release.generate_key_pair()
    envelope = php_cms_release.create_envelope(
        version="0.1.0-dev",
        database_schema_version=5,
        template_sha256="a" * 64,
        payload_sha256="b" * 64,
        file_count=26,
        content_bytes=1024,
        issued_at=1785900000,
    )
    token = php_cms_release.sign_envelope(envelope, private_key)
    assert php_cms_release.verify_token(token, public_key) == envelope

    prefix, payload, signature = token.split(".")
    changed_signature = ("A" if signature[0] != "A" else "B") + signature[1:]
    with pytest.raises(php_cms_release.CmsReleaseError, match="验证失败"):
        php_cms_release.verify_token(
            f"{prefix}.{payload}.{changed_signature}", public_key)

    invalid = dict(envelope)
    invalid["file_count"] = True
    with pytest.raises(php_cms_release.CmsReleaseError, match="摘要值不正确"):
        php_cms_release.validate_envelope(invalid)


def test_installer_template_is_not_a_runnable_release():
    source = TEMPLATE.read_text("utf-8")
    assert source.count("__KUAIZ_INSTALL_TOKEN_SHA256__") == 1
    assert source.count("__KUAIZ_PAYLOAD_JSON_BASE64__") == 1
    assert source.count("__KUAIZ_PAYLOAD_SHA256__") == 1
    assert source.count("__KUAIZ_RELEASE_VERSION__") == 1
    assert source.count("__KUAIZ_RELEASE_SIGNATURE_TOKEN__") == 1
    assert source.count("__KUAIZ_RELEASE_PUBLIC_KEY_BASE64__") == 1
    assert source.count("__KUAIZ_RELEASE_PUBLIC_KEY_FINGERPRINT__") == 1
    assert "请把安装文件直接放到空网站的根目录" in source
    assert "已有网站文件不会被覆盖" in source
    assert "private_parent_unwritable" not in source
    assert "protected-document-root" in source
    assert "无法保护 CMS 私有目录" in source
    assert "KuaizCmsExtensionRegistry::installDeclarative" in source
    assert "KuaizCmsThemeRegistry::install" in source
    assert "KuaizCmsAuth::ensureInitialAdmin" in source
    assert "自动生成 16 位" in source
    assert "管理员密码至少 12 位" in source


@pytest.mark.skipif(
    _php_with_cms_extensions() is None,
    reason="PHP CLI with PDO_SQLite, Fileinfo and GD WebP is unavailable",
)
def test_generated_installer_bootstraps_a_real_private_php_cms(tmp_path):
    php = _php_with_cms_extensions()
    assert php
    builder = _builder_module()
    token = "real-installer-token-0123456789-abcdef"
    claim = _form_token(token)
    document_root = tmp_path / "public_html"
    document_root.mkdir()
    installer = document_root / "install.php"
    result, envelope, public_key = _build(builder, installer, token)

    invalid = subprocess.run(
        [
            php,
            "-r",
            "$_SERVER=['REQUEST_METHOD'=>'POST','HTTPS'=>'on','HTTP_HOST'=>'cms-install.test',"
            "'DOCUMENT_ROOT'=>$argv[1],"
            "'CONTENT_LENGTH'=>'1024'];"
            "$_COOKIE=['kuaiz_cms_install_claim'=>'wrong-token'];"
            "$_POST=['form_token'=>'wrong-token','email'=>'owner@example.com',"
            "'display_name'=>'站点管理员','password'=>'Correct horse battery staple!',"
            "'password_confirmation'=>'Correct horse battery staple!'];require $argv[2];",
            str(document_root),
            str(installer),
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert invalid.returncode == 0
    assert "安装页面已经失效" in invalid.stdout
    assert installer.exists()
    assert not list(tmp_path.glob(".kuaiz-cms-*"))

    existing_index = document_root / "index.php"
    existing_index.write_text("existing-site", encoding="utf-8")
    conflict = subprocess.run(
        [
            php,
            "-r",
            "$_SERVER=['REQUEST_METHOD'=>'POST','HTTPS'=>'on','HTTP_HOST'=>'cms-install.test',"
            "'DOCUMENT_ROOT'=>$argv[1],"
            "'CONTENT_LENGTH'=>'1024'];"
            "$_COOKIE=['kuaiz_cms_install_claim'=>$argv[2]];"
            "$_POST=['form_token'=>$argv[2],'email'=>'owner@example.com',"
            "'display_name'=>'站点管理员','password'=>'Ab3!defghijk',"
            "'password_confirmation'=>'Ab3!defghijk',"
            "'site_name'=>'真实安装测试站','site_description'=>'用于验证 CMS 真实安装流程的业务介绍。',"
            "'site_language'=>'zh-CN'];require $argv[3];",
            str(document_root),
            claim,
            str(installer),
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert conflict.returncode == 0
    assert "为避免覆盖现有网站" in conflict.stdout
    assert existing_index.read_text("utf-8") == "existing-site"
    assert not list(tmp_path.glob(".kuaiz-cms-*"))
    # Use a fresh document root for the successful attempt. On macOS Docker
    # bind mounts can briefly retain a deleted file's directory entry, which
    # would make this test exercise the mount cache rather than the installer.
    document_root = tmp_path / "fresh_public_html"
    document_root.mkdir()
    fresh_installer = document_root / "install.php"
    shutil.copyfile(installer, fresh_installer)
    installer = fresh_installer

    install = subprocess.run(
        [
            php,
            "-r",
            "$_SERVER=['REQUEST_METHOD'=>'POST','HTTPS'=>'on','HTTP_HOST'=>'cms-install.test',"
            "'DOCUMENT_ROOT'=>$argv[1],"
            "'CONTENT_LENGTH'=>'1024'];"
            "$_COOKIE=['kuaiz_cms_install_claim'=>$argv[2]];"
            "$_POST=['form_token'=>$argv[2],'email'=>'owner@example.com',"
            "'display_name'=>'站点管理员','password'=>'Correct horse battery staple!',"
            "'password_confirmation'=>'Correct horse battery staple!',"
            "'site_name'=>'真实安装测试站','site_description'=>'用于验证 CMS 真实安装流程的业务介绍。',"
            "'site_language'=>'zh-CN'];"
            "require $argv[3];",
            str(document_root),
            claim,
            str(installer),
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert install.returncode == 0, install.stderr
    assert "网站已经装好" in install.stdout
    assert not installer.exists()
    assert (document_root / "index.php").is_file()
    assert (document_root / ".htaccess").is_file()
    assert not (document_root / ".user.ini").exists()
    for route in (
        "admin", "admin/setup", "admin/login", "admin/logout",
        "admin/content/new", "admin/content/edit", "admin/content/history",
        "admin/content/save", "admin/content/state", "admin/media",
        "admin/media/file", "admin/media/upload", "admin/media/update",
        "admin/media/state", "admin/settings", "admin/themes",
        "admin/themes/activate",
    ):
        assert (document_root / route / "index.php").is_file()
    assert (document_root / "directory" / "index.php").is_file()
    assert (document_root / "robots.txt" / "index.php").is_file()
    assert (document_root / "sitemap.xml" / "index.php").is_file()

    private_roots = list(tmp_path.glob(".kuaiz-cms-*"))
    assert len(private_roots) == 1
    private_root = private_roots[0]
    receipt = json.loads(
        (private_root / "install-receipt.json").read_text("utf-8"))
    assert receipt["release_issued_at"] == envelope["issued_at"]
    assert receipt["release_public_key_fingerprint"] == (
        php_cms_release.public_key_fingerprint(public_key))
    assert receipt["payload_sha256"] == result["payload_sha256"]
    assert receipt["site_language"] == "zh-CN"
    assert (private_root / "var" / "cms.sqlite").is_file()
    state = subprocess.run(
        [
            php,
            "-r",
            "$pdo=new PDO('sqlite:'.$argv[1]);"
            "echo json_encode(['schema'=>(int)$pdo->query('PRAGMA user_version')->fetchColumn(),"
            "'users'=>(int)$pdo->query('SELECT COUNT(*) FROM cms_users')->fetchColumn(),"
            "'extensions'=>(int)$pdo->query('SELECT COUNT(*) FROM cms_extensions')->fetchColumn(),"
            "'active_themes'=>(int)$pdo->query(\"SELECT COUNT(*) FROM cms_themes WHERE status='active'\")->fetchColumn()]);",
            str(private_root / "var" / "cms.sqlite"),
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert state.returncode == 0, state.stderr
    assert json.loads(state.stdout) == {
        "schema": 5,
        "users": 1,
        "extensions": 1,
        "active_themes": 1,
    }

    admin = subprocess.run(
        [
            php,
            "-r",
            "$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin','HTTPS'=>'on',"
            "'HTTP_HOST'=>'cms-install.test'];"
            "$_GET=[];$_POST=[];$_COOKIE=[];$_FILES=[];require $argv[1];",
            str(document_root / "index.php"),
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert admin.returncode == 0, admin.stderr
    assert "登录网站后台" in admin.stdout


@pytest.mark.skipif(
    _php_with_cms_extensions() is None,
    reason="PHP CLI with PDO_SQLite, Fileinfo and GD WebP is unavailable",
)
def test_installer_shows_form_without_sodium_verifier(tmp_path):
    php = _php_with_cms_extensions()
    assert php
    builder = _builder_module()
    document_root = tmp_path / "public_html"
    document_root.mkdir()
    installer = document_root / "install.php"
    _build(
        builder,
        installer,
        "no-sodium-installer-token-0123456789-abcdef",
    )

    result = subprocess.run(
        [
            php,
            "-d",
            "disable_functions=sodium_crypto_sign_verify_detached",
            "-r",
            "$_SERVER=['REQUEST_METHOD'=>'GET','HTTPS'=>'on',"
            "'HTTP_HOST'=>'cms-install.test','DOCUMENT_ROOT'=>$argv[1]];"
            "require $argv[2];",
            str(document_root),
            str(installer),
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )

    assert result.returncode == 0, result.stderr
    assert "安装文件已经检查通过" in result.stdout
    assert '<form method="post"' in result.stdout
    assert "Sodium" not in result.stdout


@pytest.mark.skipif(
    _php_with_cms_extensions() is None,
    reason="PHP CLI with PDO_SQLite, Fileinfo and GD WebP is unavailable",
)
def test_installer_refuses_modified_logic_before_showing_admin_form(tmp_path):
    php = _php_with_cms_extensions()
    assert php
    builder = _builder_module()
    document_root = tmp_path / "public_html"
    document_root.mkdir()
    installer = document_root / "install.php"
    _build(
        builder,
        installer,
        "tamper-test-installer-token-0123456789-abcdef",
    )
    source = installer.read_text("utf-8")
    assert source.count("开始安装快智 CMS") == 1
    installer.write_text(
        source.replace("开始安装快智 CMS", "开始安装你的快智 CMS", 1),
        encoding="utf-8",
        newline="\n",
    )

    result = subprocess.run(
        [
            php,
            "-d",
            "disable_functions=sodium_crypto_sign_verify_detached",
            "-r",
            "$_SERVER=['REQUEST_METHOD'=>'GET','HTTPS'=>'on','DOCUMENT_ROOT'=>$argv[1]];"
            "require $argv[2];",
            str(document_root),
            str(installer),
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )

    assert result.returncode == 0, result.stderr
    assert "安装器逻辑已被修改" in result.stdout
    assert '<form method="post"' not in result.stdout
