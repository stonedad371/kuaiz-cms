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
require_once $root . '/src/ThemeManifest.php';
require_once $root . '/src/ThemeRegistry.php';
require_once $root . '/src/SiteSettings.php';
require_once $root . '/src/ThemeRenderer.php';
require_once $root . '/src/PublicApplication.php';

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
    KuaizCmsAuth::ensureInitialAdmin(
        $pdo,
        'owner@example.com',
        'Smoke Test Owner',
        'Correct horse battery staple!',
        $setupToken
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
