<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Maintenance.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/ContentRepository.php';
require_once dirname(__DIR__) . '/src/MediaRepository.php';
require_once dirname(__DIR__) . '/src/ThemeManifest.php';
require_once dirname(__DIR__) . '/src/ThemeRegistry.php';
require_once dirname(__DIR__) . '/src/SiteSettings.php';
require_once dirname(__DIR__) . '/src/ThemeRenderer.php';
require_once dirname(__DIR__) . '/src/PublicApplication.php';
require_once dirname(__DIR__) . '/src/AdminApplication.php';

$dataDirectory = getenv('KUAIZ_CMS_DATA_DIR');
if (!is_string($dataDirectory) || $dataDirectory === '') {
    $dataDirectory = dirname(__DIR__) . '/var';
}

$maintenanceLock = null;
try {
    $maintenanceLock = KuaizCmsMaintenance::shared($dataDirectory);
    $pdo = KuaizCmsDatabase::connect($dataDirectory . '/cms.sqlite');
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $isAdmin = is_string($requestPath)
        && ($requestPath === '/admin' || str_starts_with($requestPath, '/admin/'));
    if ($isAdmin) {
        $response = KuaizCmsAdminApplication::handle(
            $pdo,
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            $dataDirectory
        );
    } else {
        $response = KuaizCmsPublicApplication::handle($pdo, $_SERVER, $dataDirectory);
    }
} catch (Throwable $error) {
    error_log('Kuaiz CMS failed to serve the request: ' . $error->getMessage());
    $response = [
        'status' => 503,
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ],
        'body' => '网站暂时无法访问，请稍后再试。',
    ];
} finally {
    if (isset($pdo)) {
        $pdo = null;
    }
    KuaizCmsMaintenance::release($maintenanceLock);
}

http_response_code((int)$response['status']);
foreach ($response['headers'] as $name => $values) {
    foreach (is_array($values) ? $values : [$values] as $value) {
        header($name . ': ' . $value, $name !== 'Set-Cookie', (int)$response['status']);
    }
}
echo $response['body'];
