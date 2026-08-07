<?php
declare(strict_types=1);

/** Verified, code-free backup and atomic restore for CMS data and owned assets. */
final class KuaizCmsBackup
{
    private const BACKUP_SCHEMA = 'kuaiz-cms-backup/v1';
    private const MAX_MANIFEST_BYTES = 5242880;
    private const MAX_FILES = 50000;
    private const MAX_TOTAL_BYTES = 4294967296;

    public static function create(
        string $dataDirectory,
        ?string $backupDirectory,
        string $actor
    ): array {
        self::actor($actor);
        $dataRoot = self::dataRoot($dataDirectory);
        $backupRoot = self::backupRoot($dataRoot, $backupDirectory);
        $lock = KuaizCmsMaintenance::exclusive($dataRoot);
        $pdo = null;
        $staging = null;
        try {
            $database = $dataRoot . '/cms.sqlite';
            if (is_link($database) || !is_file($database)) {
                throw new RuntimeException('cms_backup_database_missing');
            }
            $pdo = KuaizCmsDatabase::connect($database);
            $backupId = self::backupId('backup');
            $staging = $backupRoot . '/.tmp-' . $backupId;
            $final = $backupRoot . '/' . $backupId;
            self::freshDirectory($staging);
            $manifest = self::snapshot($pdo, $dataRoot, $staging, $backupId, $actor);
            self::writeManifest($staging, $manifest);
            if (file_exists($final) || !rename($staging, $final)) {
                throw new RuntimeException('cms_backup_commit_failed');
            }
            $staging = null;
            self::audit(
                $pdo,
                $actor,
                'backup.created',
                $backupId,
                [
                    'database_schema_version' => $manifest['database_schema_version'],
                    'file_count' => $manifest['totals']['file_count'],
                    'byte_size' => $manifest['totals']['byte_size'],
                ]
            );
            return [
                'backup_id' => $backupId,
                'path' => $final,
                'database_schema_version' => $manifest['database_schema_version'],
                'file_count' => $manifest['totals']['file_count'],
                'byte_size' => $manifest['totals']['byte_size'],
            ];
        } finally {
            $pdo = null;
            if (is_string($staging) && file_exists($staging)) {
                self::removeTemporary($staging);
            }
            KuaizCmsMaintenance::release($lock);
        }
    }

    public static function restore(
        string $backupPath,
        string $dataDirectory,
        string $actor
    ): array {
        self::actor($actor);
        $dataRoot = self::dataRoot($dataDirectory);
        $backup = self::verifiedBackup($backupPath);
        $backupRoot = self::backupRoot($dataRoot, null);
        $lock = KuaizCmsMaintenance::exclusive($dataRoot);
        $candidate = $dataRoot . '/.restore-' . bin2hex(random_bytes(8));
        $rollback = $dataRoot . '/.rollback-' . bin2hex(random_bytes(8));
        $currentPdo = null;
        $candidatePdo = null;
        $livePdo = null;
        $safetyBackup = null;
        $movedOld = [];
        $installedNew = [];
        $preserveRollback = false;
        try {
            self::freshDirectory($candidate);
            self::copyVerifiedBackup($backup['root'], $backup['manifest'], $candidate);
            foreach (['media', 'themes'] as $directory) {
                self::ensureDirectory($candidate . '/' . $directory, $candidate);
            }
            $candidatePdo = KuaizCmsDatabase::connect($candidate . '/cms.sqlite');
            self::verifyDatabaseReferences($candidatePdo, $candidate, $backup['manifest']);
            $restoredSchema = (int)$candidatePdo->query('PRAGMA user_version')->fetchColumn();
            $candidatePdo = null;

            $currentDatabase = $dataRoot . '/cms.sqlite';
            if (is_file($currentDatabase) && !is_link($currentDatabase)) {
                $currentPdo = KuaizCmsDatabase::connect($currentDatabase);
                $safetyId = self::backupId('pre-restore');
                $safetyStaging = $backupRoot . '/.tmp-' . $safetyId;
                $safetyFinal = $backupRoot . '/' . $safetyId;
                self::freshDirectory($safetyStaging);
                try {
                    $safetyManifest = self::snapshot(
                        $currentPdo,
                        $dataRoot,
                        $safetyStaging,
                        $safetyId,
                        $actor
                    );
                    self::writeManifest($safetyStaging, $safetyManifest);
                    if (file_exists($safetyFinal) || !rename($safetyStaging, $safetyFinal)) {
                        throw new RuntimeException('cms_restore_safety_backup_failed');
                    }
                    $safetyBackup = $safetyFinal;
                } finally {
                    if (file_exists($safetyStaging)) {
                        self::removeTemporary($safetyStaging);
                    }
                }
                $currentPdo = null;
            } elseif (file_exists($currentDatabase)) {
                throw new RuntimeException('cms_restore_database_unsafe');
            }
            foreach (['cms.sqlite-journal', 'cms.sqlite-wal', 'cms.sqlite-shm'] as $journal) {
                if (file_exists($dataRoot . '/' . $journal)) {
                    throw new RuntimeException('cms_restore_database_busy');
                }
            }

            self::freshDirectory($rollback);
            foreach (['cms.sqlite', 'media', 'themes'] as $name) {
                $live = $dataRoot . '/' . $name;
                if (!file_exists($live)) {
                    continue;
                }
                if (is_link($live) || !rename($live, $rollback . '/' . $name)) {
                    throw new RuntimeException('cms_restore_swap_failed');
                }
                $movedOld[] = $name;
            }
            foreach (['cms.sqlite', 'media', 'themes'] as $name) {
                if (!rename($candidate . '/' . $name, $dataRoot . '/' . $name)) {
                    throw new RuntimeException('cms_restore_swap_failed');
                }
                $installedNew[] = $name;
            }
            $livePdo = KuaizCmsDatabase::connect($dataRoot . '/cms.sqlite');
            self::verifyDatabaseReferences($livePdo, $dataRoot, null);
            self::audit(
                $livePdo,
                $actor,
                'backup.restored',
                $backup['manifest']['backup_id'],
                [
                    'restored_schema_version' => $restoredSchema,
                    'safety_backup' => $safetyBackup,
                ]
            );
            $livePdo = null;
            self::removeTemporary($rollback);
            $movedOld = [];
            return [
                'backup_id' => $backup['manifest']['backup_id'],
                'database_schema_version' => $restoredSchema,
                'safety_backup' => $safetyBackup,
                'status' => 'restored',
            ];
        } catch (Throwable $error) {
            $currentPdo = null;
            $candidatePdo = null;
            $livePdo = null;
            $rollbackFailed = false;
            foreach (array_reverse($installedNew) as $name) {
                $live = $dataRoot . '/' . $name;
                if (file_exists($live) && !file_exists($candidate . '/' . $name)) {
                    if (!@rename($live, $candidate . '/' . $name)) {
                        $rollbackFailed = true;
                    }
                }
            }
            foreach (array_reverse($movedOld) as $name) {
                $saved = $rollback . '/' . $name;
                if (file_exists($saved) && !file_exists($dataRoot . '/' . $name)) {
                    if (!@rename($saved, $dataRoot . '/' . $name)) {
                        $rollbackFailed = true;
                    }
                } elseif (file_exists($saved)) {
                    $rollbackFailed = true;
                }
            }
            if ($rollbackFailed) {
                $preserveRollback = true;
                throw new RuntimeException(
                    'cms_restore_rollback_failed:' . $rollback,
                    0,
                    $error
                );
            }
            throw $error;
        } finally {
            $currentPdo = null;
            $candidatePdo = null;
            $livePdo = null;
            if (file_exists($candidate)) {
                self::removeTemporary($candidate);
            }
            if (!$preserveRollback && file_exists($rollback)) {
                self::removeTemporary($rollback);
            }
            KuaizCmsMaintenance::release($lock);
        }
    }

    private static function snapshot(
        PDO $pdo,
        string $dataRoot,
        string $destination,
        string $backupId,
        string $actor
    ): array {
        if ($pdo->inTransaction()) {
            throw new RuntimeException('cms_backup_transaction_active');
        }
        $records = [];
        $totalBytes = 0;
        foreach (self::assetKeys($pdo) as $key) {
            self::copyOwnedFile($dataRoot, $destination, $key, $records, $totalBytes);
        }
        $databaseTarget = $destination . '/cms.sqlite';
        $quotedTarget = str_replace("'", "''", $databaseTarget);
        $pdo->exec("VACUUM INTO '" . $quotedTarget . "'");
        @chmod($databaseTarget, 0600);
        self::recordFile($databaseTarget, 'cms.sqlite', $records, $totalBytes);
        usort($records, static fn(array $left, array $right): int => strcmp(
            $left['path'],
            $right['path']
        ));
        return [
            'schema' => self::BACKUP_SCHEMA,
            'backup_id' => $backupId,
            'runtime_profile' => KuaizCmsDatabase::RUNTIME_PROFILE,
            'created_at' => time(),
            'created_by' => $actor,
            'database_schema_version' => (int)$pdo->query('PRAGMA user_version')->fetchColumn(),
            'files' => $records,
            'totals' => [
                'file_count' => count($records),
                'byte_size' => $totalBytes,
            ],
        ];
    }

    private static function verifiedBackup(string $backupPath): array
    {
        if ($backupPath === '' || str_contains($backupPath, "\0")
            || is_link($backupPath) || !is_dir($backupPath)) {
            throw new RuntimeException('cms_backup_path_unsafe');
        }
        $root = realpath($backupPath);
        if (!is_string($root)) {
            throw new RuntimeException('cms_backup_path_unsafe');
        }
        $manifestPath = $root . '/manifest.json';
        if (is_link($manifestPath) || !is_file($manifestPath)) {
            throw new RuntimeException('cms_backup_manifest_missing');
        }
        $bytes = filesize($manifestPath);
        if (!is_int($bytes) || $bytes < 2 || $bytes > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('cms_backup_manifest_invalid');
        }
        $body = file_get_contents($manifestPath);
        try {
            $manifest = is_string($body)
                ? json_decode($body, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $error) {
            throw new RuntimeException('cms_backup_manifest_invalid', 0, $error);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('cms_backup_manifest_invalid');
        }
        self::exactKeys($manifest, [
            'schema', 'backup_id', 'runtime_profile', 'created_at', 'created_by',
            'database_schema_version', 'files', 'totals',
        ], 'cms_backup_manifest_fields_invalid');
        if (($manifest['schema'] ?? null) !== self::BACKUP_SCHEMA
            || ($manifest['runtime_profile'] ?? null) !== KuaizCmsDatabase::RUNTIME_PROFILE
            || !is_string($manifest['backup_id'] ?? null)
            || !preg_match('/^(?:backup|pre-restore)-[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/D', $manifest['backup_id'])
            || !is_int($manifest['created_at'] ?? null) || $manifest['created_at'] < 1
            || !is_string($manifest['created_by'] ?? null)
            || !preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $manifest['created_by'])
            || !is_int($manifest['database_schema_version'] ?? null)
            || $manifest['database_schema_version'] < 1
            || $manifest['database_schema_version'] > KuaizCmsDatabase::SCHEMA_VERSION
            || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files'])
            || $manifest['files'] === [] || count($manifest['files']) > self::MAX_FILES
            || !is_array($manifest['totals'] ?? null) || array_is_list($manifest['totals'])) {
            throw new RuntimeException('cms_backup_manifest_invalid');
        }
        self::exactKeys(
            $manifest['totals'],
            ['file_count', 'byte_size'],
            'cms_backup_manifest_invalid'
        );
        $seen = [];
        $totalBytes = 0;
        foreach ($manifest['files'] as $file) {
            if (!is_array($file) || array_is_list($file)) {
                throw new RuntimeException('cms_backup_manifest_invalid');
            }
            self::exactKeys($file, ['path', 'byte_size', 'sha256'], 'cms_backup_manifest_invalid');
            $path = $file['path'] ?? null;
            if (!is_string($path) || !self::ownedPath($path) || isset($seen[$path])
                || !is_int($file['byte_size'] ?? null) || $file['byte_size'] < 1
                || !is_string($file['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
                throw new RuntimeException('cms_backup_manifest_invalid');
            }
            $seen[$path] = true;
            $totalBytes += $file['byte_size'];
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('cms_backup_too_large');
            }
            self::verifySourceFile($root, $path, $file);
        }
        if (!isset($seen['cms.sqlite'])
            || ($manifest['totals']['file_count'] ?? null) !== count($manifest['files'])
            || ($manifest['totals']['byte_size'] ?? null) !== $totalBytes) {
            throw new RuntimeException('cms_backup_manifest_invalid');
        }
        return ['root' => $root, 'manifest' => $manifest];
    }

    private static function copyVerifiedBackup(string $root, array $manifest, string $candidate): void
    {
        foreach ($manifest['files'] as $file) {
            $path = $file['path'];
            $source = $root . '/' . $path;
            $target = $candidate . '/' . $path;
            self::ensureDirectory(dirname($target), $candidate);
            if (is_link($target) || !copy($source, $target)) {
                throw new RuntimeException('cms_restore_copy_failed');
            }
            @chmod($target, 0600);
            if ((int)filesize($target) !== $file['byte_size']
                || !hash_equals($file['sha256'], (string)hash_file('sha256', $target))) {
                throw new RuntimeException('cms_restore_copy_failed');
            }
        }
    }

    private static function verifyDatabaseReferences(
        PDO $pdo,
        string $root,
        ?array $manifest
    ): void {
        $expected = ['cms.sqlite' => true];
        foreach (self::assetKeys($pdo) as $key) {
            $expected[$key] = true;
            $path = $root . '/' . $key;
            if (is_link($path) || !is_file($path)) {
                throw new RuntimeException('cms_backup_asset_missing');
            }
            $realRoot = realpath($root);
            $realPath = realpath($path);
            if (!is_string($realRoot) || !is_string($realPath)
                || !str_starts_with($realPath, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('cms_backup_asset_unsafe');
            }
        }
        $mediaRows = $pdo->query(
            'SELECT storage_key,byte_size,sha256 FROM cms_media ORDER BY id'
        )->fetchAll();
        foreach ($mediaRows as $media) {
            $path = $root . '/' . $media['storage_key'];
            if ((int)filesize($path) !== (int)$media['byte_size']
                || !hash_equals((string)$media['sha256'], (string)hash_file('sha256', $path))) {
                throw new RuntimeException('cms_backup_media_corrupt');
            }
        }
        $themeRows = $pdo->query(
            'SELECT storage_key,byte_size,sha256 FROM cms_theme_assets ORDER BY theme_id,version,asset_path'
        )->fetchAll();
        foreach ($themeRows as $asset) {
            $path = $root . '/' . $asset['storage_key'];
            if ((int)filesize($path) !== (int)$asset['byte_size']
                || !hash_equals((string)$asset['sha256'], (string)hash_file('sha256', $path))) {
                throw new RuntimeException('cms_backup_theme_asset_corrupt');
            }
        }
        if (is_array($manifest)) {
            $actual = [];
            foreach ($manifest['files'] as $file) {
                $actual[$file['path']] = true;
            }
            ksort($actual);
            ksort($expected);
            if (array_keys($actual) !== array_keys($expected)) {
                throw new RuntimeException('cms_backup_file_set_invalid');
            }
        }
    }

    private static function assetKeys(PDO $pdo): array
    {
        $keys = [];
        foreach ($pdo->query(
            'SELECT storage_key,thumbnail_storage_key FROM cms_media ORDER BY id'
        )->fetchAll() as $media) {
            foreach ([$media['storage_key'], $media['thumbnail_storage_key']] as $key) {
                if ($key === null) {
                    continue;
                }
                if (!is_string($key) || !preg_match(
                    '#^media/[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{64}(?:\.thumb)?\.(?:webp|jpg|png)$#D',
                    $key
                )) {
                    throw new RuntimeException('cms_backup_media_path_invalid');
                }
                $keys[$key] = true;
            }
        }
        foreach ($pdo->query(
            'SELECT storage_key FROM cms_theme_assets ORDER BY theme_id,version,asset_path'
        )->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (!is_string($key) || !preg_match(
                '#^themes/[a-z][a-z0-9.-]{2,79}/[0-9]+\.[0-9]+\.[0-9]+/assets/'
                . '[a-z0-9][a-z0-9/_-]*\.(?:avif|webp|png|jpg)$#D',
                $key
            )) {
                throw new RuntimeException('cms_backup_theme_path_invalid');
            }
            $keys[$key] = true;
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);
        if (count($result) + 1 > self::MAX_FILES) {
            throw new RuntimeException('cms_backup_too_many_files');
        }
        return $result;
    }

    private static function copyOwnedFile(
        string $sourceRoot,
        string $destination,
        string $key,
        array &$records,
        int &$totalBytes
    ): void {
        $source = $sourceRoot . '/' . $key;
        if (is_link($source) || !is_file($source)) {
            throw new RuntimeException('cms_backup_asset_missing');
        }
        $realSource = realpath($source);
        if (!is_string($realSource)
            || !str_starts_with($realSource, $sourceRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('cms_backup_asset_unsafe');
        }
        $target = $destination . '/' . $key;
        self::ensureDirectory(dirname($target), $destination);
        if (is_link($target) || !copy($realSource, $target)) {
            throw new RuntimeException('cms_backup_copy_failed');
        }
        @chmod($target, 0600);
        self::recordFile($target, $key, $records, $totalBytes);
    }

    private static function recordFile(
        string $path,
        string $relative,
        array &$records,
        int &$totalBytes
    ): void {
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if (!is_int($bytes) || $bytes < 1 || !is_string($sha256)
            || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new RuntimeException('cms_backup_file_invalid');
        }
        $totalBytes += $bytes;
        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('cms_backup_too_large');
        }
        $records[] = ['path' => $relative, 'byte_size' => $bytes, 'sha256' => $sha256];
    }

    private static function verifySourceFile(string $root, string $path, array $record): void
    {
        $source = $root . '/' . $path;
        $realSource = realpath($source);
        if (is_link($source) || !is_file($source) || !is_string($realSource)
            || !str_starts_with($realSource, $root . DIRECTORY_SEPARATOR)
            || (int)filesize($source) !== $record['byte_size']
            || !hash_equals($record['sha256'], (string)hash_file('sha256', $source))) {
            throw new RuntimeException('cms_backup_file_corrupt');
        }
    }

    private static function ownedPath(string $path): bool
    {
        return $path === 'cms.sqlite'
            || (bool)preg_match(
                '#^media/[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{64}(?:\.thumb)?\.(?:webp|jpg|png)$#D',
                $path
            )
            || (bool)preg_match(
                '#^themes/[a-z][a-z0-9.-]{2,79}/[0-9]+\.[0-9]+\.[0-9]+/assets/'
                . '[a-z0-9][a-z0-9/_-]*\.(?:avif|webp|png|jpg)$#D',
                $path
            );
    }

    private static function writeManifest(string $directory, array $manifest): void
    {
        $body = json_encode(
            $manifest,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        ) . "\n";
        $temporary = $directory . '/.manifest.tmp';
        $target = $directory . '/manifest.json';
        if (strlen($body) > self::MAX_MANIFEST_BYTES
            || file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)) {
            @unlink($temporary);
            throw new RuntimeException('cms_backup_manifest_write_failed');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('cms_backup_manifest_write_failed');
        }
    }

    private static function backupId(string $prefix): string
    {
        return $prefix . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
    }

    private static function dataRoot(string $dataDirectory): string
    {
        if ($dataDirectory === '' || str_contains($dataDirectory, "\0")
            || is_link($dataDirectory) || !is_dir($dataDirectory)
            || !is_writable($dataDirectory)) {
            throw new RuntimeException('cms_data_directory_unsafe');
        }
        $root = realpath($dataDirectory);
        if (!is_string($root)) {
            throw new RuntimeException('cms_data_directory_unsafe');
        }
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private static function backupRoot(string $dataRoot, ?string $backupDirectory): string
    {
        $path = $backupDirectory ?? ($dataRoot . '/backups');
        if ($path === '' || str_contains($path, "\0") || is_link($path)) {
            throw new RuntimeException('cms_backup_directory_unsafe');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('cms_backup_directory_failed');
        }
        $root = realpath($path);
        if (!is_string($root) || !is_writable($root)) {
            throw new RuntimeException('cms_backup_directory_unsafe');
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        foreach ([$dataRoot, $dataRoot . '/media', $dataRoot . '/themes'] as $forbidden) {
            if ($root === $forbidden
                || str_starts_with($root, $forbidden . DIRECTORY_SEPARATOR)) {
                if ($root !== $dataRoot . '/backups') {
                    throw new RuntimeException('cms_backup_directory_unsafe');
                }
            }
        }
        @chmod($root, 0700);
        return $root;
    }

    private static function freshDirectory(string $path): void
    {
        if (file_exists($path) || is_link($path)
            || !mkdir($path, 0700, false) || !is_dir($path) || is_link($path)) {
            throw new RuntimeException('cms_backup_temporary_directory_failed');
        }
        @chmod($path, 0700);
    }

    private static function ensureDirectory(string $path, string $root): void
    {
        if (is_link($path)
            || (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path))
            || is_link($path)) {
            throw new RuntimeException('cms_backup_directory_failed');
        }
        $realRoot = realpath($root);
        $realPath = realpath($path);
        if (!is_string($realRoot) || !is_string($realPath)
            || ($realPath !== $realRoot
                && !str_starts_with($realPath, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('cms_backup_directory_unsafe');
        }
        @chmod($path, 0700);
    }

    private static function removeTemporary(string $path): void
    {
        $name = basename($path);
        if (!str_starts_with($name, '.tmp-')
            && !str_starts_with($name, '.restore-')
            && !str_starts_with($name, '.rollback-')) {
            throw new RuntimeException('cms_temporary_cleanup_unsafe');
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    private static function exactKeys(array $value, array $keys, string $error): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new RuntimeException($error);
        }
    }

    private static function actor(string $actor): void
    {
        if (!preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $actor)) {
            throw new RuntimeException('cms_backup_actor_invalid');
        }
    }

    private static function audit(
        PDO $pdo,
        string $actor,
        string $action,
        string $resourceId,
        array $details
    ): void {
        $body = json_encode(
            $details,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_logs(
  actor,action,resource_type,resource_id,details_json,created_at)
VALUES(:actor,:action,'backup',:resource_id,:details_json,:created_at)
SQL)->execute([
            ':actor' => $actor,
            ':action' => $action,
            ':resource_id' => $resourceId,
            ':details_json' => $body,
            ':created_at' => time(),
        ]);
    }
}
