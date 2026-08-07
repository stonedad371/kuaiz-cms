<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/Compatibility.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/ExtensionManifest.php';
require_once dirname(__DIR__) . '/src/ExtensionRegistry.php';
require_once dirname(__DIR__) . '/src/ThemeManifest.php';
require_once dirname(__DIR__) . '/src/ThemeRegistry.php';

if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "Kuaiz CMS 需要 PHP 7.4 或更高版本。\n");
    exit(1);
}
$missingExtensions = array_values(array_filter(
    ['pdo_sqlite', 'fileinfo', 'gd'],
    static fn(string $extension): bool => !extension_loaded($extension)
));
if ($missingExtensions !== [] || !function_exists('imagewebp')) {
    $missing = $missingExtensions;
    if (!function_exists('imagewebp')) {
        $missing[] = 'gd-webp';
    }
    fwrite(STDERR, "主机缺少必要的 PHP 能力：" . implode('、', $missing) . "。\n");
    exit(1);
}

$dataDirectory = getenv('KUAIZ_CMS_DATA_DIR');
if (!is_string($dataDirectory) || $dataDirectory === '') {
    $dataDirectory = dirname(__DIR__) . '/var';
}
if (str_contains($dataDirectory, "\0") || is_link($dataDirectory)) {
    fwrite(STDERR, "数据目录不安全，初始化已停止。\n");
    exit(1);
}
if (!is_dir($dataDirectory)
    && !mkdir($dataDirectory, 0700, true)
    && !is_dir($dataDirectory)) {
    fwrite(STDERR, "无法创建 CMS 数据目录。\n");
    exit(1);
}
@chmod($dataDirectory, 0700);

try {
    $pdo = KuaizCmsDatabase::connect($dataDirectory . '/cms.sqlite');
    $extension = file_get_contents(
        dirname(__DIR__) . '/extensions/kuaiz-directory/extension.json'
    );
    $studioTheme = file_get_contents(dirname(__DIR__) . '/themes/kuaiz-studio/theme.json');
    $defaultTheme = file_get_contents(dirname(__DIR__) . '/themes/kuaiz-default/theme.json');
    if (!is_string($extension) || !is_string($studioTheme) || !is_string($defaultTheme)) {
        throw new RuntimeException('cms_bundled_assets_unreadable');
    }
    KuaizCmsExtensionRegistry::installDeclarative(
        $pdo,
        $extension,
        'system:cli',
        '0.1.0'
    );
    KuaizCmsThemeRegistry::install(
        $pdo,
        $studioTheme,
        dirname(__DIR__) . '/themes/kuaiz-studio',
        $dataDirectory,
        'system:cli',
        '0.1.0',
        false
    );
    KuaizCmsThemeRegistry::install(
        $pdo,
        $defaultTheme,
        dirname(__DIR__) . '/themes/kuaiz-default',
        $dataDirectory,
        'system:cli',
        '0.1.0',
        true
    );
    $setupToken = KuaizCmsAuth::provisionSetupToken($pdo);
} catch (Throwable $error) {
    fwrite(STDERR, "CMS 初始化失败：" . $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Kuaiz CMS 已准备好。\n");
fwrite(STDOUT, "请打开网站的 /admin，并输入下面的一次性启用码：\n\n");
fwrite(STDOUT, $setupToken . "\n\n");
fwrite(STDOUT, "创建首个管理员后，此启用码会立即失效。\n");
