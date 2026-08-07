<?php
declare(strict_types=1);

/** SQLite foundation for the independent Kuaiz CMS community distribution. */
final class KuaizCmsDatabase
{
    public const RUNTIME_PROFILE = 'community-php-sqlite-v1';
    public const SCHEMA_VERSION = 5;
    private const MAX_DATABASE_BYTES = 1073741824;

    public static function connect(string $database): PDO
    {
        if ($database === '' || is_link($database)) {
            throw new RuntimeException('cms_database_path_unsafe');
        }
        $parent = dirname($database);
        if (!is_dir($parent) || is_link($parent) || !is_writable($parent)) {
            throw new RuntimeException('cms_database_directory_unsafe');
        }
        if (is_file($database) && filesize($database) > self::MAX_DATABASE_BYTES) {
            throw new RuntimeException('cms_database_too_large');
        }
        if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('cms_pdo_sqlite_missing');
        }
        $pdo = new PDO('sqlite:' . $database, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        @chmod($database, 0600);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=10000');
        $pdo->exec('PRAGMA synchronous=FULL');
        $mode = strtolower((string)$pdo->query('PRAGMA journal_mode=DELETE')->fetchColumn());
        if ($mode !== 'delete') {
            throw new RuntimeException('cms_database_journal_mode_unavailable');
        }
        $current = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($current > self::SCHEMA_VERSION) {
            throw new RuntimeException('cms_database_schema_newer_than_core');
        }
        if ($current < self::SCHEMA_VERSION) {
            $migrationLock = self::migrationLock($parent);
            try {
                $current = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
                if ($current > self::SCHEMA_VERSION) {
                    throw new RuntimeException('cms_database_schema_newer_than_core');
                }
                if ($current < self::SCHEMA_VERSION) {
                    $backup = self::migrationBackup($pdo, $database, $parent, $current);
                    try {
                        self::migrate($pdo);
                        self::integrityCheck($pdo);
                    } catch (Throwable $error) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $pdo = null;
                        try {
                            self::restoreMigrationBackup($database, $backup, $parent);
                        } catch (Throwable $rollbackError) {
                            throw new RuntimeException(
                                'cms_database_migration_rollback_failed',
                                0,
                                $rollbackError
                            );
                        }
                        throw new RuntimeException(
                            'cms_database_migration_failed_restored',
                            0,
                            $error
                        );
                    }
                    self::pruneMigrationBackups(dirname($backup['path']), 5);
                } else {
                    self::integrityCheck($pdo);
                }
            } finally {
                self::releaseLock($migrationLock);
            }
        } else {
            self::integrityCheck($pdo);
        }
        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            throw new RuntimeException('cms_database_nested_migration_forbidden');
        }
        $pdo->beginTransaction();
        try {
            $current = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
            if ($current > self::SCHEMA_VERSION) {
                throw new RuntimeException('cms_database_schema_newer_than_core');
            }
            if ($current < 1) {
                $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cms_meta(
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS cms_users(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK(role IN ('admin','editor','viewer')),
  status TEXT NOT NULL CHECK(status IN ('active','disabled')),
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  last_login_at INTEGER
);
CREATE TABLE IF NOT EXISTS cms_sessions(
  token_hash TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL,
  csrf_token TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,
  last_seen_at INTEGER NOT NULL,
  FOREIGN KEY(user_id) REFERENCES cms_users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cms_sessions_expiry ON cms_sessions(expires_at);
CREATE TABLE IF NOT EXISTS cms_extensions(
  extension_id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  version TEXT NOT NULL,
  type TEXT NOT NULL,
  execution TEXT NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('active','disabled')),
  manifest_json TEXT NOT NULL,
  manifest_sha256 TEXT NOT NULL,
  installed_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS cms_extension_permissions(
  extension_id TEXT NOT NULL,
  permission TEXT NOT NULL,
  PRIMARY KEY(extension_id,permission),
  FOREIGN KEY(extension_id) REFERENCES cms_extensions(extension_id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS cms_content_types(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  extension_id TEXT NOT NULL,
  type_key TEXT NOT NULL,
  label TEXT NOT NULL,
  route_slug TEXT NOT NULL UNIQUE,
  schema_json TEXT NOT NULL,
  schema_sha256 TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  UNIQUE(extension_id,type_key),
  FOREIGN KEY(extension_id) REFERENCES cms_extensions(extension_id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS cms_entries(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  content_type_id INTEGER NOT NULL,
  slug TEXT NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('draft','published','archived')),
  current_revision_id INTEGER,
  published_revision_id INTEGER,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  UNIQUE(content_type_id,slug),
  FOREIGN KEY(content_type_id) REFERENCES cms_content_types(id) ON DELETE RESTRICT,
  FOREIGN KEY(current_revision_id) REFERENCES cms_entry_revisions(id) ON DELETE RESTRICT,
  FOREIGN KEY(published_revision_id) REFERENCES cms_entry_revisions(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS cms_entry_revisions(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_id INTEGER NOT NULL,
  version INTEGER NOT NULL,
  payload_json TEXT NOT NULL,
  payload_sha256 TEXT NOT NULL,
  actor TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  UNIQUE(entry_id,version),
  FOREIGN KEY(entry_id) REFERENCES cms_entries(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS cms_media(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  storage_key TEXT NOT NULL UNIQUE,
  original_name TEXT NOT NULL,
  mime_type TEXT NOT NULL,
  byte_size INTEGER NOT NULL,
  sha256 TEXT NOT NULL,
  alt_text TEXT NOT NULL DEFAULT '',
  caption TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS cms_routes(
  extension_id TEXT NOT NULL,
  route_id TEXT NOT NULL,
  route_path TEXT NOT NULL UNIQUE,
  methods_json TEXT NOT NULL,
  access TEXT NOT NULL,
  content_type_id INTEGER,
  PRIMARY KEY(extension_id,route_id),
  FOREIGN KEY(extension_id) REFERENCES cms_extensions(extension_id) ON DELETE CASCADE,
  FOREIGN KEY(content_type_id) REFERENCES cms_content_types(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS cms_theme_slots(
  extension_id TEXT NOT NULL,
  slot_key TEXT NOT NULL UNIQUE,
  PRIMARY KEY(extension_id,slot_key),
  FOREIGN KEY(extension_id) REFERENCES cms_extensions(extension_id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS cms_extension_migrations(
  extension_id TEXT NOT NULL,
  migration_id TEXT NOT NULL,
  migration_sha256 TEXT NOT NULL,
  applied_at INTEGER NOT NULL,
  PRIMARY KEY(extension_id,migration_id),
  FOREIGN KEY(extension_id) REFERENCES cms_extensions(extension_id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS cms_publications(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version INTEGER NOT NULL UNIQUE,
  snapshot_json TEXT NOT NULL,
  snapshot_sha256 TEXT NOT NULL,
  actor TEXT NOT NULL,
  reason TEXT NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS cms_audit_logs(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor TEXT NOT NULL,
  action TEXT NOT NULL,
  resource_type TEXT NOT NULL,
  resource_id TEXT NOT NULL,
  details_json TEXT NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cms_audit_resource
  ON cms_audit_logs(resource_type,resource_id,created_at);
SQL);
                $pdo->prepare(
                    'INSERT INTO cms_meta(key,value,updated_at) '
                    . 'VALUES(\'runtime_profile\',:value,:updated_at) '
                    . 'ON CONFLICT(key) DO UPDATE SET '
                    . 'value=excluded.value,updated_at=excluded.updated_at'
                )->execute([
                    ':value' => self::RUNTIME_PROFILE,
                    ':updated_at' => time(),
                ]);
                $pdo->exec('PRAGMA user_version=1');
            }
            if ($current < 2) {
                // Session secrets from the foundation schema are deliberately not
                // carried forward: schema v2 stores only one-way token digests.
                $pdo->exec(<<<'SQL'
DROP INDEX IF EXISTS idx_cms_sessions_expiry;
DROP TABLE IF EXISTS cms_sessions;
CREATE TABLE IF NOT EXISTS cms_sessions(
  token_hash TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL,
  csrf_token_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,
  last_seen_at INTEGER NOT NULL,
  FOREIGN KEY(user_id) REFERENCES cms_users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cms_sessions_expiry ON cms_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_cms_sessions_user ON cms_sessions(user_id,expires_at);
CREATE TABLE IF NOT EXISTS cms_login_attempts(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username_hash TEXT NOT NULL,
  client_hash TEXT NOT NULL,
  successful INTEGER NOT NULL CHECK(successful IN (0,1)),
  attempted_at INTEGER NOT NULL
);
CREATE INDEX idx_cms_login_attempt_username
  ON cms_login_attempts(username_hash,successful,attempted_at);
CREATE INDEX idx_cms_login_attempt_client
  ON cms_login_attempts(client_hash,successful,attempted_at);
SQL);
                $pdo->exec('PRAGMA user_version=2');
            }
            if ($current < 3) {
                $pdo->exec(<<<'SQL'
ALTER TABLE cms_media ADD COLUMN width INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cms_media ADD COLUMN height INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cms_media ADD COLUMN thumbnail_storage_key TEXT;
ALTER TABLE cms_media ADD COLUMN status TEXT NOT NULL DEFAULT 'active'
  CHECK(status IN ('active','archived'));
ALTER TABLE cms_media ADD COLUMN archived_at INTEGER;
CREATE INDEX IF NOT EXISTS idx_cms_media_status_created
  ON cms_media(status,created_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_cms_media_sha256 ON cms_media(sha256);
CREATE TABLE IF NOT EXISTS cms_revision_media(
  revision_id INTEGER NOT NULL,
  media_id INTEGER NOT NULL,
  field_key TEXT NOT NULL,
  PRIMARY KEY(revision_id,field_key),
  FOREIGN KEY(revision_id) REFERENCES cms_entry_revisions(id) ON DELETE CASCADE,
  FOREIGN KEY(media_id) REFERENCES cms_media(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_cms_revision_media_media
  ON cms_revision_media(media_id,revision_id);
SQL);
                self::backfillRevisionMedia($pdo);
                $pdo->exec('PRAGMA user_version=3');
            }
            if ($current < 4) {
                $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cms_themes(
  theme_id TEXT NOT NULL,
  version TEXT NOT NULL,
  name TEXT NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('installed','active')),
  manifest_json TEXT NOT NULL,
  manifest_sha256 TEXT NOT NULL,
  installed_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(theme_id,version)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_cms_theme_single_active
  ON cms_themes(status) WHERE status='active';
CREATE TABLE IF NOT EXISTS cms_theme_assets(
  theme_id TEXT NOT NULL,
  version TEXT NOT NULL,
  asset_path TEXT NOT NULL,
  storage_key TEXT NOT NULL UNIQUE,
  media_type TEXT NOT NULL,
  byte_size INTEGER NOT NULL,
  sha256 TEXT NOT NULL,
  width INTEGER,
  height INTEGER,
  PRIMARY KEY(theme_id,version,asset_path),
  FOREIGN KEY(theme_id,version) REFERENCES cms_themes(theme_id,version) ON DELETE CASCADE
);
SQL);
                $pdo->exec('PRAGMA user_version=4');
            }
            if ($current < 5) {
                $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cms_site_settings(
  id INTEGER PRIMARY KEY CHECK(id=1),
  site_name TEXT NOT NULL,
  tagline TEXT NOT NULL,
  description TEXT NOT NULL,
  language TEXT NOT NULL,
  direction TEXT NOT NULL CHECK(direction IN ('ltr','rtl')),
  base_url TEXT NOT NULL,
  search_indexing INTEGER NOT NULL CHECK(search_indexing IN (0,1)),
  contact_title TEXT NOT NULL,
  contact_summary TEXT NOT NULL,
  cover_media_id INTEGER,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  FOREIGN KEY(cover_media_id) REFERENCES cms_media(id) ON DELETE SET NULL
);
SQL);
                $pdo->exec('PRAGMA user_version=5');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
    }

    public static function integrityCheck(PDO $pdo): void
    {
        if (strtolower((string)$pdo->query('PRAGMA integrity_check')->fetchColumn()) !== 'ok') {
            throw new RuntimeException('cms_database_integrity_check_failed');
        }
    }

    private static function migrationLock(string $parent): mixed
    {
        $path = $parent . '/.migration.lock';
        if (is_link($path)) {
            throw new RuntimeException('cms_database_migration_lock_unsafe');
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('cms_database_migration_lock_unavailable');
        }
        @chmod($path, 0600);
        if (is_link($path) || !@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            throw new RuntimeException(
                is_link($path)
                    ? 'cms_database_migration_lock_unsafe'
                    : 'cms_database_migration_busy'
            );
        }
        return $handle;
    }

    private static function releaseLock(mixed $handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private static function migrationBackup(
        PDO $pdo,
        string $database,
        string $parent,
        int $fromVersion
    ): array {
        if ($pdo->inTransaction()) {
            throw new RuntimeException('cms_database_migration_backup_transaction_active');
        }
        $directory = $parent . '/migration-backups';
        if (is_link($directory)
            || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
            || is_link($directory) || !is_writable($directory)) {
            throw new RuntimeException('cms_database_migration_backup_directory_failed');
        }
        $realParent = realpath($parent);
        $realDirectory = realpath($directory);
        if (!is_string($realParent) || !is_string($realDirectory)
            || !str_starts_with($realDirectory, $realParent . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('cms_database_migration_backup_directory_unsafe');
        }
        @chmod($directory, 0700);
        $path = $realDirectory . '/cms-v' . $fromVersion . '-before-v'
            . self::SCHEMA_VERSION . '-' . gmdate('Ymd-His') . '-'
            . bin2hex(random_bytes(6)) . '.sqlite';
        $quoted = str_replace("'", "''", $path);
        $pdo->exec("VACUUM INTO '" . $quoted . "'");
        @chmod($path, 0600);
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if (!is_int($bytes) || $bytes < 1 || $bytes > self::MAX_DATABASE_BYTES
            || !is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            @unlink($path);
            throw new RuntimeException('cms_database_migration_backup_failed');
        }
        return ['path' => $path, 'sha256' => $sha256, 'byte_size' => $bytes];
    }

    private static function restoreMigrationBackup(
        string $database,
        array $backup,
        string $parent
    ): void {
        $path = $backup['path'] ?? null;
        $sha256 = $backup['sha256'] ?? null;
        $bytes = $backup['byte_size'] ?? null;
        if (!is_string($path) || is_link($path) || !is_file($path)
            || !is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)
            || !is_int($bytes) || $bytes < 1 || (int)filesize($path) !== $bytes
            || !hash_equals($sha256, (string)hash_file('sha256', $path))) {
            throw new RuntimeException('cms_database_migration_backup_corrupt');
        }
        $temporary = $parent . '/.migration-restore-' . bin2hex(random_bytes(8));
        if (is_link($temporary) || !copy($path, $temporary)) {
            throw new RuntimeException('cms_database_migration_restore_copy_failed');
        }
        @chmod($temporary, 0600);
        if ((int)filesize($temporary) !== $bytes
            || !hash_equals($sha256, (string)hash_file('sha256', $temporary))) {
            @unlink($temporary);
            throw new RuntimeException('cms_database_migration_restore_copy_failed');
        }
        $failed = dirname($path) . '/failed-' . gmdate('Ymd-His') . '-'
            . bin2hex(random_bytes(6)) . '.sqlite';
        if (file_exists($database) && (is_link($database) || !rename($database, $failed))) {
            @unlink($temporary);
            throw new RuntimeException('cms_database_migration_restore_swap_failed');
        }
        if (!rename($temporary, $database)) {
            @unlink($temporary);
            if (is_file($failed) && !file_exists($database)) {
                @rename($failed, $database);
            }
            throw new RuntimeException('cms_database_migration_restore_swap_failed');
        }
        @chmod($database, 0600);
        if ((int)filesize($database) !== $bytes
            || !hash_equals($sha256, (string)hash_file('sha256', $database))) {
            throw new RuntimeException('cms_database_migration_restore_verify_failed');
        }
    }

    private static function pruneMigrationBackups(string $directory, int $keep): void
    {
        $files = glob($directory . '/cms-v*-before-v*-*.sqlite') ?: [];
        usort($files, static fn(string $left, string $right): int =>
            ((int)filemtime($right) <=> (int)filemtime($left)) ?: strcmp($right, $left)
        );
        foreach (array_slice($files, $keep) as $file) {
            if (is_string($file) && !is_link($file)
                && preg_match(
                    '#/cms-v[0-9]+-before-v[0-9]+-[0-9]{8}-[0-9]{6}-[a-f0-9]{12}\.sqlite$#D',
                    $file
                )) {
                @unlink($file);
            }
        }
    }

    private static function backfillRevisionMedia(PDO $pdo): void
    {
        $rows = $pdo->query(<<<'SQL'
SELECT r.id AS revision_id,r.payload_json,ct.schema_json
FROM cms_entry_revisions r
JOIN cms_entries e ON e.id=r.entry_id
JOIN cms_content_types ct ON ct.id=e.content_type_id
ORDER BY r.id ASC
SQL)->fetchAll();
        $insert = $pdo->prepare(<<<'SQL'
INSERT OR IGNORE INTO cms_revision_media(revision_id,media_id,field_key)
VALUES(:revision_id,:media_id,:field_key)
SQL);
        $mediaExists = $pdo->prepare('SELECT COUNT(*) FROM cms_media WHERE id=:media_id');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('cms_media_backfill_query_failed');
            }
            try {
                $payload = json_decode((string)$row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                $schema = json_decode((string)$row['schema_json'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('cms_media_backfill_content_corrupt', 0, $error);
            }
            if (!is_array($payload) || array_is_list($payload)
                || !is_array($schema) || array_is_list($schema)
                || !isset($schema['fields']) || !is_array($schema['fields'])) {
                throw new RuntimeException('cms_media_backfill_content_corrupt');
            }
            foreach ($schema['fields'] as $field) {
                if (!is_array($field) || ($field['type'] ?? null) !== 'image') {
                    continue;
                }
                $key = $field['key'] ?? null;
                $mediaId = is_string($key) ? ($payload[$key] ?? null) : null;
                if (!is_int($mediaId) || $mediaId < 1) {
                    continue;
                }
                $mediaExists->execute([':media_id' => $mediaId]);
                if ((int)$mediaExists->fetchColumn() !== 1) {
                    throw new RuntimeException('cms_media_backfill_reference_missing');
                }
                $insert->execute([
                    ':revision_id' => $row['revision_id'],
                    ':media_id' => $mediaId,
                    ':field_key' => $key,
                ]);
            }
        }
    }
}
