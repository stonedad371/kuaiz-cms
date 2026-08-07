<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Compatibility.php';

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
    $defaultTheme = (string)file_get_contents($root . '/themes/kuaiz-default/theme.json');
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
        false
    );
    KuaizCmsThemeRegistry::install(
        $pdo,
        $defaultTheme,
        $root . '/themes/kuaiz-default',
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
        str_contains($onboarding['body'], '保存并选择网站风格'),
        'smoke_onboarding_action_failed'
    );
    $settings = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/settings'],
        [],
        [
            '_csrf' => $cookies['__Host-kuaiz_cms_csrf'],
            'site_name' => 'Kuaiz CMS Smoke Test',
            'tagline' => 'Independent publishing',
            'description' => 'A disposable site used by the public repository smoke test.',
            'language' => 'en-US',
            'direction' => 'ltr',
            'base_url' => 'https://cms-smoke.example.com',
            'contact_title' => '',
            'contact_summary' => '',
            'cover_media_id' => '',
        ],
        $cookies,
        [],
        $data
    );
    smoke_require(
        ($settings['headers']['Location'] ?? '') === '/admin/themes?welcome=1',
        'smoke_theme_onboarding_redirect_failed'
    );
    $themes = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/themes?welcome=1'],
        ['welcome' => '1'],
        [],
        $cookies,
        [],
        $data
    );
    smoke_require(
        str_contains($themes['body'], '清简商务')
            && str_contains($themes['body'], 'Studio')
            && str_contains($themes['body'], '选择这个风格'),
        'smoke_theme_selection_page_failed'
    );
    $themeSelection = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/themes/activate'],
        [],
        [
            '_csrf' => $cookies['__Host-kuaiz_cms_csrf'],
            'theme_id' => 'kuaiz.studio',
            'version' => '1.0.0',
        ],
        $cookies,
        [],
        $data
    );
    smoke_require(
        ($themeSelection['headers']['Location'] ?? '') === '/admin?onboarding=ready'
            && KuaizCmsThemeRegistry::active($pdo)['theme_id'] === 'kuaiz.studio',
        'smoke_theme_selection_failed'
    );
    $sourceImage = $data . '/smoke-source.png';
    $canvas = imagecreatetruecolor(320, 200);
    smoke_require(is_resource($canvas) || is_object($canvas), 'smoke_media_canvas_failed');
    $background = imagecolorallocate($canvas, 23, 97, 70);
    imagefilledrectangle($canvas, 0, 0, 319, 199, $background);
    smoke_require(imagepng($canvas, $sourceImage), 'smoke_media_source_failed');
    imagedestroy($canvas);
    $media = KuaizCmsMediaRepository::storeImage(
        $pdo,
        $data,
        $sourceImage,
        'smoke-source.png',
        'Smoke test image',
        '',
        'test:smoke'
    );
    $expectedMediaType = function_exists('imagewebp') ? 'image/webp'
        : (function_exists('imagejpeg') ? 'image/jpeg' : 'image/png');
    $expectedMediaExtension = KuaizCmsMediaRepository::extensionForMimeType(
        $expectedMediaType
    );
    smoke_require($media['mime_type'] === $expectedMediaType, 'smoke_media_type_failed');
    smoke_require(
        str_ends_with($media['storage_key'], '.' . $expectedMediaExtension),
        'smoke_media_extension_failed'
    );
    $mediaFile = KuaizCmsMediaRepository::readFile($pdo, $data, $media['id'], true);
    smoke_require(
        $mediaFile['mime_type'] === $expectedMediaType && $mediaFile['byte_size'] > 0,
        'smoke_media_read_failed'
    );
    KuaizCmsContentRepository::save(
        $pdo,
        'kuaiz.directory',
        'listing',
        'first-listing',
        [
            'title' => 'First listing',
            'summary' => 'Published by the repository smoke test.',
            'phone' => '+86 10 5555 0123',
            'website' => 'https://example.com/first-listing',
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
        str_contains($response['body'], 'data-extension-slot="kuaiz.directory.detail"')
            && str_contains($response['body'], '+86 10 5555 0123')
            && str_contains($response['body'], 'https://example.com/first-listing'),
        'smoke_public_extension_slot_failed'
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
        'media_type' => $media['mime_type'],
        'ok' => true,
        'schema' => $schema,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    if (isset($pdo)) {
        $pdo = null;
    }
    smoke_remove($data);
}
