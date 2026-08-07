<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/Compatibility.php';
$dataDirectory = getenv('KUAIZ_CMS_DATA_DIR');
if (!is_string($dataDirectory) || $dataDirectory === '') {
    $dataDirectory = $root . '/var';
}
$report = [
    'ok' => false,
    'runtime' => 'community-php-sqlite-v1',
    'checks' => [],
];
$fail = static function (string $name, $detail) use (&$report): void {
    $report['checks'][$name] = ['ok' => false, 'detail' => $detail];
};
$pass = static function (string $name, $detail) use (&$report): void {
    $report['checks'][$name] = ['ok' => true, 'detail' => $detail];
};

try {
    $manifestRaw = file_get_contents($root . '/cms-manifest.json');
    $manifest = is_string($manifestRaw)
        ? json_decode($manifestRaw, true, 32, JSON_THROW_ON_ERROR) : null;
    if (!is_array($manifest) || ($manifest['runtime_profile'] ?? null) !== $report['runtime']) {
        throw new RuntimeException('cms_manifest_invalid');
    }
    $report['version'] = $manifest['version'];
    $report['expected_schema'] = $manifest['database_schema_version'];
    $pass('manifest', $manifest['version']);
} catch (Throwable $error) {
    $fail('manifest', $error->getMessage());
}

$required = ['pdo_sqlite', 'fileinfo', 'gd'];
$missing = array_values(array_filter(
    $required,
    static fn(string $extension): bool => !extension_loaded($extension)
));
if (!function_exists('imagewebp')) {
    $missing[] = 'gd-webp';
}
PHP_VERSION_ID < 70400 ? $fail('php', PHP_VERSION) : $pass('php', PHP_VERSION);
$missing !== [] ? $fail('extensions', $missing) : $pass('extensions', $required);

if (str_contains($dataDirectory, "\0") || is_link($dataDirectory) || !is_dir($dataDirectory)) {
    $fail('data_directory', 'missing_or_unsafe');
} else {
    $realData = realpath($dataDirectory);
    if (!is_string($realData)) {
        $fail('data_directory', 'unresolvable');
    } else {
        $pass('data_directory', $realData);
        $database = $realData . '/cms.sqlite';
        if (is_link($database) || !is_file($database)) {
            $fail('database', 'missing_or_unsafe');
        } elseif (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $fail('database', 'pdo_sqlite_missing');
        } else {
            try {
                $pdo = new PDO('sqlite:' . $database, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $integrity = strtolower((string)$pdo->query('PRAGMA integrity_check')->fetchColumn());
                $schema = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
                if ($integrity !== 'ok') {
                    throw new RuntimeException('integrity_check_failed');
                }
                if (isset($report['expected_schema']) && $schema > (int)$report['expected_schema']) {
                    throw new RuntimeException('database_newer_than_code');
                }
                $pass('database', [
                    'integrity' => $integrity,
                    'schema' => $schema,
                    'upgrade_required' => isset($report['expected_schema'])
                        && $schema < (int)$report['expected_schema'],
                ]);
            } catch (Throwable $error) {
                $fail('database', $error->getMessage());
            }
        }
    }
}

$report['ok'] = $report['checks'] !== [] && array_reduce(
    $report['checks'],
    static fn(bool $ok, array $check): bool => $ok && ($check['ok'] ?? false),
    true
);
fwrite(STDOUT, json_encode(
    $report,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
) . "\n");
exit($report['ok'] ? 0 : 1);
