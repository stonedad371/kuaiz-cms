<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Maintenance.php';
require_once dirname(__DIR__) . '/src/Backup.php';

$dataDirectory = getenv('KUAIZ_CMS_DATA_DIR');
if (!is_string($dataDirectory) || $dataDirectory === '') {
    $dataDirectory = dirname(__DIR__) . '/var';
}
$backupDirectory = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : null;

try {
    $result = KuaizCmsBackup::create($dataDirectory, $backupDirectory, 'system:cli');
} catch (Throwable $error) {
    fwrite(STDERR, "CMS 备份失败：" . $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "CMS 备份已完成。\n");
fwrite(STDOUT, "备份位置：" . $result['path'] . "\n");
fwrite(STDOUT, "文件数量：" . $result['file_count'] . "\n");
fwrite(STDOUT, "数据大小：" . $result['byte_size'] . " 字节\n");
