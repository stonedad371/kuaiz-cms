<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Maintenance.php';
require_once dirname(__DIR__) . '/src/Backup.php';

if (($argv[1] ?? '') === '' || ($argv[2] ?? '') !== '--yes') {
    fwrite(STDERR, "用法：php bin/cms-restore.php /备份目录 --yes\n");
    fwrite(STDERR, "恢复前会自动保存当前网站；校验失败时不会替换现有数据。\n");
    exit(2);
}
$dataDirectory = getenv('KUAIZ_CMS_DATA_DIR');
if (!is_string($dataDirectory) || $dataDirectory === '') {
    $dataDirectory = dirname(__DIR__) . '/var';
}

try {
    $result = KuaizCmsBackup::restore($argv[1], $dataDirectory, 'system:cli');
} catch (Throwable $error) {
    fwrite(STDERR, "CMS 恢复失败：" . $error->getMessage() . "\n");
    fwrite(STDERR, "系统不会主动删除恢复前自动备份或未完成的回滚数据。\n");
    exit(1);
}

fwrite(STDOUT, "CMS 恢复已完成。\n");
fwrite(STDOUT, "恢复的备份：" . $result['backup_id'] . "\n");
if (is_string($result['safety_backup'])) {
    fwrite(STDOUT, "恢复前自动备份：" . $result['safety_backup'] . "\n");
}
