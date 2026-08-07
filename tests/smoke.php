<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Database.php';
require_once $root . '/src/Maintenance.php';
require_once $root . '/src/Backup.php';
require_once $root . '/src/Auth.php';
require_once $root . '/src/ExtensionManifest.php';
require_once $root . '/src/ExtensionRegistry.php';
require_once $root . '/src/ContentRepository.php';
require_once $root . '/src/MediaRepository.php';
require_once $root . '/src/ThemeManifest.php';
require_once $root . '/src/ThemeRegistry.php';
require_once $root . '/src/SiteSettings.php';
require_once $root . '/src/ThemeRenderer.php';
require_once $root . '/src/PublicApplication.php';
require_once $root . '/src/AdminApplication.php';

function smoke_require(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function smoke_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                smoke_remove($path . DIRECTORY_SEPARATOR . $item);
            }
        }
    }
    @rmdir($path);
}

$data = sys_get_temp_dir() . '/kuaiz-cms-smoke-' . bin2hex(random_bytes(8));
if (!mkdir($data, 0700)) {
    throw new RuntimeException('smoke_data_directory_failed');
}

try {
    $pdo = KuaizCmsDatabase::connect($data . '/cms.sqlite');
    $extension = (string)file_get_contents(
        $root . '/extensions/kuaiz-directory/extension.json'
    );
    $theme = (string)file_get_contents($root . '/themes/kuaiz-studio/theme.json');
    KuaizCmsExtensionRegistry::installDeclarative(
        $pdo,
        $extension,
        'test:smoke',
        '0.1.0'
    );
    KuaizCmsThemeRegistry::install(
        $pdo,
        $theme,
        $root . '/themes/kuaiz-studio',
        $data,
        'test:smoke',
        '0.1.0',
        true
    );
    $setupToken = KuaizCmsAuth::provisionSetupToken($pdo);
    $setup = KuaizCmsAdminApplication::handle(
        $pdo,
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/setup',
            'REMOTE_ADDR' => '127.0.0.1',
        ],
        [],
        [
            'setup_token' => $setupToken,
            'username' => 'owner@example.com',
            'display_name' => 'Smoke Test Owner',
            'password' => 'Correct horse battery staple!',
            'password_confirmation' => 'Correct horse battery staple!',
        ],
        []
    );
    smoke_require($setup['status'] === 303, 'smoke_admin_setup_failed');
    smoke_require(
        ($setup['headers']['Location'] ?? '') === '/admin/settings?welcome=1',
        'smoke_onboarding_redirect_failed'
    );
    $cookies = [];
    foreach ($setup['headers']['Set-Cookie'] as $cookieHeader) {
        [$pair] = explode(';', $cookieHeader, 2);
        [$cookieName, $cookieValue] = explode('=', $pair, 2);
        $cookies[$cookieName] = $cookieValue;
    }
    $onboarding = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/settings?welcome=1'],
        ['welcome' => '1'],
        [],
        $cookies,
        [],
        $data
    );
    smoke_require($onboarding['status'] === 200, 'smoke_onboarding_page_failed');
    smoke_require(
        str_contains($onboarding['body'], '保存并进入内容管理'),
        'smoke_onboarding_action_failed'
    );
    KuaizCmsSiteSettings::save($pdo, [
        'site_name' => 'Kuaiz CMS Smoke Test',
        'tagline' => 'Independent publishing',
        'description' => 'A disposable site used by the public repository smoke test.',
        'language' => 'en-US',
        'direction' => 'ltr',
        'base_url' => 'https://cms-smoke.example.com',
        'search_indexing' => false,
        'contact_title' => '',
        'contact_summary' => '',
        'cover_media_id' => null,
    ], 'test:smoke');
    KuaizCmsContentRepository::save(
        $pdo,
        'kuaiz.directory',
        'listing',
        'first-listing',
        [
            'title' => 'First listing',
            'summary' => 'Published by the repository smoke test.',
        ],
        'test:smoke',
        true
    );
    $response = KuaizCmsPublicApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/directory/first-listing'],
        $data
    );
    smoke_require($response['status'] === 200, 'smoke_public_status_failed');
    smoke_require(
        str_contains($response['body'], 'First listing'),
        'smoke_public_content_failed'
    );
    smoke_require(
        str_contains((string)($response['headers']['X-Robots-Tag'] ?? ''), 'noindex'),
        'smoke_default_noindex_failed'
    );
    $schema = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $pdo = null;
    $backup = KuaizCmsBackup::create($data, null, 'test:smoke');
    smoke_require($schema === 5, 'smoke_schema_failed');
    smoke_require($backup['file_count'] >= 1, 'smoke_backup_failed');
    fwrite(STDOUT, json_encode([
        'backup_files' => $backup['file_count'],
        'ok' => true,
        'schema' => $schema,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    if (isset($pdo)) {
        $pdo = null;
    }
    smoke_remove($data);
}
