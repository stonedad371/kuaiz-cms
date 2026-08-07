<?php
declare(strict_types=1);

/** Register declarative extensions without loading extension-owned PHP code. */
final class KuaizCmsExtensionRegistry
{
    public static function activeThemeSlots(PDO $pdo): array
    {
        $rows = $pdo->query(<<<'SQL'
SELECT s.slot_key
FROM cms_theme_slots s
JOIN cms_extensions e
  ON e.extension_id=s.extension_id AND e.status='active'
ORDER BY s.slot_key ASC
SQL)->fetchAll();
        $slots = [];
        foreach ($rows as $row) {
            $slot = is_array($row) ? ($row['slot_key'] ?? null) : null;
            if (!is_string($slot)
                || !preg_match('/^[a-z][a-z0-9.-]{2,119}$/D', $slot)) {
                throw new RuntimeException('extension_theme_slot_storage_corrupt');
            }
            $slots[$slot] = true;
        }
        return $slots;
    }

    public static function installDeclarative(
        PDO $pdo,
        string $manifestJson,
        string $actor,
        string $coreVersion = '0.1.0'
    ): array {
        if (!preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $actor)) {
            throw new RuntimeException('extension_actor_invalid');
        }
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $coreVersion)) {
            throw new RuntimeException('cms_core_version_invalid');
        }
        if (!class_exists('KuaizCmsExtensionManifest', false)) {
            throw new RuntimeException('extension_manifest_validator_missing');
        }
        $manifest = KuaizCmsExtensionManifest::parse($manifestJson);
        if ($manifest['execution'] !== 'declarative') {
            throw new RuntimeException('extension_executable_install_requires_signed_pipeline');
        }
        self::requireCompatibility($manifest['requires'], $coreVersion);
        $canonical = KuaizCmsExtensionManifest::canonicalJson($manifest);
        $digest = hash('sha256', $canonical);
        $extensionId = $manifest['id'];
        $now = time();

        $existingStatement = $pdo->prepare(
            'SELECT * FROM cms_extensions WHERE extension_id=:extension_id'
        );
        $existingStatement->execute([':extension_id' => $extensionId]);
        $existing = $existingStatement->fetch();
        if (is_array($existing)) {
            $comparison = version_compare($manifest['version'], (string)$existing['version']);
            if ($comparison < 0) {
                throw new RuntimeException('extension_version_downgrade_forbidden');
            }
            if ($comparison === 0) {
                if (!hash_equals((string)$existing['manifest_sha256'], $digest)) {
                    throw new RuntimeException('extension_version_content_changed');
                }
                return [
                    'status' => 'unchanged',
                    'extension_id' => $extensionId,
                    'version' => $manifest['version'],
                    'manifest_sha256' => $digest,
                ];
            }
        }

        if ($pdo->inTransaction()) {
            throw new RuntimeException('extension_install_nested_transaction_forbidden');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_extensions(
  extension_id,name,version,type,execution,status,manifest_json,manifest_sha256,
  installed_at,updated_at)
VALUES(
  :extension_id,:name,:version,:type,:execution,'active',:manifest_json,
  :manifest_sha256,:installed_at,:updated_at)
ON CONFLICT(extension_id) DO UPDATE SET
  name=excluded.name,
  version=excluded.version,
  type=excluded.type,
  execution=excluded.execution,
  status='active',
  manifest_json=excluded.manifest_json,
  manifest_sha256=excluded.manifest_sha256,
  updated_at=excluded.updated_at
SQL)->execute([
                ':extension_id' => $extensionId,
                ':name' => $manifest['name'],
                ':version' => $manifest['version'],
                ':type' => $manifest['type'],
                ':execution' => $manifest['execution'],
                ':manifest_json' => $canonical,
                ':manifest_sha256' => $digest,
                ':installed_at' => $now,
                ':updated_at' => $now,
            ]);

            $pdo->prepare('DELETE FROM cms_routes WHERE extension_id=:extension_id')
                ->execute([':extension_id' => $extensionId]);
            self::syncContentTypes($pdo, $extensionId, $manifest['content_types'], $now);
            $pdo->prepare(
                'DELETE FROM cms_extension_permissions WHERE extension_id=:extension_id'
            )->execute([':extension_id' => $extensionId]);
            $permissionStatement = $pdo->prepare(
                'INSERT INTO cms_extension_permissions(extension_id,permission) '
                . 'VALUES(:extension_id,:permission)'
            );
            foreach ($manifest['permissions'] as $permission) {
                $permissionStatement->execute([
                    ':extension_id' => $extensionId,
                    ':permission' => $permission,
                ]);
            }

            self::syncRoutes($pdo, $extensionId, $manifest['routes']);
            $pdo->prepare(
                'DELETE FROM cms_theme_slots WHERE extension_id=:extension_id'
            )->execute([':extension_id' => $extensionId]);
            $slotStatement = $pdo->prepare(
                'INSERT INTO cms_theme_slots(extension_id,slot_key) '
                . 'VALUES(:extension_id,:slot_key)'
            );
            foreach ($manifest['theme_slots'] as $slot) {
                $slotStatement->execute([
                    ':extension_id' => $extensionId,
                    ':slot_key' => $slot,
                ]);
            }

            $details = KuaizCmsExtensionManifest::canonicalJson([
                'execution' => $manifest['execution'],
                'manifest_sha256' => $digest,
                'previous_version' => is_array($existing) ? (string)$existing['version'] : '',
                'version' => $manifest['version'],
            ]);
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_logs(
  actor,action,resource_type,resource_id,details_json,created_at)
VALUES(:actor,:action,'extension',:resource_id,:details_json,:created_at)
SQL)->execute([
                ':actor' => $actor,
                ':action' => is_array($existing) ? 'extension.updated' : 'extension.installed',
                ':resource_id' => $extensionId,
                ':details_json' => $details,
                ':created_at' => $now,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }

        return [
            'status' => is_array($existing) ? 'updated' : 'installed',
            'extension_id' => $extensionId,
            'version' => $manifest['version'],
            'manifest_sha256' => $digest,
            'content_types' => count($manifest['content_types']),
            'routes' => count($manifest['routes']),
        ];
    }

    private static function syncContentTypes(
        PDO $pdo,
        string $extensionId,
        array $contentTypes,
        int $now
    ): void {
        $existingStatement = $pdo->prepare(
            'SELECT id,type_key FROM cms_content_types WHERE extension_id=:extension_id'
        );
        $existingStatement->execute([':extension_id' => $extensionId]);
        $existing = [];
        foreach ($existingStatement->fetchAll() as $row) {
            $existing[(string)$row['type_key']] = (int)$row['id'];
        }
        $desired = array_fill_keys(
            array_map(static fn(array $item): string => $item['id'], $contentTypes),
            true
        );
        foreach ($existing as $typeKey => $contentTypeId) {
            if (isset($desired[$typeKey])) {
                continue;
            }
            $used = $pdo->prepare(
                'SELECT COUNT(*) FROM cms_entries WHERE content_type_id=:content_type_id'
            );
            $used->execute([':content_type_id' => $contentTypeId]);
            if ((int)$used->fetchColumn() > 0) {
                throw new RuntimeException('extension_content_type_removal_has_entries');
            }
            $pdo->prepare(
                'DELETE FROM cms_content_types WHERE id=:content_type_id'
            )->execute([':content_type_id' => $contentTypeId]);
        }

        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO cms_content_types(
  extension_id,type_key,label,route_slug,schema_json,schema_sha256,created_at,updated_at)
VALUES(
  :extension_id,:type_key,:label,:route_slug,:schema_json,:schema_sha256,
  :created_at,:updated_at)
ON CONFLICT(extension_id,type_key) DO UPDATE SET
  label=excluded.label,
  route_slug=excluded.route_slug,
  schema_json=excluded.schema_json,
  schema_sha256=excluded.schema_sha256,
  updated_at=excluded.updated_at
SQL);
        foreach ($contentTypes as $contentType) {
            $schemaJson = KuaizCmsExtensionManifest::canonicalJson($contentType);
            $statement->execute([
                ':extension_id' => $extensionId,
                ':type_key' => $contentType['id'],
                ':label' => $contentType['label'],
                ':route_slug' => $contentType['route'],
                ':schema_json' => $schemaJson,
                ':schema_sha256' => hash('sha256', $schemaJson),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    }

    private static function syncRoutes(PDO $pdo, string $extensionId, array $routes): void
    {
        $typeStatement = $pdo->prepare(
            'SELECT id FROM cms_content_types '
            . 'WHERE extension_id=:extension_id AND type_key=:type_key'
        );
        $routeStatement = $pdo->prepare(<<<'SQL'
INSERT INTO cms_routes(
  extension_id,route_id,route_path,methods_json,access,content_type_id)
VALUES(
  :extension_id,:route_id,:route_path,:methods_json,:access,:content_type_id)
SQL);
        foreach ($routes as $route) {
            $contentTypeId = null;
            if ($route['content_type'] !== null) {
                $typeStatement->execute([
                    ':extension_id' => $extensionId,
                    ':type_key' => $route['content_type'],
                ]);
                $contentTypeId = $typeStatement->fetchColumn();
                if ($contentTypeId === false) {
                    throw new RuntimeException('extension_route_content_type_not_registered');
                }
            }
            $methodsJson = KuaizCmsExtensionManifest::canonicalJson($route['methods']);
            $routeStatement->execute([
                ':extension_id' => $extensionId,
                ':route_id' => $route['id'],
                ':route_path' => $route['path'],
                ':methods_json' => $methodsJson,
                ':access' => $route['access'],
                ':content_type_id' => $contentTypeId,
            ]);
        }
    }

    private static function requireCompatibility(array $requires, string $coreVersion): void
    {
        if (!preg_match(
            '/^>=([0-9]+\.[0-9]+\.[0-9]+) <([0-9]+\.[0-9]+\.[0-9]+)$/D',
            $requires['cms'],
            $cms
        ) || version_compare($coreVersion, $cms[1], '<')
            || !version_compare($coreVersion, $cms[2], '<')) {
            throw new RuntimeException('extension_cms_version_incompatible');
        }
        if (!preg_match('/^>=([0-9]+\.[0-9]+)$/D', $requires['php'], $php)
            || version_compare(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $php[1], '<')) {
            throw new RuntimeException('extension_php_version_incompatible');
        }
    }
}
