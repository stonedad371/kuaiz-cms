<?php
declare(strict_types=1);

/** Single-language public site identity and indexing policy. */
final class KuaizCmsSiteSettings
{
    public static function get(PDO $pdo): ?array
    {
        $row = $pdo->query('SELECT * FROM cms_site_settings WHERE id=1')->fetch();
        return is_array($row) ? self::publicRecord($row) : null;
    }

    public static function save(PDO $pdo, array $input, string $actor): array
    {
        self::actor($actor);
        self::exactKeys($input, [
            'site_name', 'tagline', 'description', 'language', 'direction',
            'base_url', 'search_indexing', 'contact_title', 'contact_summary',
            'cover_media_id',
        ]);
        $siteName = self::text($input['site_name'] ?? null, 120, 'cms_site_name_invalid');
        $tagline = self::text($input['tagline'] ?? null, 200, 'cms_site_tagline_invalid', true);
        $description = self::text(
            $input['description'] ?? null,
            500,
            'cms_site_description_invalid'
        );
        $language = self::language($input['language'] ?? null);
        $direction = $input['direction'] ?? null;
        if (!is_string($direction) || !in_array($direction, ['ltr', 'rtl'], true)) {
            throw new RuntimeException('cms_site_direction_invalid');
        }
        $baseUrl = self::baseUrl($input['base_url'] ?? null);
        if (!is_bool($input['search_indexing'] ?? null)) {
            throw new RuntimeException('cms_site_indexing_invalid');
        }
        $searchIndexing = $input['search_indexing'];
        $contactTitle = self::text(
            $input['contact_title'] ?? null,
            160,
            'cms_site_contact_title_invalid',
            true
        );
        $contactSummary = self::text(
            $input['contact_summary'] ?? null,
            1000,
            'cms_site_contact_summary_invalid',
            true
        );
        $coverMediaId = $input['cover_media_id'] ?? null;
        if ($coverMediaId !== null) {
            if (!is_int($coverMediaId) || $coverMediaId < 1) {
                throw new RuntimeException('cms_site_cover_invalid');
            }
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM cms_media WHERE id=:media_id AND status='active'"
            );
            $statement->execute([':media_id' => $coverMediaId]);
            if ((int)$statement->fetchColumn() !== 1) {
                throw new RuntimeException('cms_site_cover_invalid');
            }
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('cms_site_nested_transaction_forbidden');
        }
        $now = time();
        $existing = self::get($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_site_settings(
  id,site_name,tagline,description,language,direction,base_url,search_indexing,
  contact_title,contact_summary,cover_media_id,created_at,updated_at)
VALUES(
  1,:site_name,:tagline,:description,:language,:direction,:base_url,:search_indexing,
  :contact_title,:contact_summary,:cover_media_id,:created_at,:updated_at)
ON CONFLICT(id) DO UPDATE SET
  site_name=excluded.site_name,
  tagline=excluded.tagline,
  description=excluded.description,
  language=excluded.language,
  direction=excluded.direction,
  base_url=excluded.base_url,
  search_indexing=excluded.search_indexing,
  contact_title=excluded.contact_title,
  contact_summary=excluded.contact_summary,
  cover_media_id=excluded.cover_media_id,
  updated_at=excluded.updated_at
SQL)->execute([
                ':site_name' => $siteName,
                ':tagline' => $tagline,
                ':description' => $description,
                ':language' => $language,
                ':direction' => $direction,
                ':base_url' => $baseUrl,
                ':search_indexing' => $searchIndexing ? 1 : 0,
                ':contact_title' => $contactTitle,
                ':contact_summary' => $contactSummary,
                ':cover_media_id' => $coverMediaId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $details = json_encode(
                [
                    'base_url' => $baseUrl,
                    'direction' => $direction,
                    'language' => $language,
                    'search_indexing' => $searchIndexing,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_logs(
  actor,action,resource_type,resource_id,details_json,created_at)
VALUES(:actor,:action,'site','1',:details_json,:created_at)
SQL)->execute([
                ':actor' => $actor,
                ':action' => $existing === null ? 'site.configured' : 'site.updated',
                ':details_json' => $details,
                ':created_at' => $now,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        $saved = self::get($pdo);
        if ($saved === null) {
            throw new RuntimeException('cms_site_save_failed');
        }
        return $saved;
    }

    private static function language($value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('cms_site_language_invalid');
        }
        $value = str_replace('_', '-', trim($value));
        if (!preg_match(
            '/^([A-Za-z]{2,3})(?:-([A-Za-z]{4}))?(?:-([A-Za-z]{2}|[0-9]{3}))?$/D',
            $value,
            $parts
        )) {
            throw new RuntimeException('cms_site_language_invalid');
        }
        $language = strtolower($parts[1]);
        if (isset($parts[2]) && $parts[2] !== '') {
            $language .= '-' . ucfirst(strtolower($parts[2]));
        }
        if (isset($parts[3]) && $parts[3] !== '') {
            $language .= '-' . (ctype_alpha($parts[3]) ? strtoupper($parts[3]) : $parts[3]);
        }
        return $language;
    }

    private static function baseUrl($value): string
    {
        $value = self::text($value, 2048, 'cms_site_base_url_invalid');
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new RuntimeException('cms_site_base_url_invalid');
        }
        $host = strtolower((string)$parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            || !preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'
                . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
                $host
            )) {
            throw new RuntimeException('cms_site_base_url_invalid');
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return 'https://' . $host . $port;
    }

    private static function exactKeys(array $input, array $expected): void
    {
        $actual = array_keys($input);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('cms_site_fields_invalid');
        }
    }

    private static function text(
        $value,
        int $maximum,
        string $errorCode,
        bool $allowEmpty = false
    ): string {
        if (!is_string($value) || strlen($value) > $maximum * 4
            || !preg_match('//u', $value)
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new RuntimeException($errorCode);
        }
        $value = trim($value);
        if (!$allowEmpty && $value === '') {
            throw new RuntimeException($errorCode);
        }
        return $value;
    }

    private static function actor(string $actor): void
    {
        if (!preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $actor)) {
            throw new RuntimeException('cms_site_actor_invalid');
        }
    }

    private static function publicRecord(array $row): array
    {
        return [
            'site_name' => (string)$row['site_name'],
            'tagline' => (string)$row['tagline'],
            'description' => (string)$row['description'],
            'language' => (string)$row['language'],
            'direction' => (string)$row['direction'],
            'base_url' => (string)$row['base_url'],
            'search_indexing' => (int)$row['search_indexing'] === 1,
            'contact_title' => (string)$row['contact_title'],
            'contact_summary' => (string)$row['contact_summary'],
            'cover_media_id' => $row['cover_media_id'] === null
                ? null : (int)$row['cover_media_id'],
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }
}
