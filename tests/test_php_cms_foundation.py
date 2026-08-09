import json
import os
import re
import shutil
import subprocess
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[1]
CMS = ROOT
REFERENCE = CMS / "extensions" / "kuaiz-directory" / "extension.json"
THEME_REFERENCE = CMS / "themes" / "kuaiz-studio" / "theme.json"
DEFAULT_THEME = CMS / "themes" / "kuaiz-default" / "theme.json"


def test_community_distribution_is_an_independent_project():
    manifest = json.loads((CMS / "cms-manifest.json").read_text("utf-8"))

    assert manifest == {
        "schema": "kuaiz-cms-distribution/v1",
        "name": "Kuaiz CMS Community",
        "version": "0.1.9-dev",
        "runtime_profile": "community-php-sqlite-v1",
        "database": "sqlite",
        "database_schema_version": 5,
        "license": "Apache-2.0",
        "source_distribution": "public-github",
        "minimum_php": "7.4",
        "required_php_extensions": ["pdo_sqlite", "fileinfo", "gd"],
        "optional_php_extensions": ["exif", "sodium"],
        "license_required": False,
        "independent_editing": True,
        "independent_publishing": True,
        "public_site_requires_vendor": False,
        "ai_operations": "optional-outbound-connector",
        "theme_protocol": "kuaiz-theme/v2",
        "extension_protocol": "kuaiz-cms-extension/v1",
        "extension_execution": ["declarative", "official-signed-php"],
        "distribution_status": "developer-preview",
    }
    for engine_only_path in (
        "main.py",
        "site_export.py",
        "php_runtime",
        "installer/kuaiz-install.php",
    ):
        assert not (ROOT / engine_only_path).exists()
    assert (CMS / "LICENSE").read_text("utf-8").startswith(
        "                                 Apache License")
    assert "Kuaiz CMS Community" in (CMS / "NOTICE").read_text("utf-8")


def test_extension_contract_is_closed_and_reference_is_declarative():
    schema = json.loads(
        (CMS / "contracts" / "extension-manifest.schema.json").read_text("utf-8"))
    extension = json.loads(REFERENCE.read_text("utf-8"))

    assert schema["$id"] == (
        "https://cms.kuaiz.net/contracts/extension-manifest-v1.json")
    assert schema["additionalProperties"] is False
    assert set(extension) == set(schema["required"])
    assert extension["schema"] == schema["properties"]["schema"]["const"]
    assert re.fullmatch(schema["properties"]["id"]["pattern"], extension["id"])
    assert re.fullmatch(
        schema["properties"]["version"]["pattern"], extension["version"])
    assert extension["type"] == "content"
    assert extension["execution"] == "declarative"
    assert extension["entrypoint"] is None
    assert extension["migrations"] == []
    assert extension["events"]["subscribes"] == []
    assert extension["network"]["outbound_hosts"] == []
    assert extension["content_types"]
    assert all(route["methods"] == ["GET"] for route in extension["routes"])
    assert all(slot.startswith(extension["id"] + ".")
               for slot in extension["theme_slots"])
    assert all(event.startswith(extension["id"] + ".")
               for event in extension["events"]["publishes"])
    assert not list(REFERENCE.parent.glob("*.php"))

    content_type = extension["content_types"][0]
    assert content_type["id"] == "listing"
    assert {field["key"] for field in content_type["fields"]} == {
        "title", "summary", "cover", "phone", "website", "sort_order"}
    assert len({field["key"] for field in content_type["fields"]}) == len(
        content_type["fields"])


def test_theme_v2_contract_is_ai_friendly_closed_and_global():
    schema = json.loads(
        (CMS / "contracts" / "theme-manifest.schema.json").read_text("utf-8"))
    theme = json.loads(THEME_REFERENCE.read_text("utf-8"))

    assert schema["$id"] == "https://cms.kuaiz.net/contracts/theme-manifest-v2.json"
    assert schema["additionalProperties"] is False
    assert set(theme) == set(schema["required"])
    assert theme["schema"] == "kuaiz-theme/v2"
    assert re.fullmatch(schema["properties"]["id"]["pattern"], theme["id"])
    assert theme["compatibility"]["site_language_mode"] == "single"
    assert set(theme["compatibility"]["directions"]) == {"ltr", "rtl"}
    assert {"short", "long", "complex", "empty", "rtl"}.issubset(
        theme["preview"]["required_seeds"])
    assert {"mobile", "desktop"}.issubset(theme["preview"]["viewports"])
    assert set(theme["templates"]) == {
        "home", "content_list", "content_detail", "not_found"}

    section_schema = schema["$defs"]["section"]
    allowed_components = set(section_schema["properties"]["component"]["enum"])
    binding_pattern = re.compile(
        section_schema["properties"]["bindings"]["additionalProperties"]["pattern"])
    forbidden_keys = {
        "html", "css", "javascript", "script", "php", "entrypoint",
        "template_file", "execute", "eval",
    }
    for sections in theme["templates"].values():
        assert sections
        assert len({section["id"] for section in sections}) == len(sections)
        for section in sections:
            assert set(section) == set(section_schema["required"])
            assert section["component"] in allowed_components
            assert all(binding_pattern.fullmatch(binding)
                       for binding in section["bindings"].values())
            assert not (set(section) | set(section["options"])) & forbidden_keys
            assert all(not isinstance(value, (dict, list))
                       for value in section["options"].values())

    def luminance(color: str) -> float:
        channels = [int(color[index:index + 2], 16) / 255
                    for index in (1, 3, 5)]
        linear = [channel / 12.92 if channel <= .04045
                  else ((channel + .055) / 1.055) ** 2.4
                  for channel in channels]
        return .2126 * linear[0] + .7152 * linear[1] + .0722 * linear[2]

    colors = theme["design"]["colors"]
    for foreground, background in (
        ("text", "background"), ("text", "surface"),
        ("muted", "background"), ("muted", "surface"),
        ("accent", "background"), ("accent", "surface"),
        ("primary_text", "primary"),
    ):
        high, low = sorted(
            (luminance(colors[foreground]), luminance(colors[background])),
            reverse=True,
        )
        assert (high + .05) / (low + .05) >= 4.5

    assert theme["assets"] == []
    assert not list(THEME_REFERENCE.parent.glob("*.php"))


def test_default_business_theme_is_bundled_declarative_and_ready_to_select():
    theme = json.loads(DEFAULT_THEME.read_text("utf-8"))

    assert theme["schema"] == "kuaiz-theme/v2"
    assert theme["id"] == "kuaiz.default"
    assert theme["name"] == "清简商务"
    assert theme["compatibility"] == {
        "cms": ">=0.1.0 <1.0.0",
        "site_language_mode": "single",
        "directions": ["ltr", "rtl"],
    }
    assert set(theme["templates"]) == {
        "home", "content_list", "content_detail", "not_found"}
    assert theme["assets"] == []
    assert {"short", "long", "complex", "empty", "rtl"}.issubset(
        theme["preview"]["required_seeds"])
    assert not list(DEFAULT_THEME.parent.glob("*.php"))


def test_php_cms_core_has_versioned_content_extension_and_audit_storage():
    database = (CMS / "src" / "Database.php").read_text("utf-8")
    validator = (CMS / "src" / "ExtensionManifest.php").read_text("utf-8")
    registry = (CMS / "src" / "ExtensionRegistry.php").read_text("utf-8")
    content = (CMS / "src" / "ContentRepository.php").read_text("utf-8")
    auth = (CMS / "src" / "Auth.php").read_text("utf-8")
    media = (CMS / "src" / "MediaRepository.php").read_text("utf-8")
    admin_app = (CMS / "src" / "AdminApplication.php").read_text("utf-8")
    front_controller = (CMS / "public" / "index.php").read_text("utf-8")
    theme_validator = (CMS / "src" / "ThemeManifest.php").read_text("utf-8")
    theme_registry = (CMS / "src" / "ThemeRegistry.php").read_text("utf-8")
    site_settings = (CMS / "src" / "SiteSettings.php").read_text("utf-8")
    theme_renderer = (CMS / "src" / "ThemeRenderer.php").read_text("utf-8")
    public_app = (CMS / "src" / "PublicApplication.php").read_text("utf-8")
    maintenance = (CMS / "src" / "Maintenance.php").read_text("utf-8")
    backup = (CMS / "src" / "Backup.php").read_text("utf-8")

    assert "SCHEMA_VERSION = 5" in database
    for table in (
        "cms_users", "cms_sessions", "cms_login_attempts", "cms_extensions", "cms_content_types",
        "cms_entries", "cms_entry_revisions", "cms_media", "cms_routes",
        "cms_theme_slots", "cms_extension_migrations", "cms_publications",
        "cms_audit_logs", "cms_revision_media", "cms_themes", "cms_theme_assets",
        "cms_site_settings",
    ):
        assert f"CREATE TABLE IF NOT EXISTS {table}" in database
    assert "PRAGMA foreign_keys=ON" in database
    assert "PRAGMA integrity_check" in database
    assert "database_schema_newer_than_core" in database
    assert "VACUUM INTO" in database
    assert "cms_database_migration_failed_restored" in database
    assert "cms_database_migration_rollback_failed" in database
    assert "LOCK_EX | LOCK_NB" in database
    assert "UNIQUE(extension_id,type_key)" in database
    assert "FOREIGN KEY(extension_id)" in database

    assert "extension_manifest_fields_invalid" in validator
    assert "declarative_extension_execution_forbidden" in validator
    assert "declarative_extension_code_forbidden" in validator
    assert "signed_extension_entrypoint_required" in validator
    assert "declarative_extension_route_write_forbidden" in validator
    assert "extension_personal_data_controls_required" in validator
    assert "extension_route_reserved" in validator
    assert "official-signed-php" in validator
    assert "installDeclarative" in registry
    assert "extension_executable_install_requires_signed_pipeline" in registry
    assert "extension_version_downgrade_forbidden" in registry
    assert "extension_version_content_changed" in registry
    assert "extension_content_type_removal_has_entries" in registry
    assert registry.index("DELETE FROM cms_routes") < registry.index(
        "self::syncContentTypes")
    assert "cms_audit_logs" in registry
    assert "class KuaizCmsContentRepository" in content
    assert "cms_content_required_field_missing" in content
    assert "cms_content_unknown_field" in content
    assert "cms_content_media_not_found" in content
    assert "payload_sha256" in content
    assert "published_revision_id" in content
    assert "public static function restore" in content
    assert "public static function published" in content
    assert "public static function adminEntries" in content
    assert "public static function history" in content
    assert "public static function unpublish" in content
    assert "public static function archive" in content
    assert "public static function restoreArchived" in content
    assert "class KuaizCmsAuth" in auth
    assert "MINIMUM_PASSWORD_BYTES = 12" in auth
    assert "password_hash(" in auth
    assert "password_verify(" in auth
    assert "hash('sha256', $token)" in auth
    assert "hash('sha256', $csrfToken)" in auth
    assert "cms_auth_rate_limited" in auth
    assert "cms_auth_last_admin_forbidden" in auth
    assert "cms_auth_self_lockout_forbidden" in auth
    assert "auth.password_changed" in auth
    assert "provisionSetupToken" in auth
    assert "cms_auth_setup_token_invalid" in auth
    assert "class KuaizCmsAdminApplication" in admin_app
    assert "Content-Security-Policy" in admin_app
    assert "SameSite=Strict" in admin_app
    assert "__Host-kuaiz_cms_session" in admin_app
    assert "__Host-kuaiz_cms_login_csrf" in admin_app
    assert "KuaizCmsAuth::authorizeMutation" in admin_app
    assert "KuaizCmsContentRepository::adminEntries" in admin_app
    assert "KuaizCmsMediaRepository::storeImage" in admin_app
    assert "KuaizCmsMediaRepository::items" in admin_app
    assert "class KuaizCmsMediaRepository" in media
    assert "imagewebp(" in media
    assert "MAX_PIXELS" in media
    assert "cms_media_in_use" in media
    assert "cms_revision_media" in content
    assert "AND status='active'" in content
    assert "class KuaizCmsThemeManifest" in theme_validator
    assert "theme_color_contrast_insufficient" in theme_validator
    assert "theme_preview_seed_required" in theme_validator
    assert "theme_preview_rtl_required" in theme_validator
    assert "theme_extension_slot_unexpected" in theme_validator
    assert "class KuaizCmsThemeRegistry" in theme_registry
    assert "theme_version_content_changed" in theme_registry
    assert "theme_version_downgrade_forbidden" in theme_registry
    assert "theme_extension_slot_unavailable" in theme_registry
    assert "theme_asset_storage_corrupt" in theme_registry
    assert "class KuaizCmsSiteSettings" in site_settings
    assert "site_language_mode" not in site_settings
    assert "search_indexing" in site_settings
    assert "cms_site_base_url_invalid" in site_settings
    assert "class KuaizCmsThemeRenderer" in theme_renderer
    assert "application/ld+json" in theme_renderer
    assert "rel=\"canonical\"" in theme_renderer
    assert "X-Robots-Tag" in theme_renderer
    assert "class KuaizCmsPublicApplication" in public_app
    assert "/robots.txt" in public_app
    assert "/sitemap.xml" in public_app
    assert "Disallow: /" in public_app
    assert "class KuaizCmsMaintenance" in maintenance
    assert "LOCK_SH | LOCK_NB" in maintenance
    assert "LOCK_EX | LOCK_NB" in maintenance
    assert "class KuaizCmsBackup" in backup
    assert "VACUUM INTO" in backup
    assert "cms_backup_file_corrupt" in backup
    assert "cms_restore_rollback_failed" in backup
    assert "pre-restore" in backup
    assert "KuaizCmsPublicApplication::handle" in front_controller
    assert "KuaizCmsMaintenance::shared" in front_controller
    assert "KUAIZ_CMS_DATA_DIR" in front_controller
    assert "$name !== 'Set-Cookie'" in front_controller
    assert "csrf_token_hash TEXT NOT NULL" in database
    assert "csrf_token_hash" in auth
    assert "INSERT INTO cms_sessions(" in auth

    forbidden = re.compile(
        r"\b(?:eval|shell_exec|system|passthru|proc_open|popen)\s*\(")
    for source in (
            database, validator, registry, content, auth, media, admin_app,
            theme_validator, theme_registry, site_settings, theme_renderer,
            public_app, maintenance, backup):
        assert not forbidden.search(source)
        assert not re.search(r"(?<!->)(?<!::)\bexec\s*\(", source)
    assert not re.search(r"\b(?:include|require)(?:_once)?\b", registry)


def _php_with_sqlite() -> str | None:
    php = shutil.which(os.environ.get("KUAIZ_PHP_BINARY", "php"))
    if not php:
        return None
    completed = subprocess.run(
        [php, "-r", "echo in_array('sqlite', PDO::getAvailableDrivers(), true) ? 'yes' : 'no';"],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return php if completed.returncode == 0 and completed.stdout == "yes" else None


@pytest.mark.skipif(_php_with_sqlite() is None, reason="PHP CLI with PDO_SQLite is unavailable")
def test_php_cms_declarative_extension_installs_and_is_immutable(tmp_path):
    php = _php_with_sqlite()
    assert php
    runner = tmp_path / "foundation-smoke.php"
    runner.write_text(
        """<?php
declare(strict_types=1);
require $argv[1];
require $argv[2];
require $argv[3];
require $argv[4];
require $argv[5];
require $argv[6];
require $argv[7];
require $argv[8];
require $argv[9];
require $argv[10];
require $argv[11];
require $argv[12];
$pdo = KuaizCmsDatabase::connect($argv[15]);
$manifest = file_get_contents($argv[13]);
$themeManifest = file_get_contents($argv[14]);
$storageRoot = dirname($argv[15]);
$setupToken = KuaizCmsAuth::provisionSetupToken($pdo);
$setupPage = KuaizCmsAdminApplication::handle(
    $pdo,
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin'],
    [],
    [],
    []
);
$setupResponse = KuaizCmsAdminApplication::handle(
    $pdo,
    [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/admin/setup',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'foundation-smoke',
    ],
    [],
    [
        'setup_token' => $setupToken,
        'username' => 'owner@example.com',
        'display_name' => '站点管理员',
        'password' => 'Correct horse battery staple!',
        'password_confirmation' => 'Correct horse battery staple!',
    ],
    []
);
$cookies = [];
foreach ($setupResponse['headers']['Set-Cookie'] as $cookieHeader) {
    $pair = explode(';', $cookieHeader, 2)[0];
    [$cookieName, $cookieValue] = explode('=', $pair, 2);
    $cookies[$cookieName] = $cookieValue;
}
$onboardingPage = KuaizCmsAdminApplication::handle(
    $pdo,
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/settings?welcome=1'],
    ['welcome' => '1'],
    [],
    $cookies,
    [],
    $storageRoot
);
$sessionToken = $cookies['__Host-kuaiz_cms_session'];
$csrfToken = $cookies['__Host-kuaiz_cms_csrf'];
$session = KuaizCmsAuth::session($pdo, $sessionToken);
$admin = $session['user'];
KuaizCmsAuth::verifyCsrf($session, $csrfToken);
$csrfInvalid = '';
try {
    KuaizCmsAuth::verifyCsrf($session, str_repeat('0', 64));
} catch (RuntimeException $error) {
    $csrfInvalid = $error->getMessage();
}
$editor = KuaizCmsAuth::createUser(
    $pdo,
    $session,
    'editor',
    '内容编辑',
    'Another long editor password!',
    'editor'
);
$first = KuaizCmsExtensionRegistry::installDeclarative(
    $pdo, $manifest, 'test:foundation', '0.1.0'
);
$second = KuaizCmsExtensionRegistry::installDeclarative(
    $pdo, $manifest, 'test:foundation', '0.1.0'
);
$themeInstall = KuaizCmsThemeRegistry::install(
    $pdo,
    $themeManifest,
    dirname($argv[14]),
    $storageRoot,
    'user:' . $admin['id'],
    '0.1.0',
    true
);
$canonicalTheme = KuaizCmsThemeManifest::canonicalJson(
    KuaizCmsThemeManifest::parse($themeManifest)
);
$activeTheme = KuaizCmsThemeRegistry::active($pdo);
$changedTheme = json_decode($themeManifest, true, 64, JSON_THROW_ON_ERROR);
$changedTheme['name'] = 'Changed without a version bump';
$themeImmutable = '';
try {
    KuaizCmsThemeRegistry::install(
        $pdo,
        json_encode($changedTheme, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        dirname($argv[14]),
        $storageRoot,
        'user:' . $admin['id'],
        '0.1.0'
    );
} catch (RuntimeException $error) {
    $themeImmutable = $error->getMessage();
}
$sourceImage = $storageRoot . '/source.png';
$canvas = imagecreatetruecolor(1200, 800);
$background = imagecolorallocate($canvas, 23, 97, 70);
imagefilledrectangle($canvas, 0, 0, 1199, 799, $background);
imagepng($canvas, $sourceImage);
imagedestroy($canvas);
$media = KuaizCmsMediaRepository::storeImage(
    $pdo,
    $storageRoot,
    $sourceImage,
    'office-source.png',
    '绿色的工作室测试图',
    '真实 PHP 媒体处理测试',
    'user:' . $admin['id']
);
$settingsResponse = KuaizCmsAdminApplication::handle(
    $pdo,
    [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/admin/settings',
        'REMOTE_ADDR' => '127.0.0.1',
    ],
    [],
    [
        '_csrf' => $csrfToken,
        'site_name' => '上海创意工作室',
        'tagline' => '把复杂业务讲清楚',
        'description' => '面向全球客户的专业创意与网站服务。',
        'language' => 'zh-Hans-CN',
        'direction' => 'ltr',
        'base_url' => 'https://studio.example.com',
        'contact_title' => '聊聊你的项目',
        'contact_summary' => '告诉我们你的目标，我们会准备清晰方案。',
        'cover_media_id' => (string)$media['id'],
    ],
    $cookies,
    [],
    $storageRoot
);
$themePage = KuaizCmsAdminApplication::handle(
    $pdo,
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/themes?welcome=1'],
    ['welcome' => '1'],
    [],
    $cookies,
    [],
    $storageRoot
);
$themeSelection = KuaizCmsAdminApplication::handle(
    $pdo,
    ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/themes/activate'],
    [],
    [
        '_csrf' => $csrfToken,
        'theme_id' => 'kuaiz.studio',
        'version' => '1.0.0',
    ],
    $cookies,
    [],
    $storageRoot
);
$siteSettings = KuaizCmsSiteSettings::get($pdo);
$entry = KuaizCmsContentRepository::save(
    $pdo,
    'kuaiz.directory',
    'listing',
    'shanghai-studio',
    [
        'title' => '上海工作室',
        'summary' => '用于验证声明式扩展内容。',
        'cover' => $media['id'],
        'phone' => '021-00000000',
        'website' => 'https://example.com/studio',
        'sort_order' => 10,
    ],
    'test:foundation',
    true
);
$published = KuaizCmsContentRepository::published(
    $pdo, 'kuaiz.directory', 'listing', 'shanghai-studio'
);
$draftUpdate = KuaizCmsContentRepository::save(
    $pdo,
    'kuaiz.directory',
    'listing',
    'shanghai-studio',
    [
        'title' => '上海工作室（新版草稿）',
        'summary' => '这次修改尚未公开。',
        'cover' => $media['id'],
        'phone' => '021-00000000',
        'website' => 'https://example.com/studio',
        'sort_order' => 20,
    ],
    'user:' . $editor['id'],
    false
);
$adminEntries = KuaizCmsContentRepository::adminEntries(
    $pdo, 'kuaiz.directory', 'listing', 'published'
);
$history = KuaizCmsContentRepository::history($pdo, $entry['entry_id']);
$publicHomeBlocked = KuaizCmsPublicApplication::handle(
    $pdo, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], $storageRoot
);
$publicList = KuaizCmsPublicApplication::handle(
    $pdo, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/directory'], $storageRoot
);
$publicDetail = KuaizCmsPublicApplication::handle(
    $pdo, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/directory/shanghai-studio'], $storageRoot
);
$publicQueryDetail = KuaizCmsPublicApplication::handle(
    $pdo,
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?page=directory/shanghai-studio'],
    $storageRoot,
    ['page' => 'directory/shanghai-studio']
);
$publicMissing = KuaizCmsPublicApplication::handle(
    $pdo, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/missing'], $storageRoot
);
$robotsBlocked = KuaizCmsPublicApplication::handle(
    $pdo, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/robots.txt'], $storageRoot
);
$siteSettings['search_indexing'] = true;
$siteSettings = KuaizCmsSiteSettings::save($pdo, [
    'site_name' => $siteSettings['site_name'],
    'tagline' => $siteSettings['tagline'],
    'description' => $siteSettings['description'],
    'language' => $siteSettings['language'],
    'direction' => $siteSettings['direction'],
    'base_url' => $siteSettings['base_url'],
    'search_indexing' => true,
    'contact_title' => $siteSettings['contact_title'],
    'contact_summary' => $siteSettings['contact_summary'],
    'cover_media_id' => $siteSettings['cover_media_id'],
], 'user:' . $admin['id']);
$robotsAllowed = KuaizCmsPublicApplication::handle(
    $pdo, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/robots.txt'], $storageRoot
);
$sitemap = KuaizCmsPublicApplication::handle(
    $pdo, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/sitemap.xml'], $storageRoot
);
$unpublished = KuaizCmsContentRepository::unpublish(
    $pdo, $entry['entry_id'], 'user:' . $admin['id']
);
$publicAfterUnpublish = KuaizCmsContentRepository::published(
    $pdo, 'kuaiz.directory', 'listing', 'shanghai-studio'
);
$republished = KuaizCmsContentRepository::publish(
    $pdo, $entry['entry_id'], 'user:' . $admin['id']
);
$archived = KuaizCmsContentRepository::archive(
    $pdo, $entry['entry_id'], 'user:' . $admin['id']
);
$restoredArchive = KuaizCmsContentRepository::restoreArchived(
    $pdo, $entry['entry_id'], 'user:' . $admin['id']
);
$dashboard = KuaizCmsAdminApplication::handle(
    $pdo,
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin'],
    [],
    [],
    $cookies
);
$historyPage = KuaizCmsAdminApplication::handle(
    $pdo,
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/content/history?id=' . $entry['entry_id']],
    ['id' => (string)$entry['entry_id']],
    [],
    $cookies
);
$mediaPage = KuaizCmsAdminApplication::handle(
    $pdo,
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/media'],
    [],
    [],
    $cookies,
    [],
    $storageRoot
);
$mediaFile = KuaizCmsAdminApplication::handle(
    $pdo,
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/media/file?id=' . $media['id'] . '&variant=thumb'],
    ['id' => (string)$media['id'], 'variant' => 'thumb'],
    [],
    $cookies,
    [],
    $storageRoot
);
$mediaInUse = '';
try {
    KuaizCmsMediaRepository::archive(
        $pdo, $media['id'], 'user:' . $admin['id']
    );
} catch (RuntimeException $error) {
    $mediaInUse = $error->getMessage();
}
$loginCsrfRejected = KuaizCmsAdminApplication::handle(
    $pdo,
    [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/admin/login',
        'REMOTE_ADDR' => '127.0.0.1',
    ],
    [],
    [
        'username' => 'owner@example.com',
        'password' => 'Correct horse battery staple!',
        '_login_csrf' => str_repeat('0', 64),
    ],
    []
);
$tampered = json_decode($manifest, true, 64, JSON_THROW_ON_ERROR);
$tampered['name'] = 'Changed without a version bump';
$immutable = '';
try {
    KuaizCmsExtensionRegistry::installDeclarative(
        $pdo,
        json_encode($tampered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'test:foundation',
        '0.1.0'
    );
} catch (RuntimeException $error) {
    $immutable = $error->getMessage();
}
$storedTokenIsHash = (int)$pdo->query(
    "SELECT COUNT(*) FROM cms_sessions WHERE token_hash='" . $sessionToken . "'"
)->fetchColumn() === 0;
KuaizCmsAuth::logout($pdo, $sessionToken);
$loggedOut = KuaizCmsAuth::session($pdo, $sessionToken) === null;
echo json_encode([
    'admin' => $admin,
    'editor' => $editor,
    'session_role' => $session['user']['role'],
    'csrf_invalid' => $csrfInvalid,
    'stored_token_is_hash' => $storedTokenIsHash,
    'logged_out' => $loggedOut,
    'setup_page_status' => $setupPage['status'],
    'setup_page_has_code' => str_contains($setupPage['body'], '一次性启用码'),
    'setup_page_uses_physical_route' => str_contains(
        $setupPage['body'], 'action="/admin/setup/"'
    ),
    'setup_status' => $setupResponse['status'],
    'setup_location' => $setupResponse['headers']['Location'] ?? '',
    'setup_cookie_count' => count($setupResponse['headers']['Set-Cookie']),
    'onboarding_status' => $onboardingPage['status'],
    'onboarding_has_next_step' => str_contains(
        $onboardingPage['body'], '先完成网站的基础设置'
    ) && str_contains($onboardingPage['body'], '保存并选择网站风格'),
    'settings_status' => $settingsResponse['status'],
    'settings_location' => $settingsResponse['headers']['Location'] ?? '',
    'theme_page_status' => $themePage['status'],
    'theme_page_has_choice' => str_contains($themePage['body'], '选择网站风格')
        && str_contains($themePage['body'], 'Studio'),
    'theme_selection_status' => $themeSelection['status'],
    'theme_selection_location' => $themeSelection['headers']['Location'] ?? '',
    'dashboard_status' => $dashboard['status'],
    'dashboard_has_draft' => str_contains($dashboard['body'], '上海工作室（新版草稿）'),
        'dashboard_uses_physical_routes' => str_contains(
            $dashboard['body'], 'href="/admin/content/new/?'
        ) && str_contains($dashboard['body'], 'action="/admin/logout/"'),
    'history_page_status' => $historyPage['status'],
    'history_has_csp' => isset($historyPage['headers']['Content-Security-Policy']),
    'login_csrf_rejected' => $loginCsrfRejected['status'],
    'media' => $media,
    'media_page_status' => $mediaPage['status'],
    'media_page_has_image' => str_contains($mediaPage['body'], '绿色的工作室测试图'),
    'media_file_status' => $mediaFile['status'],
    'media_file_type' => $mediaFile['headers']['Content-Type'],
    'media_in_use' => $mediaInUse,
    'media_links' => (int)$pdo->query('SELECT COUNT(*) FROM cms_revision_media')->fetchColumn(),
    'theme_install' => $themeInstall,
    'theme_empty_options_are_objects' => str_contains($canonicalTheme, '"options":{}')
        && !str_contains($canonicalTheme, '"options":[]'),
    'active_theme' => $activeTheme,
    'theme_immutable' => $themeImmutable,
    'site_settings' => $siteSettings,
    'public_home_status' => $publicHomeBlocked['status'],
    'public_home_has_site_name' => str_contains(
        $publicHomeBlocked['body'], '<h1>上海创意工作室</h1>'
    ),
    'public_home_hides_empty_featured' => !str_contains(
        $publicHomeBlocked['body'], '暂时还没有可展示的内容。'
    ),
    'public_home_noindex' => $publicHomeBlocked['headers']['X-Robots-Tag'] ?? '',
    'public_home_language' => str_contains($publicHomeBlocked['body'], 'lang="zh-Hans-CN"'),
    'public_home_canonical' => str_contains($publicHomeBlocked['body'], 'https://studio.example.com'),
    'public_home_uses_root_routes' => str_contains(
        $publicHomeBlocked['body'], '/?page=directory/shanghai-studio'
    ),
    'public_list_status' => $publicList['status'],
    'public_list_has_entry' => str_contains($publicList['body'], '上海工作室'),
    'public_detail_status' => $publicDetail['status'],
    'public_query_detail_status' => $publicQueryDetail['status'],
    'public_query_detail_canonical' => str_contains(
        $publicQueryDetail['body'],
        'https://studio.example.com/?page=directory/shanghai-studio'
    ),
    'public_detail_has_jsonld' => str_contains($publicDetail['body'], 'application/ld+json'),
    'public_missing_status' => $publicMissing['status'],
    'robots_blocked' => $robotsBlocked['body'],
    'robots_allowed' => $robotsAllowed['body'],
    'sitemap_has_detail' => str_contains(
        $sitemap['body'],
        'https://studio.example.com/?page=directory/shanghai-studio'
    ),
    'first' => $first,
    'second' => $second,
    'extensions' => (int)$pdo->query('SELECT COUNT(*) FROM cms_extensions')->fetchColumn(),
    'content_types' => (int)$pdo->query('SELECT COUNT(*) FROM cms_content_types')->fetchColumn(),
    'routes' => (int)$pdo->query('SELECT COUNT(*) FROM cms_routes')->fetchColumn(),
    'slots' => (int)$pdo->query('SELECT COUNT(*) FROM cms_theme_slots')->fetchColumn(),
    'audits' => (int)$pdo->query('SELECT COUNT(*) FROM cms_audit_logs')->fetchColumn(),
    'schema' => (int)$pdo->query('PRAGMA user_version')->fetchColumn(),
    'immutable' => $immutable,
    'entry' => $entry,
    'published' => $published,
    'draft_update' => $draftUpdate,
    'admin_entries' => $adminEntries,
    'history' => $history,
    'unpublished' => $unpublished,
    'public_after_unpublish' => $publicAfterUnpublish,
    'republished' => $republished,
    'archived' => $archived,
    'restored_archive' => $restoredArchive,
], JSON_THROW_ON_ERROR);
""",
        encoding="utf-8",
    )
    completed = subprocess.run(
        [
            php,
            str(runner),
            str(CMS / "src" / "Database.php"),
            str(CMS / "src" / "ExtensionManifest.php"),
            str(CMS / "src" / "ExtensionRegistry.php"),
            str(CMS / "src" / "ContentRepository.php"),
            str(CMS / "src" / "Auth.php"),
            str(CMS / "src" / "MediaRepository.php"),
            str(CMS / "src" / "AdminApplication.php"),
            str(CMS / "src" / "ThemeManifest.php"),
            str(CMS / "src" / "ThemeRegistry.php"),
            str(CMS / "src" / "SiteSettings.php"),
            str(CMS / "src" / "ThemeRenderer.php"),
            str(CMS / "src" / "PublicApplication.php"),
            str(REFERENCE),
            str(THEME_REFERENCE),
            str(tmp_path / "cms.sqlite"),
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert completed.returncode == 0, completed.stderr
    result = json.loads(completed.stdout)
    assert result["first"]["status"] == "installed"
    assert result["second"]["status"] == "unchanged"
    assert result["extensions"] == 1
    assert result["content_types"] == 1
    assert result["routes"] == 2
    assert result["slots"] == 2
    assert result["audits"] >= 10
    assert result["schema"] == 5
    assert result["immutable"] == "extension_version_content_changed"
    assert result["admin"]["role"] == "admin"
    assert result["editor"]["role"] == "editor"
    assert result["session_role"] == "admin"
    assert result["csrf_invalid"] == "cms_auth_csrf_invalid"
    assert result["stored_token_is_hash"] is True
    assert result["logged_out"] is True
    assert result["setup_page_status"] == 200
    assert result["setup_page_has_code"] is True
    assert result["setup_page_uses_physical_route"] is True
    assert result["setup_status"] == 303
    assert result["setup_location"] == "/admin/settings/?welcome=1"
    assert result["setup_cookie_count"] == 3
    assert result["onboarding_status"] == 200
    assert result["onboarding_has_next_step"] is True
    assert result["settings_status"] == 303
    assert result["settings_location"] == "/admin/themes/?welcome=1"
    assert result["theme_page_status"] == 200
    assert result["theme_page_has_choice"] is True
    assert result["theme_selection_status"] == 303
    assert result["theme_selection_location"] == "/admin/?onboarding=ready"
    assert result["dashboard_status"] == 200
    assert result["dashboard_has_draft"] is True
    assert result["dashboard_uses_physical_routes"] is True
    assert result["history_page_status"] == 200
    assert result["history_has_csp"] is True
    assert result["login_csrf_rejected"] == 401
    assert result["media"]["mime_type"] == "image/webp"
    assert result["media"]["width"] == 1200
    assert result["media"]["height"] == 800
    assert result["media_page_status"] == 200
    assert result["media_page_has_image"] is True
    assert result["media_file_status"] == 200
    assert result["media_file_type"] == "image/webp"
    assert result["media_in_use"] == "cms_media_in_use"
    assert result["media_links"] == 2
    assert result["theme_install"]["status"] == "installed_and_activated"
    assert result["theme_empty_options_are_objects"] is True
    assert result["active_theme"]["theme_id"] == "kuaiz.studio"
    assert result["active_theme"]["manifest"]["schema"] == "kuaiz-theme/v2"
    assert result["theme_immutable"] == "theme_version_content_changed"
    assert result["site_settings"]["language"] == "zh-Hans-CN"
    assert result["site_settings"]["search_indexing"] is True
    assert result["public_home_status"] == 200
    assert result["public_home_has_site_name"] is True
    assert result["public_home_hides_empty_featured"] is True
    assert "noindex" in result["public_home_noindex"]
    assert result["public_home_language"] is True
    assert result["public_home_canonical"] is True
    assert result["public_home_uses_root_routes"] is True
    assert result["public_list_status"] == 200
    assert result["public_list_has_entry"] is True
    assert result["public_detail_status"] == 200
    assert result["public_query_detail_status"] == 200
    assert result["public_query_detail_canonical"] is True
    assert result["public_detail_has_jsonld"] is True
    assert result["public_missing_status"] == 404
    assert "Disallow: /" in result["robots_blocked"]
    assert "Allow: /" in result["robots_allowed"]
    assert result["sitemap_has_detail"] is True
    assert result["entry"]["status"] == "published"
    assert result["entry"]["version"] == 1
    assert result["published"]["payload"]["title"] == "上海工作室"
    assert result["draft_update"]["version"] == 2
    assert result["admin_entries"][0]["has_unpublished_changes"] is True
    assert result["admin_entries"][0]["payload"]["title"] == "上海工作室（新版草稿）"
    assert len(result["history"]) == 2
    assert result["history"][0]["is_current"] is True
    assert result["history"][1]["is_published"] is True
    assert result["unpublished"]["status"] == "draft"
    assert result["public_after_unpublish"] is None
    assert result["republished"]["status"] == "published"
    assert result["archived"]["status"] == "archived"
    assert result["restored_archive"]["status"] == "draft"


@pytest.mark.skipif(_php_with_sqlite() is None, reason="PHP CLI with PDO_SQLite is unavailable")
def test_php_cms_backup_rejects_tampering_and_restores_atomically(tmp_path):
    php = _php_with_sqlite()
    assert php
    data = tmp_path / "data"
    data.mkdir()
    runner = tmp_path / "backup-smoke.php"
    runner.write_text(
        """<?php
declare(strict_types=1);
require $argv[1];
require $argv[2];
require $argv[3];
$data = $argv[4];
$pdo = KuaizCmsDatabase::connect($data . '/cms.sqlite');
$pdo->prepare(
    "INSERT INTO cms_meta(key,value,updated_at) VALUES('backup_test_marker',:value,:updated_at)"
)->execute([':value' => 'before', ':updated_at' => time()]);
$mediaBody = 'original-media-body';
$mediaHash = hash('sha256', $mediaBody);
$storageKey = 'media/' . substr($mediaHash, 0, 2) . '/' . substr($mediaHash, 2, 2)
    . '/' . $mediaHash . '.webp';
$thumbKey = 'media/' . substr($mediaHash, 0, 2) . '/' . substr($mediaHash, 2, 2)
    . '/' . $mediaHash . '.thumb.webp';
$mediaPath = $data . '/' . $storageKey;
mkdir(dirname($mediaPath), 0700, true);
file_put_contents($mediaPath, $mediaBody);
file_put_contents($data . '/' . $thumbKey, 'original-thumbnail-body');
$pdo->prepare(<<<'SQL'
INSERT INTO cms_media(
  storage_key,original_name,mime_type,byte_size,sha256,alt_text,caption,
  created_at,updated_at,width,height,thumbnail_storage_key,status,archived_at)
VALUES(
  :storage_key,'backup.webp','image/webp',:byte_size,:sha256,'backup','',
  :created_at,:updated_at,1,1,:thumbnail_storage_key,'active',NULL)
SQL)->execute([
    ':storage_key' => $storageKey,
    ':byte_size' => strlen($mediaBody),
    ':sha256' => $mediaHash,
    ':created_at' => time(),
    ':updated_at' => time(),
    ':thumbnail_storage_key' => $thumbKey,
]);
$backup = KuaizCmsBackup::create($data, null, 'system:test');
$pdo->prepare("UPDATE cms_meta SET value='after',updated_at=:updated_at WHERE key='backup_test_marker'")
    ->execute([':updated_at' => time()]);
$pdo = null;
$backupMedia = $backup['path'] . '/' . $storageKey;
$savedMedia = file_get_contents($backupMedia);
file_put_contents($backupMedia, 'tampered');
$tamperError = '';
try {
    KuaizCmsBackup::restore($backup['path'], $data, 'system:test');
} catch (RuntimeException $error) {
    $tamperError = $error->getMessage();
}
$afterTamper = KuaizCmsDatabase::connect($data . '/cms.sqlite');
$markerAfterTamper = $afterTamper->query(
    "SELECT value FROM cms_meta WHERE key='backup_test_marker'"
)->fetchColumn();
$afterTamper = null;
file_put_contents($backupMedia, $savedMedia);
$restored = KuaizCmsBackup::restore($backup['path'], $data, 'system:test');
$restoredPdo = KuaizCmsDatabase::connect($data . '/cms.sqlite');
$markerAfterRestore = $restoredPdo->query(
    "SELECT value FROM cms_meta WHERE key='backup_test_marker'"
)->fetchColumn();
$restoreAudit = (int)$restoredPdo->query(
    "SELECT COUNT(*) FROM cms_audit_logs WHERE action='backup.restored'"
)->fetchColumn();
$restoredPdo = null;
$manifest = json_decode(
    file_get_contents($backup['path'] . '/manifest.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);
echo json_encode([
    'backup' => $backup,
    'manifest' => $manifest,
    'tamper_error' => $tamperError,
    'marker_after_tamper' => $markerAfterTamper,
    'marker_after_restore' => $markerAfterRestore,
    'media_after_restore' => file_get_contents($data . '/' . $storageKey),
    'restore_audit' => $restoreAudit,
    'restored' => $restored,
    'safety_manifest_exists' => is_file($restored['safety_backup'] . '/manifest.json'),
], JSON_THROW_ON_ERROR);
""",
        encoding="utf-8",
    )
    completed = subprocess.run(
        [
            php,
            str(runner),
            str(CMS / "src" / "Database.php"),
            str(CMS / "src" / "Maintenance.php"),
            str(CMS / "src" / "Backup.php"),
            str(data),
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert completed.returncode == 0, completed.stderr
    result = json.loads(completed.stdout)
    assert result["backup"]["file_count"] == 3
    assert result["manifest"]["schema"] == "kuaiz-cms-backup/v1"
    assert result["manifest"]["totals"]["file_count"] == 3
    assert result["tamper_error"] == "cms_backup_file_corrupt"
    assert result["marker_after_tamper"] == "after"
    assert result["marker_after_restore"] == "before"
    assert result["media_after_restore"] == "original-media-body"
    assert result["restore_audit"] == 1
    assert result["restored"]["status"] == "restored"
    assert result["safety_manifest_exists"] is True


@pytest.mark.skipif(_php_with_sqlite() is None, reason="PHP CLI with PDO_SQLite is unavailable")
def test_php_cms_failed_migration_restores_previous_database(tmp_path):
    php = _php_with_sqlite()
    assert php
    runner = tmp_path / "migration-rollback-smoke.php"
    database = tmp_path / "broken.sqlite"
    runner.write_text(
        """<?php
declare(strict_types=1);
require $argv[1];
$database = $argv[2];
$raw = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raw->exec('PRAGMA user_version=2');
$raw = null;
$errorMessage = '';
try {
    KuaizCmsDatabase::connect($database);
} catch (RuntimeException $error) {
    $errorMessage = $error->getMessage();
}
$restored = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$version = (int)$restored->query('PRAGMA user_version')->fetchColumn();
$tableCount = (int)$restored->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='cms_site_settings'"
)->fetchColumn();
$restored = null;
echo json_encode([
    'error' => $errorMessage,
    'version' => $version,
    'site_settings_table_count' => $tableCount,
    'migration_backups' => count(glob(dirname($database) . '/migration-backups/cms-v2-before-v5-*.sqlite') ?: []),
    'failed_snapshots' => count(glob(dirname($database) . '/migration-backups/failed-*.sqlite') ?: []),
], JSON_THROW_ON_ERROR);
""",
        encoding="utf-8",
    )
    completed = subprocess.run(
        [php, str(runner), str(CMS / "src" / "Database.php"), str(database)],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert completed.returncode == 0, completed.stderr
    result = json.loads(completed.stdout)
    assert result["error"] == "cms_database_migration_failed_restored"
    assert result["version"] == 2
    assert result["site_settings_table_count"] == 0
    assert result["migration_backups"] == 1
    assert result["failed_snapshots"] == 1
