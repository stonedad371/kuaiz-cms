<?php
declare(strict_types=1);

/** Immutable content revisions for core and declarative extension content types. */
final class KuaizCmsContentRepository
{
    private const MAX_PAYLOAD_BYTES = 131072;

    public static function save(
        PDO $pdo,
        string $extensionId,
        string $typeKey,
        string $slug,
        array $payload,
        string $actor,
        bool $publish = false
    ): array {
        self::identity($extensionId, $typeKey, $slug, $actor);
        $contentType = self::contentType($pdo, $extensionId, $typeKey);
        $normalized = self::payload($pdo, $contentType, $payload);
        $payloadJson = self::canonicalJson($normalized);
        if (strlen($payloadJson) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('cms_content_payload_too_large');
        }
        $payloadSha256 = hash('sha256', $payloadJson);
        $now = time();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('cms_content_nested_transaction_forbidden');
        }
        $pdo->beginTransaction();
        try {
            $entryStatement = $pdo->prepare(
                'SELECT * FROM cms_entries '
                . 'WHERE content_type_id=:content_type_id AND slug=:slug'
            );
            $entryStatement->execute([
                ':content_type_id' => $contentType['id'],
                ':slug' => $slug,
            ]);
            $entry = $entryStatement->fetch();
            if (!is_array($entry)) {
                $pdo->prepare(<<<'SQL'
INSERT INTO cms_entries(
  content_type_id,slug,status,current_revision_id,published_revision_id,created_at,updated_at)
VALUES(:content_type_id,:slug,'draft',NULL,NULL,:created_at,:updated_at)
SQL)->execute([
                    ':content_type_id' => $contentType['id'],
                    ':slug' => $slug,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $entryId = (int)$pdo->lastInsertId();
                $publishedRevisionId = null;
                $created = true;
            } else {
                $entryId = (int)$entry['id'];
                $publishedRevisionId = $entry['published_revision_id'] === null
                    ? null : (int)$entry['published_revision_id'];
                $created = false;
            }
            $versionStatement = $pdo->prepare(
                'SELECT COALESCE(MAX(version),0)+1 FROM cms_entry_revisions '
                . 'WHERE entry_id=:entry_id'
            );
            $versionStatement->execute([':entry_id' => $entryId]);
            $version = (int)$versionStatement->fetchColumn();
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_entry_revisions(
  entry_id,version,payload_json,payload_sha256,actor,created_at)
VALUES(:entry_id,:version,:payload_json,:payload_sha256,:actor,:created_at)
SQL)->execute([
                ':entry_id' => $entryId,
                ':version' => $version,
                ':payload_json' => $payloadJson,
                ':payload_sha256' => $payloadSha256,
                ':actor' => $actor,
                ':created_at' => $now,
            ]);
            $revisionId = (int)$pdo->lastInsertId();
            self::recordRevisionMedia(
                $pdo,
                $revisionId,
                $contentType['schema'],
                $normalized
            );
            $status = ($publish || $publishedRevisionId !== null) ? 'published' : 'draft';
            $nextPublished = $publish ? $revisionId : $publishedRevisionId;
            $pdo->prepare(<<<'SQL'
UPDATE cms_entries
SET status=:status,current_revision_id=:current_revision_id,
    published_revision_id=:published_revision_id,updated_at=:updated_at
WHERE id=:entry_id
SQL)->execute([
                ':status' => $status,
                ':current_revision_id' => $revisionId,
                ':published_revision_id' => $nextPublished,
                ':updated_at' => $now,
                ':entry_id' => $entryId,
            ]);
            self::audit(
                $pdo,
                $actor,
                $publish ? 'content.published' : ($created ? 'content.created' : 'content.updated'),
                (string)$entryId,
                [
                    'content_type' => $extensionId . ':' . $typeKey,
                    'payload_sha256' => $payloadSha256,
                    'revision_id' => $revisionId,
                    'slug' => $slug,
                    'version' => $version,
                ],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        return [
            'entry_id' => $entryId,
            'revision_id' => $revisionId,
            'version' => $version,
            'status' => $status,
            'published' => $publish,
            'payload_sha256' => $payloadSha256,
        ];
    }

    public static function restore(
        PDO $pdo,
        int $entryId,
        int $revisionId,
        string $actor,
        bool $publish = false
    ): array {
        if ($entryId < 1 || $revisionId < 1) {
            throw new RuntimeException('cms_content_restore_identity_invalid');
        }
        $statement = $pdo->prepare(<<<'SQL'
SELECT e.slug,ct.extension_id,ct.type_key,r.payload_json
FROM cms_entries e
JOIN cms_content_types ct ON ct.id=e.content_type_id
JOIN cms_entry_revisions r ON r.entry_id=e.id
WHERE e.id=:entry_id AND r.id=:revision_id
SQL);
        $statement->execute([':entry_id' => $entryId, ':revision_id' => $revisionId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('cms_content_revision_not_found');
        }
        try {
            $payload = json_decode((string)$row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('cms_content_revision_corrupt', 0, $error);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('cms_content_revision_corrupt');
        }
        return self::save(
            $pdo,
            (string)$row['extension_id'],
            (string)$row['type_key'],
            (string)$row['slug'],
            $payload,
            $actor,
            $publish
        );
    }

    public static function published(
        PDO $pdo,
        string $extensionId,
        string $typeKey,
        string $slug
    ): ?array {
        self::identity($extensionId, $typeKey, $slug, 'reader:public');
        $statement = $pdo->prepare(<<<'SQL'
SELECT e.id AS entry_id,e.slug,r.id AS revision_id,r.version,
       r.payload_json,r.payload_sha256,r.created_at
FROM cms_entries e
JOIN cms_content_types ct ON ct.id=e.content_type_id
JOIN cms_entry_revisions r ON r.id=e.published_revision_id AND r.entry_id=e.id
JOIN cms_extensions x ON x.extension_id=ct.extension_id AND x.status='active'
WHERE ct.extension_id=:extension_id AND ct.type_key=:type_key
  AND e.slug=:slug AND e.status='published'
SQL);
        $statement->execute([
            ':extension_id' => $extensionId,
            ':type_key' => $typeKey,
            ':slug' => $slug,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        try {
            $payload = json_decode((string)$row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('cms_published_content_corrupt', 0, $error);
        }
        if (!is_array($payload) || array_is_list($payload)
            || !hash_equals((string)$row['payload_sha256'], hash('sha256', self::canonicalJson($payload)))) {
            throw new RuntimeException('cms_published_content_corrupt');
        }
        return [
            'entry_id' => (int)$row['entry_id'],
            'revision_id' => (int)$row['revision_id'],
            'version' => (int)$row['version'],
            'slug' => (string)$row['slug'],
            'payload' => $payload,
            'payload_sha256' => (string)$row['payload_sha256'],
            'published_at' => (int)$row['created_at'],
        ];
    }

    public static function publishedList(
        PDO $pdo,
        string $extensionId,
        string $typeKey,
        int $limit = 100,
        int $offset = 0
    ): array {
        if (!preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $extensionId)
            || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $typeKey)) {
            throw new RuntimeException('cms_content_identity_invalid');
        }
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            throw new RuntimeException('cms_content_pagination_invalid');
        }
        $statement = $pdo->prepare(<<<'SQL'
SELECT e.id AS entry_id,e.slug,e.updated_at,r.id AS revision_id,r.version,
       r.payload_json,r.payload_sha256,r.created_at AS published_at
FROM cms_entries e
JOIN cms_content_types ct ON ct.id=e.content_type_id
JOIN cms_extensions x ON x.extension_id=ct.extension_id AND x.status='active'
JOIN cms_entry_revisions r ON r.id=e.published_revision_id AND r.entry_id=e.id
WHERE ct.extension_id=:extension_id AND ct.type_key=:type_key
  AND e.status='published'
ORDER BY e.updated_at DESC,e.id DESC
LIMIT :limit OFFSET :offset
SQL);
        $statement->bindValue(':extension_id', $extensionId, PDO::PARAM_STR);
        $statement->bindValue(':type_key', $typeKey, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('cms_published_content_corrupt');
            }
            $result[] = [
                'entry_id' => (int)$row['entry_id'],
                'revision_id' => (int)$row['revision_id'],
                'version' => (int)$row['version'],
                'slug' => (string)$row['slug'],
                'payload' => self::verifiedJson(
                    (string)$row['payload_json'],
                    (string)$row['payload_sha256'],
                    'cms_published_content_corrupt'
                ),
                'payload_sha256' => (string)$row['payload_sha256'],
                'published_at' => (int)$row['published_at'],
                'updated_at' => (int)$row['updated_at'],
            ];
        }
        return $result;
    }

    public static function publicContentTypes(PDO $pdo): array
    {
        $rows = $pdo->query(<<<'SQL'
SELECT ct.extension_id,ct.type_key,ct.label,ct.route_slug,
       COUNT(e.id) AS published_count
FROM cms_content_types ct
JOIN cms_extensions x ON x.extension_id=ct.extension_id AND x.status='active'
LEFT JOIN cms_entries e
  ON e.content_type_id=ct.id AND e.status='published' AND e.published_revision_id IS NOT NULL
GROUP BY ct.id
ORDER BY ct.label ASC,ct.extension_id ASC,ct.type_key ASC
SQL)->fetchAll();
        return array_map(static fn(array $row): array => [
            'extension_id' => (string)$row['extension_id'],
            'type_key' => (string)$row['type_key'],
            'label' => (string)$row['label'],
            'route_slug' => (string)$row['route_slug'],
            'published_count' => (int)$row['published_count'],
        ], $rows);
    }

    public static function contentTypes(PDO $pdo): array
    {
        $rows = $pdo->query(<<<'SQL'
SELECT ct.id,ct.extension_id,ct.type_key,ct.label,ct.route_slug,
       ct.schema_json,ct.schema_sha256,ct.created_at,ct.updated_at,
       COUNT(e.id) AS entry_count
FROM cms_content_types ct
JOIN cms_extensions x ON x.extension_id=ct.extension_id AND x.status='active'
LEFT JOIN cms_entries e ON e.content_type_id=ct.id
GROUP BY ct.id
ORDER BY ct.label ASC,ct.extension_id ASC,ct.type_key ASC
SQL)->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('cms_content_type_query_failed');
            }
            $schema = self::verifiedJson(
                (string)$row['schema_json'],
                (string)$row['schema_sha256'],
                'cms_content_type_schema_corrupt'
            );
            $result[] = [
                'id' => (int)$row['id'],
                'extension_id' => (string)$row['extension_id'],
                'type_key' => (string)$row['type_key'],
                'label' => (string)$row['label'],
                'route_slug' => (string)$row['route_slug'],
                'schema' => $schema,
                'entry_count' => (int)$row['entry_count'],
                'created_at' => (int)$row['created_at'],
                'updated_at' => (int)$row['updated_at'],
            ];
        }
        return $result;
    }

    public static function adminEntries(
        PDO $pdo,
        ?string $extensionId = null,
        ?string $typeKey = null,
        ?string $status = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        if ($extensionId !== null
            && !preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $extensionId)) {
            throw new RuntimeException('cms_content_filter_invalid');
        }
        if ($typeKey !== null && !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $typeKey)) {
            throw new RuntimeException('cms_content_filter_invalid');
        }
        if ($status !== null && !in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new RuntimeException('cms_content_filter_invalid');
        }
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            throw new RuntimeException('cms_content_pagination_invalid');
        }
        $where = [];
        $params = [];
        if ($extensionId !== null) {
            $where[] = 'ct.extension_id=:extension_id';
            $params[':extension_id'] = $extensionId;
        }
        if ($typeKey !== null) {
            $where[] = 'ct.type_key=:type_key';
            $params[':type_key'] = $typeKey;
        }
        if ($status !== null) {
            $where[] = 'e.status=:status';
            $params[':status'] = $status;
        }
        $sql = <<<'SQL'
SELECT e.id,e.slug,e.status,e.current_revision_id,e.published_revision_id,
       e.created_at,e.updated_at,ct.extension_id,ct.type_key,ct.label,
       current.version AS current_version,current.payload_json AS current_payload_json,
       current.payload_sha256 AS current_payload_sha256,
       published.version AS published_version
FROM cms_entries e
JOIN cms_content_types ct ON ct.id=e.content_type_id
JOIN cms_extensions x ON x.extension_id=ct.extension_id AND x.status='active'
LEFT JOIN cms_entry_revisions current
  ON current.id=e.current_revision_id AND current.entry_id=e.id
LEFT JOIN cms_entry_revisions published
  ON published.id=e.published_revision_id AND published.entry_id=e.id
SQL;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.updated_at DESC,e.id DESC LIMIT :limit OFFSET :offset';
        $statement = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row) || $row['current_revision_id'] === null) {
                throw new RuntimeException('cms_content_entry_corrupt');
            }
            $payload = self::verifiedJson(
                (string)$row['current_payload_json'],
                (string)$row['current_payload_sha256'],
                'cms_content_entry_corrupt'
            );
            $result[] = [
                'id' => (int)$row['id'],
                'extension_id' => (string)$row['extension_id'],
                'type_key' => (string)$row['type_key'],
                'type_label' => (string)$row['label'],
                'slug' => (string)$row['slug'],
                'status' => (string)$row['status'],
                'current_revision_id' => (int)$row['current_revision_id'],
                'current_version' => (int)$row['current_version'],
                'published_revision_id' => $row['published_revision_id'] === null
                    ? null : (int)$row['published_revision_id'],
                'published_version' => $row['published_version'] === null
                    ? null : (int)$row['published_version'],
                'has_unpublished_changes' => $row['published_revision_id'] !== null
                    && (int)$row['current_revision_id'] !== (int)$row['published_revision_id'],
                'payload' => $payload,
                'created_at' => (int)$row['created_at'],
                'updated_at' => (int)$row['updated_at'],
            ];
        }
        return $result;
    }

    public static function history(PDO $pdo, int $entryId): array
    {
        if ($entryId < 1) {
            throw new RuntimeException('cms_content_entry_identity_invalid');
        }
        $statement = $pdo->prepare(<<<'SQL'
SELECT r.id,r.version,r.payload_json,r.payload_sha256,r.actor,r.created_at,
       e.current_revision_id,e.published_revision_id
FROM cms_entry_revisions r
JOIN cms_entries e ON e.id=r.entry_id
WHERE r.entry_id=:entry_id
ORDER BY r.version DESC
SQL);
        $statement->execute([':entry_id' => $entryId]);
        $rows = $statement->fetchAll();
        if ($rows === []) {
            throw new RuntimeException('cms_content_entry_not_found');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('cms_content_revision_corrupt');
            }
            $result[] = [
                'id' => (int)$row['id'],
                'version' => (int)$row['version'],
                'payload' => self::verifiedJson(
                    (string)$row['payload_json'],
                    (string)$row['payload_sha256'],
                    'cms_content_revision_corrupt'
                ),
                'payload_sha256' => (string)$row['payload_sha256'],
                'actor' => (string)$row['actor'],
                'created_at' => (int)$row['created_at'],
                'is_current' => (int)$row['id'] === (int)$row['current_revision_id'],
                'is_published' => $row['published_revision_id'] !== null
                    && (int)$row['id'] === (int)$row['published_revision_id'],
            ];
        }
        return $result;
    }

    public static function adminEntry(PDO $pdo, int $entryId): array
    {
        if ($entryId < 1) {
            throw new RuntimeException('cms_content_entry_identity_invalid');
        }
        $statement = $pdo->prepare(<<<'SQL'
SELECT e.id,e.slug,e.status,e.current_revision_id,e.published_revision_id,
       e.created_at,e.updated_at,ct.extension_id,ct.type_key,ct.label,
       ct.schema_json,ct.schema_sha256,r.version,r.payload_json,r.payload_sha256
FROM cms_entries e
JOIN cms_content_types ct ON ct.id=e.content_type_id
JOIN cms_extensions x ON x.extension_id=ct.extension_id AND x.status='active'
JOIN cms_entry_revisions r ON r.id=e.current_revision_id AND r.entry_id=e.id
WHERE e.id=:entry_id
SQL);
        $statement->execute([':entry_id' => $entryId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('cms_content_entry_not_found');
        }
        return [
            'id' => (int)$row['id'],
            'extension_id' => (string)$row['extension_id'],
            'type_key' => (string)$row['type_key'],
            'type_label' => (string)$row['label'],
            'schema' => self::verifiedJson(
                (string)$row['schema_json'],
                (string)$row['schema_sha256'],
                'cms_content_type_schema_corrupt'
            ),
            'slug' => (string)$row['slug'],
            'status' => (string)$row['status'],
            'current_revision_id' => (int)$row['current_revision_id'],
            'current_version' => (int)$row['version'],
            'published_revision_id' => $row['published_revision_id'] === null
                ? null : (int)$row['published_revision_id'],
            'payload' => self::verifiedJson(
                (string)$row['payload_json'],
                (string)$row['payload_sha256'],
                'cms_content_entry_corrupt'
            ),
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }

    public static function publish(PDO $pdo, int $entryId, string $actor): array
    {
        return self::changeState($pdo, $entryId, $actor, 'publish');
    }

    public static function unpublish(PDO $pdo, int $entryId, string $actor): array
    {
        return self::changeState($pdo, $entryId, $actor, 'unpublish');
    }

    public static function archive(PDO $pdo, int $entryId, string $actor): array
    {
        return self::changeState($pdo, $entryId, $actor, 'archive');
    }

    public static function restoreArchived(PDO $pdo, int $entryId, string $actor): array
    {
        return self::changeState($pdo, $entryId, $actor, 'restore');
    }

    private static function changeState(
        PDO $pdo,
        int $entryId,
        string $actor,
        string $operation
    ): array {
        if ($entryId < 1 || !preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $actor)
            || !in_array($operation, ['publish', 'unpublish', 'archive', 'restore'], true)) {
            throw new RuntimeException('cms_content_state_change_invalid');
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('cms_content_nested_transaction_forbidden');
        }
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT id,status,current_revision_id,published_revision_id '
                . 'FROM cms_entries WHERE id=:entry_id'
            );
            $statement->execute([':entry_id' => $entryId]);
            $entry = $statement->fetch();
            if (!is_array($entry) || $entry['current_revision_id'] === null) {
                throw new RuntimeException('cms_content_entry_not_found');
            }
            $status = (string)$entry['status'];
            $publishedRevisionId = $entry['published_revision_id'] === null
                ? null : (int)$entry['published_revision_id'];
            $action = '';
            if ($operation === 'publish') {
                if ($status === 'archived') {
                    throw new RuntimeException('cms_content_archived_publish_forbidden');
                }
                $status = 'published';
                $publishedRevisionId = (int)$entry['current_revision_id'];
                $action = 'content.published';
            } elseif ($operation === 'unpublish') {
                if ($status === 'archived') {
                    throw new RuntimeException('cms_content_archived_unpublish_forbidden');
                }
                $status = 'draft';
                $publishedRevisionId = null;
                $action = 'content.unpublished';
            } elseif ($operation === 'archive') {
                $status = 'archived';
                $publishedRevisionId = null;
                $action = 'content.archived';
            } else {
                if ($status !== 'archived') {
                    throw new RuntimeException('cms_content_not_archived');
                }
                $status = 'draft';
                $publishedRevisionId = null;
                $action = 'content.archive_restored';
            }
            $now = time();
            $pdo->prepare(<<<'SQL'
UPDATE cms_entries
SET status=:status,published_revision_id=:published_revision_id,updated_at=:updated_at
WHERE id=:entry_id
SQL)->execute([
                ':status' => $status,
                ':published_revision_id' => $publishedRevisionId,
                ':updated_at' => $now,
                ':entry_id' => $entryId,
            ]);
            self::audit(
                $pdo,
                $actor,
                $action,
                (string)$entryId,
                [
                    'current_revision_id' => (int)$entry['current_revision_id'],
                    'published_revision_id' => $publishedRevisionId,
                    'status' => $status,
                ],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        return [
            'entry_id' => $entryId,
            'status' => $status,
            'current_revision_id' => (int)$entry['current_revision_id'],
            'published_revision_id' => $publishedRevisionId,
        ];
    }

    private static function contentType(PDO $pdo, string $extensionId, string $typeKey): array
    {
        $statement = $pdo->prepare(<<<'SQL'
SELECT ct.* FROM cms_content_types ct
JOIN cms_extensions x ON x.extension_id=ct.extension_id AND x.status='active'
WHERE ct.extension_id=:extension_id AND ct.type_key=:type_key
SQL);
        $statement->execute([':extension_id' => $extensionId, ':type_key' => $typeKey]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('cms_content_type_not_found');
        }
        try {
            $schema = json_decode((string)$row['schema_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('cms_content_type_schema_corrupt', 0, $error);
        }
        if (!is_array($schema) || array_is_list($schema)
            || !hash_equals((string)$row['schema_sha256'], hash('sha256', self::canonicalJson($schema)))) {
            throw new RuntimeException('cms_content_type_schema_corrupt');
        }
        $row['schema'] = $schema;
        return $row;
    }

    private static function payload(PDO $pdo, array $contentType, array $payload): array
    {
        if (array_is_list($payload)) {
            throw new RuntimeException('cms_content_payload_object_required');
        }
        $fields = $contentType['schema']['fields'] ?? null;
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new RuntimeException('cms_content_type_schema_corrupt');
        }
        $known = [];
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['key'], $field['type'], $field['required'])) {
                throw new RuntimeException('cms_content_type_schema_corrupt');
            }
            $key = (string)$field['key'];
            $known[$key] = true;
            if (!array_key_exists($key, $payload)) {
                if ($field['required']) {
                    throw new RuntimeException('cms_content_required_field_missing:' . $key);
                }
                continue;
            }
            $normalized[$key] = self::fieldValue(
                $pdo,
                (string)$field['type'],
                $payload[$key],
                (bool)$field['required'],
                $key
            );
        }
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !isset($known[$key])) {
                throw new RuntimeException('cms_content_unknown_field');
            }
        }
        return $normalized;
    }

    private static function fieldValue(
        PDO $pdo,
        string $type,
        mixed $value,
        bool $required,
        string $key
    ): mixed {
        if ($type === 'text' || $type === 'long_text') {
            if (!is_string($value) || !preg_match('//u', $value)
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                throw new RuntimeException('cms_content_field_invalid:' . $key);
            }
            $value = trim($value);
            $maximum = $type === 'text' ? 2000 : 50000;
            if (($required && $value === '') || strlen($value) > $maximum * 4) {
                throw new RuntimeException('cms_content_field_invalid:' . $key);
            }
            return $value;
        }
        if ($type === 'number') {
            if ((!is_int($value) && !is_float($value)) || is_nan((float)$value)
                || is_infinite((float)$value) || abs((float)$value) > 1000000000000) {
                throw new RuntimeException('cms_content_field_invalid:' . $key);
            }
            return $value;
        }
        if ($type === 'boolean') {
            if (!is_bool($value)) {
                throw new RuntimeException('cms_content_field_invalid:' . $key);
            }
            return $value;
        }
        if ($type === 'date') {
            if (!is_string($value)
                || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
                || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
                throw new RuntimeException('cms_content_field_invalid:' . $key);
            }
            return $value;
        }
        if ($type === 'datetime') {
            if (!is_string($value)
                || !preg_match(
                    '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                    . '(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',
                    $value
                )) {
                throw new RuntimeException('cms_content_field_invalid:' . $key);
            }
            try {
                new DateTimeImmutable($value);
            } catch (Exception $error) {
                throw new RuntimeException('cms_content_field_invalid:' . $key, 0, $error);
            }
            return $value;
        }
        if ($type === 'image') {
            if (!is_int($value) || $value < 1) {
                throw new RuntimeException('cms_content_field_invalid:' . $key);
            }
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM cms_media WHERE id=:media_id AND status='active'"
            );
            $statement->execute([':media_id' => $value]);
            if ((int)$statement->fetchColumn() !== 1) {
                throw new RuntimeException('cms_content_media_not_found:' . $key);
            }
            return $value;
        }
        if ($type === 'url') {
            if (!is_string($value) || strlen($value) > 2048
                || ($required && $value === '')) {
                throw new RuntimeException('cms_content_field_invalid:' . $key);
            }
            if ($value === '') {
                return '';
            }
            $parts = parse_url($value);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new RuntimeException('cms_content_field_invalid:' . $key);
            }
            return $value;
        }
        throw new RuntimeException('cms_content_field_type_unsupported:' . $key);
    }

    private static function recordRevisionMedia(
        PDO $pdo,
        int $revisionId,
        array $schema,
        array $payload
    ): void {
        $fields = $schema['fields'] ?? null;
        if (!is_array($fields)) {
            throw new RuntimeException('cms_content_type_schema_corrupt');
        }
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO cms_revision_media(revision_id,media_id,field_key)
VALUES(:revision_id,:media_id,:field_key)
SQL);
        foreach ($fields as $field) {
            if (!is_array($field) || ($field['type'] ?? null) !== 'image') {
                continue;
            }
            $key = $field['key'] ?? null;
            $mediaId = is_string($key) ? ($payload[$key] ?? null) : null;
            if ($mediaId === null) {
                continue;
            }
            if (!is_int($mediaId) || $mediaId < 1) {
                throw new RuntimeException('cms_content_media_reference_invalid');
            }
            $statement->execute([
                ':revision_id' => $revisionId,
                ':media_id' => $mediaId,
                ':field_key' => $key,
            ]);
        }
    }

    private static function identity(
        string $extensionId,
        string $typeKey,
        string $slug,
        string $actor
    ): void {
        if (!preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $extensionId)
            || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $typeKey)
            || !preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/D', $slug)
            || !preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $actor)) {
            throw new RuntimeException('cms_content_identity_invalid');
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
        $pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_logs(
  actor,action,resource_type,resource_id,details_json,created_at)
VALUES(:actor,:action,'content',:resource_id,:details_json,:created_at)
SQL)->execute([
            ':actor' => $actor,
            ':action' => $action,
            ':resource_id' => $resourceId,
            ':details_json' => self::canonicalJson($details),
            ':created_at' => $now,
        ]);
    }

    private static function canonicalJson(array $value): string
    {
        $body = json_encode(
            self::canonicalValue($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (!is_string($body)) {
            throw new RuntimeException('cms_content_json_encode_failed');
        }
        return $body;
    }

    private static function verifiedJson(
        string $body,
        string $expectedSha256,
        string $errorCode
    ): array {
        try {
            $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($errorCode, 0, $error);
        }
        if (!is_array($value) || array_is_list($value)
            || !hash_equals($expectedSha256, hash('sha256', self::canonicalJson($value)))) {
            throw new RuntimeException($errorCode);
        }
        return $value;
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalValue'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalValue($item);
        }
        return $value;
    }
}
