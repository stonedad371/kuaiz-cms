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
        ($setup['headers']['Location'] ?? '') === '/admin/settings/?welcome=1',
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
        ($settings['headers']['Location'] ?? '') === '/admin/themes/?welcome=1',
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
        ($themeSelection['headers']['Location'] ?? '') === '/admin/?onboarding=ready'
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
    for ($number = 2; $number <= 31; $number++) {
        $fixturePath = $data . '/media-' . $number . '.png';
        $fixture = imagecreatetruecolor(32, 24);
        smoke_require(is_resource($fixture) || is_object($fixture), 'smoke_media_fixture_failed');
        $color = imagecolorallocate(
            $fixture,
            ($number * 37) % 255,
            ($number * 67) % 255,
            ($number * 97) % 255
        );
        imagefilledrectangle($fixture, 0, 0, 31, 23, $color);
        smoke_require(imagepng($fixture, $fixturePath), 'smoke_media_fixture_write_failed');
        imagedestroy($fixture);
        KuaizCmsMediaRepository::storeImage(
            $pdo,
            $data,
            $fixturePath,
            $number === 31 ? 'needle-image.png' : 'fixture-' . $number . '.png',
            $number === 31 ? 'Needle media' : 'Fixture media ' . $number,
            '',
            'test:smoke'
        );
    }
    smoke_require(
        KuaizCmsMediaRepository::count($pdo) === 31
            && count(KuaizCmsMediaRepository::items($pdo, 'active', 30, 30)) === 1
            && KuaizCmsMediaRepository::count($pdo, 'active', 'Needle media') === 1,
        'smoke_media_pagination_search_failed'
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
    $queryResponse = KuaizCmsPublicApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?page=directory/first-listing'],
        $data,
        ['page' => 'directory/first-listing']
    );
    smoke_require($queryResponse['status'] === 200, 'smoke_public_query_status_failed');
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

    for ($number = 2; $number <= 105; $number++) {
        KuaizCmsContentRepository::save(
            $pdo,
            'kuaiz.directory',
            'listing',
            'listing-' . str_pad((string)$number, 3, '0', STR_PAD_LEFT),
            [
                'title' => $number === 105 ? 'Needle Result' : 'Listing ' . $number,
                'summary' => 'Pagination fixture ' . $number,
                'phone' => '+86 10 5555 ' . str_pad((string)$number, 4, '0', STR_PAD_LEFT),
                'website' => 'https://example.com/listing-' . $number,
            ],
            'test:smoke',
            true
        );
    }
    $secondPage = KuaizCmsPublicApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?page=directory&p=2'],
        $data,
        ['page' => 'directory', 'p' => '2']
    );
    smoke_require(
        $secondPage['status'] === 200
            && str_contains($secondPage['body'], '第 2 / 5 页')
            && str_contains($secondPage['body'], 'rel="canonical" href="https://cms-smoke.example.com/?page=directory&amp;p=2"'),
        'smoke_public_pagination_failed'
    );
    $invalidPage = KuaizCmsPublicApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?page=directory&p=999'],
        $data,
        ['page' => 'directory', 'p' => '999']
    );
    smoke_require($invalidPage['status'] === 404, 'smoke_public_page_boundary_failed');

    KuaizCmsSiteSettings::save($pdo, [
        'site_name' => 'Kuaiz CMS Smoke Test',
        'tagline' => 'Independent publishing',
        'description' => 'A disposable site used by the public repository smoke test.',
        'language' => 'en-US',
        'direction' => 'ltr',
        'base_url' => 'https://cms-smoke.example.com',
        'search_indexing' => true,
        'contact_title' => '',
        'contact_summary' => '',
        'cover_media_id' => null,
    ], 'test:smoke');
    $sitemap = KuaizCmsPublicApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/sitemap.xml'],
        $data
    );
    smoke_require(
        $sitemap['status'] === 200
            && substr_count($sitemap['body'], '<url>') === 111
            && str_contains($sitemap['body'], '/?page=directory/listing-105')
            && str_contains($sitemap['body'], '/?page=directory&amp;p=5'),
        'smoke_complete_sitemap_failed'
    );

    $dashboard = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin?p=2'],
        ['p' => '2'],
        [],
        $cookies,
        [],
        $data
    );
    smoke_require(
        $dashboard['status'] === 200
            && str_contains($dashboard['body'], '第 2 / 4 页')
            && str_contains($dashboard['body'], '/admin/?p=1'),
        'smoke_admin_pagination_failed'
    );
    $filteredDashboard = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin?status=published'],
        ['status' => 'published'],
        [],
        $cookies,
        [],
        $data
    );
    smoke_require(
        str_contains($filteredDashboard['body'], '?p=2&amp;status=published')
            && !str_contains($filteredDashboard['body'], '&amp;amp;status='),
        'smoke_admin_pagination_filter_failed'
    );
    $search = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin?q=Needle'],
        ['q' => 'Needle'],
        [],
        $cookies,
        [],
        $data
    );
    smoke_require(
        $search['status'] === 200
            && str_contains($search['body'], 'Needle Result')
            && str_contains($search['body'], '内容（1）'),
        'smoke_admin_search_failed'
    );
    $mediaDashboard = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/media?p=2'],
        ['p' => '2'],
        [],
        $cookies,
        [],
        $data
    );
    $mediaSearch = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/media?q=Needle'],
        ['q' => 'Needle'],
        [],
        $cookies,
        [],
        $data
    );
    smoke_require(
        str_contains($mediaDashboard['body'], '第 2 / 2 页')
            && str_contains($mediaSearch['body'], 'needle-image.png')
            && str_contains($mediaSearch['body'], '可用图片（1）'),
        'smoke_admin_media_search_failed'
    );

    $entry = KuaizCmsContentRepository::adminEntries(
        $pdo,
        'kuaiz.directory',
        'listing',
        null,
        100,
        0,
        'first-listing'
    )[0];
    KuaizCmsContentRepository::save(
        $pdo,
        'kuaiz.directory',
        'listing',
        'first-listing',
        [
            'title' => 'Changed listing',
            'summary' => 'A draft that will be replaced by a restored revision.',
            'phone' => '+86 10 5555 0123',
            'website' => 'https://example.com/changed-listing',
        ],
        'test:smoke',
        false
    );
    $history = KuaizCmsContentRepository::history($pdo, $entry['id']);
    $oldRevision = $history[count($history) - 1];
    $restored = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/content/restore-revision'],
        [],
        [
            '_csrf' => $cookies['__Host-kuaiz_cms_csrf'],
            'entry_id' => (string)$entry['id'],
            'revision_id' => (string)$oldRevision['id'],
        ],
        $cookies,
        [],
        $data
    );
    $restoredEntry = KuaizCmsContentRepository::adminEntry($pdo, $entry['id']);
    smoke_require(
        $restored['status'] === 303
            && $restoredEntry['current_version'] === 3
            && $restoredEntry['payload']['title'] === 'First listing',
        'smoke_admin_revision_restore_failed'
    );

    $createdUser = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/create'],
        [],
        [
            '_csrf' => $cookies['__Host-kuaiz_cms_csrf'],
            'username' => 'viewer@example.com',
            'display_name' => 'Smoke Viewer',
            'password' => 'Viewer password 123!',
            'role' => 'viewer',
        ],
        $cookies,
        [],
        $data
    );
    $viewerId = (int)$pdo->query(
        "SELECT id FROM cms_users WHERE username='viewer@example.com'"
    )->fetchColumn();
    $disabledUser = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/access'],
        [],
        [
            '_csrf' => $cookies['__Host-kuaiz_cms_csrf'],
            'user_id' => (string)$viewerId,
            'role' => 'viewer',
            'status' => 'disabled',
        ],
        $cookies,
        [],
        $data
    );
    $usersPage = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/users'],
        [],
        [],
        $cookies,
        [],
        $data
    );
    smoke_require(
        $createdUser['status'] === 303
            && $disabledUser['status'] === 303
            && $usersPage['status'] === 200
            && str_contains($usersPage['body'], 'Smoke Viewer')
            && (string)$pdo->query('SELECT status FROM cms_users WHERE id=' . $viewerId)->fetchColumn() === 'disabled',
        'smoke_admin_users_failed'
    );

    $backupResponse = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/backups/create'],
        [],
        ['_csrf' => $cookies['__Host-kuaiz_cms_csrf']],
        $cookies,
        [],
        $data
    );
    $backupsPage = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/backups?created=1'],
        ['created' => '1'],
        [],
        $cookies,
        [],
        $data
    );
    smoke_require(
        $backupResponse['status'] === 303
            && count(KuaizCmsBackup::backups($data)) === 1
            && str_contains($backupsPage['body'], '备份已经创建'),
        'smoke_admin_backup_failed'
    );

    $passwordResponse = KuaizCmsAdminApplication::handle(
        $pdo,
        ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/account/password'],
        [],
        [
            '_csrf' => $cookies['__Host-kuaiz_cms_csrf'],
            'current_password' => 'Correct horse battery staple!',
            'new_password' => 'A safer smoke password 2026!',
            'password_confirmation' => 'A safer smoke password 2026!',
        ],
        $cookies,
        [],
        $data
    );
    smoke_require(
        $passwordResponse['status'] === 200
            && str_contains($passwordResponse['body'], '密码已经修改')
            && (int)$pdo->query('SELECT COUNT(*) FROM cms_sessions')->fetchColumn() === 0,
        'smoke_admin_password_change_failed'
    );
    $schema = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $pdo = null;
    $backup = KuaizCmsBackup::create($data, null, 'test:smoke');
    smoke_require($schema === 5, 'smoke_schema_failed');
    smoke_require($backup['file_count'] >= 1, 'smoke_backup_failed');
    fwrite(STDOUT, json_encode([
        'backup_files' => $backup['file_count'],
        'published_entries' => 105,
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
