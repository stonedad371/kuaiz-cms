<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Database.php';

function upgrade_require(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function upgrade_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            upgrade_remove($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

$data = sys_get_temp_dir() . '/kuaiz-cms-upgrade-' . bin2hex(random_bytes(8));
if (!mkdir($data, 0700)) {
    throw new RuntimeException('upgrade_data_directory_failed');
}

try {
    $database = $data . '/cms.sqlite';
    $pdo = KuaizCmsDatabase::connect($database);
    $pdo->prepare(
        'INSERT INTO cms_meta(key,value,updated_at) VALUES(:key,:value,:updated_at) '
        . 'ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at'
    )->execute([':key' => 'upgrade_sentinel', ':value' => 'preserved', ':updated_at' => time()]);
    $pdo->exec('DROP TABLE cms_site_settings');
    $pdo->exec('PRAGMA user_version=4');
    $pdo = null;

    $upgraded = KuaizCmsDatabase::connect($database);
    upgrade_require(
        (int)$upgraded->query('PRAGMA user_version')->fetchColumn() === 5,
        'upgrade_schema_not_advanced'
    );
    upgrade_require(
        (string)$upgraded->query(
            "SELECT value FROM cms_meta WHERE key='upgrade_sentinel'"
        )->fetchColumn() === 'preserved',
        'upgrade_data_not_preserved'
    );
    upgrade_require(
        (int)$upgraded->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='cms_site_settings'"
        )->fetchColumn() === 1,
        'upgrade_new_table_missing'
    );
    $upgraded = null;
    $backups = glob($data . '/migration-backups/cms-v4-before-v5-*.sqlite') ?: [];
    upgrade_require(count($backups) === 1, 'upgrade_pre_migration_backup_missing');

    $newer = new PDO('sqlite:' . $database);
    $newer->exec('PRAGMA user_version=6');
    $newer = null;
    try {
        KuaizCmsDatabase::connect($database);
        throw new RuntimeException('newer_database_was_accepted');
    } catch (RuntimeException $error) {
        upgrade_require(
            $error->getMessage() === 'cms_database_schema_newer_than_core',
            'newer_database_wrong_error'
        );
    }

    fwrite(STDOUT, json_encode([
        'backup_count' => count($backups),
        'from_schema' => 4,
        'ok' => true,
        'to_schema' => 5,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    upgrade_remove($data);
}
