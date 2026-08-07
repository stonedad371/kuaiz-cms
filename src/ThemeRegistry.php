<?php
declare(strict_types=1);

/** Versioned storage and activation for validated declarative themes. */
final class KuaizCmsThemeRegistry
{
    private const MAX_ASSET_BYTES = 20971520;

    public static function install(
        PDO $pdo,
        string $manifestJson,
        string $sourceRoot,
        string $storageRoot,
        string $actor,
        string $coreVersion = '0.1.0',
        bool $activate = false
    ): array {
        self::actor($actor);
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $coreVersion)) {
            throw new RuntimeException('cms_core_version_invalid');
        }
        if (!class_exists('KuaizCmsThemeManifest', false)) {
            throw new RuntimeException('theme_manifest_validator_missing');
        }
        $manifest = KuaizCmsThemeManifest::parse($manifestJson);
        self::requireCompatibility($manifest['compatibility']['cms'], $coreVersion);
        $canonical = KuaizCmsThemeManifest::canonicalJson($manifest);
        $digest = hash('sha256', $canonical);
        $themeId = $manifest['id'];
        $version = $manifest['version'];

        $existingStatement = $pdo->prepare(<<<'SQL'
SELECT * FROM cms_themes WHERE theme_id=:theme_id AND version=:version
SQL);
        $existingStatement->execute([':theme_id' => $themeId, ':version' => $version]);
        $existing = $existingStatement->fetch();
        if (is_array($existing)) {
            if (!hash_equals((string)$existing['manifest_sha256'], $digest)) {
                throw new RuntimeException('theme_version_content_changed');
            }
            if ($activate && $existing['status'] !== 'active') {
                self::activate($pdo, $themeId, $version, $actor);
            }
            return [
                'status' => $activate && $existing['status'] !== 'active' ? 'activated' : 'unchanged',
                'theme_id' => $themeId,
                'version' => $version,
                'manifest_sha256' => $digest,
                'assets' => count($manifest['assets']),
            ];
        }
        $newestStatement = $pdo->prepare(
            'SELECT version FROM cms_themes WHERE theme_id=:theme_id'
        );
        $newestStatement->execute([':theme_id' => $themeId]);
        $newestVersion = null;
        foreach ($newestStatement->fetchAll(PDO::FETCH_COLUMN) as $installedVersion) {
            if (is_string($installedVersion)
                && ($newestVersion === null || version_compare($installedVersion, $newestVersion, '>'))) {
                $newestVersion = $installedVersion;
            }
        }
        if (is_string($newestVersion) && version_compare($version, $newestVersion, '<')) {
            throw new RuntimeException('theme_version_downgrade_forbidden');
        }

        $sourceRoot = self::sourceRoot($sourceRoot);
        $storageRoot = self::storageRoot($storageRoot);
        $assetRecords = self::stageAssets(
            $manifest['assets'],
            $sourceRoot,
            $storageRoot,
            $themeId,
            $version
        );
        $now = time();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('theme_install_nested_transaction_forbidden');
        }
        $pdo->beginTransaction();
        try {
            if ($activate) {
                self::requireSlots($pdo, $manifest);
                $pdo->exec("UPDATE cms_themes SET status='installed' WHERE status='active'");
            }
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_themes(
  theme_id,version,name,status,manifest_json,manifest_sha256,installed_at,updated_at)
VALUES(
  :theme_id,:version,:name,:status,:manifest_json,:manifest_sha256,:installed_at,:updated_at)
SQL)->execute([
                ':theme_id' => $themeId,
                ':version' => $version,
                ':name' => $manifest['name'],
                ':status' => $activate ? 'active' : 'installed',
                ':manifest_json' => $canonical,
                ':manifest_sha256' => $digest,
                ':installed_at' => $now,
                ':updated_at' => $now,
            ]);
            $assetStatement = $pdo->prepare(<<<'SQL'
INSERT INTO cms_theme_assets(
  theme_id,version,asset_path,storage_key,media_type,byte_size,sha256,width,height)
VALUES(
  :theme_id,:version,:asset_path,:storage_key,:media_type,:byte_size,:sha256,:width,:height)
SQL);
            foreach ($assetRecords as $asset) {
                $assetStatement->execute([
                    ':theme_id' => $themeId,
                    ':version' => $version,
                    ':asset_path' => $asset['path'],
                    ':storage_key' => $asset['storage_key'],
                    ':media_type' => $asset['media_type'],
                    ':byte_size' => $asset['byte_size'],
                    ':sha256' => $asset['sha256'],
                    ':width' => $asset['width'],
                    ':height' => $asset['height'],
                ]);
            }
            self::audit(
                $pdo,
                $actor,
                $activate ? 'theme.installed_and_activated' : 'theme.installed',
                $themeId . '@' . $version,
                ['assets' => count($assetRecords), 'manifest_sha256' => $digest],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            self::removeStagedAssets($storageRoot, $assetRecords);
            throw $error;
        }
        return [
            'status' => $activate ? 'installed_and_activated' : 'installed',
            'theme_id' => $themeId,
            'version' => $version,
            'manifest_sha256' => $digest,
            'assets' => count($assetRecords),
        ];
    }

    public static function activate(PDO $pdo, string $themeId, string $version, string $actor): array
    {
        self::actor($actor);
        self::identity($themeId, $version);
        $statement = $pdo->prepare(<<<'SQL'
SELECT * FROM cms_themes WHERE theme_id=:theme_id AND version=:version
SQL);
        $statement->execute([':theme_id' => $themeId, ':version' => $version]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('theme_not_installed');
        }
        $manifest = self::verifiedManifest($row);
        if ($pdo->inTransaction()) {
            throw new RuntimeException('theme_activate_nested_transaction_forbidden');
        }
        $now = time();
        $pdo->beginTransaction();
        try {
            self::requireSlots($pdo, $manifest);
            $pdo->exec("UPDATE cms_themes SET status='installed' WHERE status='active'");
            $pdo->prepare(<<<'SQL'
UPDATE cms_themes SET status='active',updated_at=:updated_at
WHERE theme_id=:theme_id AND version=:version
SQL)->execute([
                ':updated_at' => $now,
                ':theme_id' => $themeId,
                ':version' => $version,
            ]);
            self::audit(
                $pdo,
                $actor,
                'theme.activated',
                $themeId . '@' . $version,
                [],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        return ['theme_id' => $themeId, 'version' => $version, 'status' => 'active'];
    }

    public static function active(PDO $pdo): ?array
    {
        $row = $pdo->query("SELECT * FROM cms_themes WHERE status='active' LIMIT 1")->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'theme_id' => (string)$row['theme_id'],
            'version' => (string)$row['version'],
            'manifest_sha256' => (string)$row['manifest_sha256'],
            'manifest' => self::verifiedManifest($row),
        ];
    }

    public static function themes(PDO $pdo): array
    {
        $rows = $pdo->query(<<<'SQL'
SELECT theme_id,version,name,status,manifest_sha256,installed_at,updated_at
FROM cms_themes ORDER BY theme_id ASC,installed_at DESC
SQL)->fetchAll();
        return array_map(static fn(array $row): array => [
            'theme_id' => (string)$row['theme_id'],
            'version' => (string)$row['version'],
            'name' => (string)$row['name'],
            'status' => (string)$row['status'],
            'manifest_sha256' => (string)$row['manifest_sha256'],
            'installed_at' => (int)$row['installed_at'],
            'updated_at' => (int)$row['updated_at'],
        ], $rows);
    }

    public static function asset(
        PDO $pdo,
        string $storageRoot,
        string $themeId,
        string $version,
        string $assetPath
    ): array {
        self::identity($themeId, $version);
        if (!preg_match('#^assets/[a-z0-9][a-z0-9/_-]*\.(?:avif|webp|png|jpg)$#D', $assetPath)) {
            throw new RuntimeException('theme_asset_path_invalid');
        }
        $statement = $pdo->prepare(<<<'SQL'
SELECT a.* FROM cms_theme_assets a
JOIN cms_themes t ON t.theme_id=a.theme_id AND t.version=a.version
WHERE a.theme_id=:theme_id AND a.version=:version AND a.asset_path=:asset_path
SQL);
        $statement->execute([
            ':theme_id' => $themeId,
            ':version' => $version,
            ':asset_path' => $assetPath,
        ]);
        $asset = $statement->fetch();
        if (!is_array($asset)) {
            throw new RuntimeException('theme_asset_not_found');
        }
        $root = self::storageRoot($storageRoot);
        if (!is_string($asset['storage_key'])
            || !preg_match(
                '#^themes/[a-z][a-z0-9.-]{2,79}/[0-9]+\.[0-9]+\.[0-9]+/assets/'
                . '[a-z0-9][a-z0-9/_-]*\.(?:avif|webp|png|jpg)$#D',
                $asset['storage_key']
            )) {
            throw new RuntimeException('theme_asset_storage_key_invalid');
        }
        $path = $root . '/' . $asset['storage_key'];
        $realPath = realpath($path);
        if (is_link($path) || !is_file($path) || !is_string($realPath)
            || !str_starts_with($realPath, $root . DIRECTORY_SEPARATOR)
            || !hash_equals((string)$asset['sha256'], (string)hash_file('sha256', $path))) {
            throw new RuntimeException('theme_asset_storage_corrupt');
        }
        return [
            'path' => $path,
            'media_type' => (string)$asset['media_type'],
            'byte_size' => (int)$asset['byte_size'],
            'sha256' => (string)$asset['sha256'],
        ];
    }

    private static function stageAssets(
        array $assets,
        string $sourceRoot,
        string $storageRoot,
        string $themeId,
        string $version
    ): array {
        $result = [];
        foreach ($assets as $asset) {
            $source = $sourceRoot . '/' . $asset['path'];
            if (is_link($source) || !is_file($source)) {
                throw new RuntimeException('theme_asset_source_unsafe');
            }
            $realSource = realpath($source);
            if (!is_string($realSource)
                || !str_starts_with($realSource, $sourceRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('theme_asset_source_unsafe');
            }
            $bytes = filesize($realSource);
            if (!is_int($bytes) || $bytes < 1 || $bytes > self::MAX_ASSET_BYTES
                || !hash_equals($asset['sha256'], (string)hash_file('sha256', $realSource))) {
                throw new RuntimeException('theme_asset_source_invalid');
            }
            self::verifyAssetContent($realSource, $asset);
            $storageKey = 'themes/' . $themeId . '/' . $version . '/' . $asset['path'];
            $target = $storageRoot . '/' . $storageKey;
            $targetDirectory = dirname($target);
            self::directory($targetDirectory, $storageRoot);
            if (is_link($target)) {
                throw new RuntimeException('theme_asset_target_unsafe');
            }
            if (is_file($target)) {
                if (!hash_equals($asset['sha256'], (string)hash_file('sha256', $target))) {
                    throw new RuntimeException('theme_asset_target_corrupt');
                }
            } else {
                $temporary = tempnam($targetDirectory, 'asset-');
                if (!is_string($temporary) || !copy($realSource, $temporary)) {
                    is_string($temporary) && @unlink($temporary);
                    throw new RuntimeException('theme_asset_copy_failed');
                }
                @chmod($temporary, 0600);
                if (!hash_equals($asset['sha256'], (string)hash_file('sha256', $temporary))
                    || !rename($temporary, $target)) {
                    @unlink($temporary);
                    throw new RuntimeException('theme_asset_copy_failed');
                }
                @chmod($target, 0600);
            }
            $result[] = $asset + [
                'storage_key' => $storageKey,
                'byte_size' => $bytes,
            ];
        }
        return $result;
    }

    private static function verifyAssetContent(string $path, array $asset): void
    {
        $info = @getimagesize($path);
        if (!is_array($info) || ($info['mime'] ?? null) !== $asset['media_type']
            || (int)$info[0] !== $asset['width'] || (int)$info[1] !== $asset['height']) {
            throw new RuntimeException('theme_asset_image_invalid');
        }
    }

    private static function requireSlots(PDO $pdo, array $manifest): void
    {
        $statement = $pdo->prepare(<<<'SQL'
SELECT COUNT(*) FROM cms_theme_slots s
JOIN cms_extensions e ON e.extension_id=s.extension_id AND e.status='active'
WHERE s.slot_key=:slot_key
SQL);
        foreach ($manifest['templates'] as $sections) {
            foreach ($sections as $section) {
                if ($section['component'] !== 'extension_slot') {
                    continue;
                }
                $statement->execute([':slot_key' => $section['options']['slot']]);
                if ((int)$statement->fetchColumn() !== 1) {
                    throw new RuntimeException('theme_extension_slot_unavailable');
                }
            }
        }
    }

    private static function verifiedManifest(array $row): array
    {
        $manifest = KuaizCmsThemeManifest::parse((string)$row['manifest_json']);
        $canonical = KuaizCmsThemeManifest::canonicalJson($manifest);
        if (!hash_equals((string)$row['manifest_sha256'], hash('sha256', $canonical))) {
            throw new RuntimeException('theme_manifest_storage_corrupt');
        }
        return $manifest;
    }

    private static function requireCompatibility(string $range, string $coreVersion): void
    {
        if (!preg_match(
            '/^>=([0-9]+\.[0-9]+\.[0-9]+) <([0-9]+\.[0-9]+\.[0-9]+)$/D',
            $range,
            $parts
        ) || version_compare($coreVersion, $parts[1], '<')
            || !version_compare($coreVersion, $parts[2], '<')) {
            throw new RuntimeException('theme_cms_version_incompatible');
        }
    }

    private static function sourceRoot(string $sourceRoot): string
    {
        if ($sourceRoot === '' || is_link($sourceRoot) || !is_dir($sourceRoot)) {
            throw new RuntimeException('theme_source_root_unsafe');
        }
        $real = realpath($sourceRoot);
        if (!is_string($real)) {
            throw new RuntimeException('theme_source_root_unsafe');
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private static function storageRoot(string $storageRoot): string
    {
        if ($storageRoot === '' || is_link($storageRoot) || !is_dir($storageRoot)
            || !is_writable($storageRoot)) {
            throw new RuntimeException('theme_storage_root_unsafe');
        }
        $real = realpath($storageRoot);
        if (!is_string($real)) {
            throw new RuntimeException('theme_storage_root_unsafe');
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private static function directory(string $path, string $storageRoot): void
    {
        if (is_link($path)
            || (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path))
            || is_link($path) || !is_writable($path)) {
            throw new RuntimeException('theme_asset_directory_unsafe');
        }
        $realPath = realpath($path);
        if (!is_string($realPath)
            || !str_starts_with($realPath, $storageRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('theme_asset_directory_unsafe');
        }
        @chmod($path, 0700);
    }

    private static function removeStagedAssets(string $storageRoot, array $assets): void
    {
        foreach ($assets as $asset) {
            $path = $storageRoot . '/' . $asset['storage_key'];
            is_file($path) && @unlink($path);
        }
    }

    private static function identity(string $themeId, string $version): void
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $themeId)
            || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)) {
            throw new RuntimeException('theme_identity_invalid');
        }
    }

    private static function actor(string $actor): void
    {
        if (!preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $actor)) {
            throw new RuntimeException('theme_actor_invalid');
        }
    }

    private static function audit(
        PDO $pdo,
        string $actor,
        string $action,
        string $resourceId,
        array $details,
        int $now
    ): void {
        $body = KuaizCmsThemeManifest::canonicalJson($details);
        $pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_logs(
  actor,action,resource_type,resource_id,details_json,created_at)
VALUES(:actor,:action,'theme',:resource_id,:details_json,:created_at)
SQL)->execute([
            ':actor' => $actor,
            ':action' => $action,
            ':resource_id' => $resourceId,
            ':details_json' => $body,
            ':created_at' => $now,
        ]);
    }
}
