<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Compatibility.php';

$root = dirname(__DIR__, 2);
foreach ([
    'Database', 'Maintenance', 'Backup', 'Auth', 'ExtensionManifest',
    'ExtensionRegistry', 'ContentRepository', 'MediaRepository', 'ThemeManifest',
    'ThemeRegistry', 'SiteSettings', 'ThemeRenderer', 'PublicApplication',
    'AdminApplication',
] as $class) {
    require_once $root . '/src/' . $class . '.php';
}

function browser_fixture_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function browser_fixture_remove(string $path, string $expected): void
{
    if ($path === $expected && preg_match('#^/tmp/kuaiz-cms-browser-[a-z]+$#D', $path) !== 1) {
        browser_fixture_fail('browser_fixture_path_unsafe');
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        browser_fixture_fail('browser_fixture_read_failed');
    }
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') {
            browser_fixture_remove($path . '/' . $item, $expected);
        }
    }
    @rmdir($path);
}

if ($argc !== 4) {
    browser_fixture_fail('usage: fixture.php DATA_DIRECTORY SEED BASE_URL');
}
$data = (string)$argv[1];
$seed = (string)$argv[2];
$baseUrl = (string)$argv[3];
if (!in_array($seed, ['short', 'long', 'complex', 'empty', 'rtl'], true)
    || preg_match('#^/tmp/kuaiz-cms-browser-[a-z]+$#D', $data) !== 1) {
    browser_fixture_fail('browser_fixture_arguments_invalid');
}
browser_fixture_remove($data, $data);
if (!mkdir($data, 0700, true)) {
    browser_fixture_fail('browser_fixture_directory_failed');
}

$pdo = KuaizCmsDatabase::connect($data . '/cms.sqlite');
KuaizCmsExtensionRegistry::installDeclarative(
    $pdo,
    (string)file_get_contents($root . '/extensions/kuaiz-directory/extension.json'),
    'test:browser',
    '0.1.10'
);
KuaizCmsThemeRegistry::install(
    $pdo,
    (string)file_get_contents($root . '/themes/kuaiz-default/theme.json'),
    $root . '/themes/kuaiz-default',
    $data,
    'test:browser',
    '0.1.10',
    true
);
$setupToken = KuaizCmsAuth::provisionSetupToken($pdo);
KuaizCmsAuth::ensureInitialAdmin(
    $pdo,
    'browser-owner@example.com',
    '浏览器测试管理员',
    'Browser password 2026!',
    $setupToken
);
$admin = KuaizCmsAuth::login(
    $pdo,
    'browser-owner@example.com',
    'Browser password 2026!',
    'browser-fixture'
);
KuaizCmsAuth::createUser(
    $pdo,
    $admin,
    'browser-viewer@example.com',
    '浏览器只读成员',
    'Browser viewer 2026!',
    'viewer'
);

$rtl = $seed === 'rtl';
KuaizCmsSiteSettings::save($pdo, [
    'site_name' => $rtl ? 'دليل الخدمات المحلية' : '快智浏览器验收站',
    'tagline' => $rtl ? 'خدمات موثوقة بالقرب منك' : '独立发布，数据留在自己的主机',
    'description' => $rtl
        ? 'دليل تجريبي للتحقق من اتجاه الكتابة من اليمين إلى اليسار على الهاتف وسطح المكتب.'
        : '用于验证快智 CMS 在桌面和手机上的真实排版、分页、空状态和后台操作。',
    'language' => $rtl ? 'ar-SA' : 'zh-CN',
    'direction' => $rtl ? 'rtl' : 'ltr',
    'base_url' => 'https://' . $seed . '.browser-test.example',
    'search_indexing' => true,
    'contact_title' => $rtl ? 'تواصل معنا' : '联系我们',
    'contact_summary' => $rtl ? 'نرد على رسائلك خلال يوم عمل واحد.' : '工作日内回复业务咨询。',
    'cover_media_id' => null,
], 'test:browser');

if ($seed === 'short') {
    KuaizCmsContentRepository::save($pdo, 'kuaiz.directory', 'listing', 'short-entry', [
        'title' => '杭州轻量办公设备服务',
        'summary' => '为小团队提供按月租赁与上门维护。',
        'phone' => '+86 571 5555 2026',
        'website' => 'https://example.com/short-entry',
        'sort_order' => 1,
    ], 'test:browser', true);
} elseif ($seed === 'long') {
    for ($number = 1; $number <= 35; $number++) {
        KuaizCmsContentRepository::save(
            $pdo,
            'kuaiz.directory',
            'listing',
            'long-entry-' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            [
                'title' => ($number === 35
                    ? '一个很长但仍然必须在窄屏幕里完整换行且不能撑破页面边界的服务项目标题'
                    : '长内容分页项目 ' . $number),
                'summary' => str_repeat('这段业务说明用于检查长内容换行、卡片高度和阅读节奏。', 8),
                'phone' => '+86 571 5555 ' . str_pad((string)$number, 4, '0', STR_PAD_LEFT),
                'website' => 'https://example.com/long-entry-' . $number,
                'sort_order' => $number,
            ],
            'test:browser',
            true
        );
    }
} elseif ($seed === 'complex') {
    KuaizCmsContentRepository::save($pdo, 'kuaiz.directory', 'listing', 'complex-entry', [
        'title' => '研发、设计 & 交付 <script>alert("never")</script>',
        'summary' => "第一行：中英文 mixed content。\n第二行：<b>这些标签只能作为文字显示</b>。\n第三行：emoji 🧭 与特殊字符 % _ \\",
        'phone' => '+86 (571) 5555-2026 ext. 808',
        'website' => 'https://example.com/complex?from=cms&campaign=browser',
        'sort_order' => -12.5,
    ], 'test:browser', true);
} elseif ($seed === 'rtl') {
    foreach ([
        ['arabic-consulting', 'استشارات الأعمال المحلية', 'نساعد الشركات الصغيرة على تنظيم عملياتها والوصول إلى عملائها.'],
        ['arabic-design', 'تصميم الهوية الرقمية', 'تصميم واضح ومتجاوب للهواتف وأجهزة سطح المكتب.'],
    ] as $item) {
        KuaizCmsContentRepository::save($pdo, 'kuaiz.directory', 'listing', $item[0], [
            'title' => $item[1],
            'summary' => $item[2],
            'phone' => '+966 11 555 2026',
            'website' => 'https://example.com/' . $item[0],
            'sort_order' => 1,
        ], 'test:browser', true);
    }
}

$pdo = null;
fwrite(STDOUT, json_encode([
    'ok' => true,
    'seed' => $seed,
    'data_directory' => $data,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
