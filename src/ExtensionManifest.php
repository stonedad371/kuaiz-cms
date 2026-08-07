<?php
declare(strict_types=1);

/** Strict, dependency-free validator for Kuaiz CMS extension manifests. */
final class KuaizCmsExtensionManifest
{
    public const SCHEMA = 'kuaiz-cms-extension/v1';
    private const MAX_MANIFEST_BYTES = 262144;
    private const TYPES = ['content', 'business', 'connector'];
    private const EXECUTION = ['declarative', 'official-signed-php'];
    private const PERMISSIONS = [
        'content.read', 'content.write', 'media.read', 'media.write',
        'mail.send', 'jobs.schedule', 'webhook.emit',
        'members.read', 'members.write',
    ];
    private const FIELD_TYPES = [
        'text', 'long_text', 'number', 'boolean', 'date', 'datetime', 'image', 'url',
    ];
    private const PERSONAL_DATA = [
        'contact', 'identity', 'appointment', 'member_profile', 'custom',
    ];
    private const RESERVED_ROUTES = [
        'admin', 'api', 'assets', 'media', 'health', 'kuaiz-admin.php',
        'kuaiz-worker.php', 'robots.txt', 'sitemap.xml',
    ];

    public static function fromFile(string $path): array
    {
        if ($path === '' || is_link($path) || !is_file($path)) {
            throw new RuntimeException('extension_manifest_file_unsafe');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('extension_manifest_size_invalid');
        }
        $body = file_get_contents($path);
        if (!is_string($body)) {
            throw new RuntimeException('extension_manifest_unreadable');
        }
        return self::parse($body);
    }

    public static function parse(string $json): array
    {
        if ($json === '' || strlen($json) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('extension_manifest_size_invalid');
        }
        try {
            $raw = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('extension_manifest_json_invalid', 0, $error);
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new RuntimeException('extension_manifest_object_required');
        }
        self::exactKeys($raw, [
            'schema', 'id', 'name', 'version', 'description', 'type', 'execution',
            'entrypoint', 'migrations', 'requires', 'permissions', 'routes', 'events', 'theme_slots',
            'content_types', 'data_policy', 'network',
        ], 'extension_manifest_fields_invalid');

        if (($raw['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('extension_manifest_schema_unsupported');
        }
        $id = self::text($raw['id'] ?? null, 80, 'extension_id_invalid');
        if (!preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $id)) {
            throw new RuntimeException('extension_id_invalid');
        }
        $name = self::text($raw['name'] ?? null, 80, 'extension_name_invalid');
        $version = self::text($raw['version'] ?? null, 40, 'extension_version_invalid');
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)) {
            throw new RuntimeException('extension_version_invalid');
        }
        $description = self::text(
            $raw['description'] ?? null,
            500,
            'extension_description_invalid',
            false
        );
        $type = self::enum($raw['type'] ?? null, self::TYPES, 'extension_type_invalid');
        $execution = self::enum(
            $raw['execution'] ?? null,
            self::EXECUTION,
            'extension_execution_invalid'
        );
        $entrypoint = self::entrypoint($raw['entrypoint'] ?? null);
        $migrations = self::migrations($raw['migrations'] ?? null);
        $requires = self::requires($raw['requires'] ?? null);
        $permissions = self::stringList(
            $raw['permissions'] ?? null,
            32,
            'extension_permissions_invalid'
        );
        foreach ($permissions as $permission) {
            if (!in_array($permission, self::PERMISSIONS, true)) {
                throw new RuntimeException('extension_permission_unsupported');
            }
        }

        $contentTypes = self::contentTypes($raw['content_types'] ?? null);
        $contentTypeIds = array_fill_keys(
            array_map(static fn(array $item): string => $item['id'], $contentTypes),
            true
        );
        $routes = self::routes($raw['routes'] ?? null, $contentTypeIds, $execution);
        $events = self::events($raw['events'] ?? null, $id);
        $themeSlots = self::themeSlots($raw['theme_slots'] ?? null, $id);
        $dataPolicy = self::dataPolicy($raw['data_policy'] ?? null);
        $network = self::network($raw['network'] ?? null);

        if ($execution === 'declarative') {
            if ($type !== 'content' || $contentTypes === []) {
                throw new RuntimeException('declarative_extension_content_required');
            }
            if ($events['subscribes'] !== [] || $network['outbound_hosts'] !== []) {
                throw new RuntimeException('declarative_extension_execution_forbidden');
            }
            if ($entrypoint !== null || $migrations !== []) {
                throw new RuntimeException('declarative_extension_code_forbidden');
            }
            $unsafe = array_intersect(
                $permissions,
                ['mail.send', 'jobs.schedule', 'webhook.emit', 'members.read', 'members.write']
            );
            if ($unsafe !== []) {
                throw new RuntimeException('declarative_extension_permission_forbidden');
            }
        } elseif ($entrypoint === null) {
            throw new RuntimeException('signed_extension_entrypoint_required');
        }

        return [
            'schema' => self::SCHEMA,
            'id' => $id,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'type' => $type,
            'execution' => $execution,
            'entrypoint' => $entrypoint,
            'migrations' => $migrations,
            'requires' => $requires,
            'permissions' => $permissions,
            'routes' => $routes,
            'events' => $events,
            'theme_slots' => $themeSlots,
            'content_types' => $contentTypes,
            'data_policy' => $dataPolicy,
            'network' => $network,
        ];
    }

    private static function entrypoint(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('extension_entrypoint_invalid');
        }
        self::exactKeys($value, ['file', 'class'], 'extension_entrypoint_invalid');
        $file = self::text($value['file'] ?? null, 160, 'extension_entrypoint_file_invalid');
        $class = self::text($value['class'] ?? null, 160, 'extension_entrypoint_class_invalid');
        if (!preg_match('#^src/[A-Z][A-Za-z0-9/]*\.php$#D', $file)
            || !preg_match('/^[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)+$/D', $class)) {
            throw new RuntimeException('extension_entrypoint_invalid');
        }
        return ['file' => $file, 'class' => $class];
    }

    private static function migrations(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new RuntimeException('extension_migrations_invalid');
        }
        $result = [];
        $seen = [];
        foreach ($value as $migration) {
            if (!is_array($migration) || array_is_list($migration)) {
                throw new RuntimeException('extension_migration_invalid');
            }
            self::exactKeys(
                $migration,
                ['id', 'file', 'sha256'],
                'extension_migration_fields_invalid'
            );
            $id = self::text($migration['id'] ?? null, 55, 'extension_migration_id_invalid');
            $file = self::text(
                $migration['file'] ?? null,
                80,
                'extension_migration_file_invalid'
            );
            $sha256 = self::text(
                $migration['sha256'] ?? null,
                64,
                'extension_migration_hash_invalid'
            );
            if (!preg_match('/^[0-9]{4}_[a-z][a-z0-9_]{0,50}$/D', $id)
                || $file !== 'migrations/' . $id . '.sql'
                || !preg_match('/^[a-f0-9]{64}$/D', $sha256)
                || isset($seen[$id])) {
                throw new RuntimeException('extension_migration_invalid');
            }
            $seen[$id] = true;
            $result[] = ['id' => $id, 'file' => $file, 'sha256' => $sha256];
        }
        return $result;
    }

    public static function canonicalJson(array $value): string
    {
        $encoded = json_encode(
            self::canonicalValue($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (!is_string($encoded)) {
            throw new RuntimeException('extension_manifest_encode_failed');
        }
        return $encoded;
    }

    private static function requires(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('extension_requires_invalid');
        }
        self::exactKeys($value, ['cms', 'php'], 'extension_requires_invalid');
        $cms = self::text($value['cms'] ?? null, 64, 'extension_cms_range_invalid');
        $php = self::text($value['php'] ?? null, 32, 'extension_php_range_invalid');
        if (!preg_match('/^>=[0-9]+\.[0-9]+\.[0-9]+ <[0-9]+\.[0-9]+\.[0-9]+$/D', $cms)) {
            throw new RuntimeException('extension_cms_range_invalid');
        }
        if (!preg_match('/^>=[0-9]+\.[0-9]+$/D', $php)) {
            throw new RuntimeException('extension_php_range_invalid');
        }
        return ['cms' => $cms, 'php' => $php];
    }

    private static function routes(mixed $value, array $contentTypes, string $execution): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 32) {
            throw new RuntimeException('extension_routes_invalid');
        }
        $result = [];
        $ids = [];
        $paths = [];
        foreach ($value as $route) {
            if (!is_array($route) || array_is_list($route)) {
                throw new RuntimeException('extension_route_invalid');
            }
            self::exactKeys(
                $route,
                ['id', 'path', 'methods', 'access', 'content_type'],
                'extension_route_fields_invalid'
            );
            $routeId = self::text($route['id'] ?? null, 40, 'extension_route_id_invalid');
            if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $routeId) || isset($ids[$routeId])) {
                throw new RuntimeException('extension_route_id_invalid');
            }
            $path = self::text($route['path'] ?? null, 120, 'extension_route_path_invalid');
            $segment = '(?:[a-z0-9][a-z0-9_-]*|\{(?:slug|id)\})';
            if (!preg_match('#^/' . $segment . '(?:/' . $segment . ')*$#D', $path)
                || isset($paths[$path])) {
                throw new RuntimeException('extension_route_path_invalid');
            }
            $first = explode('/', ltrim($path, '/'), 2)[0];
            if (in_array($first, self::RESERVED_ROUTES, true)) {
                throw new RuntimeException('extension_route_reserved');
            }
            $methods = self::stringList(
                $route['methods'] ?? null,
                5,
                'extension_route_methods_invalid',
                false
            );
            foreach ($methods as $method) {
                if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    throw new RuntimeException('extension_route_method_unsupported');
                }
                if ($execution === 'declarative' && $method !== 'GET') {
                    throw new RuntimeException('declarative_extension_route_write_forbidden');
                }
            }
            $access = self::enum(
                $route['access'] ?? null,
                ['public', 'member', 'admin'],
                'extension_route_access_invalid'
            );
            $contentType = $route['content_type'] ?? null;
            if ($contentType !== null) {
                $contentType = self::text(
                    $contentType,
                    40,
                    'extension_route_content_type_invalid'
                );
                if (!isset($contentTypes[$contentType])) {
                    throw new RuntimeException('extension_route_content_type_unknown');
                }
            }
            if ($execution === 'declarative' && $contentType === null) {
                throw new RuntimeException('declarative_extension_route_content_required');
            }
            $ids[$routeId] = true;
            $paths[$path] = true;
            $result[] = [
                'id' => $routeId,
                'path' => $path,
                'methods' => $methods,
                'access' => $access,
                'content_type' => $contentType,
            ];
        }
        return $result;
    }

    private static function events(mixed $value, string $extensionId): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('extension_events_invalid');
        }
        self::exactKeys($value, ['subscribes', 'publishes'], 'extension_events_invalid');
        $subscribes = self::eventList($value['subscribes'] ?? null);
        $publishes = self::eventList($value['publishes'] ?? null);
        foreach ($publishes as $event) {
            if (!str_starts_with($event, $extensionId . '.')) {
                throw new RuntimeException('extension_published_event_not_namespaced');
            }
        }
        return ['subscribes' => $subscribes, 'publishes' => $publishes];
    }

    private static function eventList(mixed $value): array
    {
        $events = self::stringList($value, 32, 'extension_event_list_invalid');
        foreach ($events as $event) {
            if (strlen($event) > 120
                || !preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9_]+)+$/D', $event)) {
                throw new RuntimeException('extension_event_invalid');
            }
        }
        return $events;
    }

    private static function themeSlots(mixed $value, string $extensionId): array
    {
        $slots = self::stringList($value, 32, 'extension_theme_slots_invalid');
        foreach ($slots as $slot) {
            if (strlen($slot) > 120
                || !preg_match('/^[a-z][a-z0-9.-]{2,119}$/D', $slot)
                || !str_starts_with($slot, $extensionId . '.')) {
                throw new RuntimeException('extension_theme_slot_invalid');
            }
        }
        return $slots;
    }

    private static function contentTypes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 32) {
            throw new RuntimeException('extension_content_types_invalid');
        }
        $result = [];
        $ids = [];
        $routes = [];
        foreach ($value as $contentType) {
            if (!is_array($contentType) || array_is_list($contentType)) {
                throw new RuntimeException('extension_content_type_invalid');
            }
            self::exactKeys(
                $contentType,
                ['id', 'label', 'route', 'fields'],
                'extension_content_type_fields_invalid'
            );
            $id = self::text($contentType['id'] ?? null, 40, 'extension_content_type_id_invalid');
            if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $id) || isset($ids[$id])) {
                throw new RuntimeException('extension_content_type_id_invalid');
            }
            $label = self::text(
                $contentType['label'] ?? null,
                80,
                'extension_content_type_label_invalid'
            );
            $route = self::text(
                $contentType['route'] ?? null,
                63,
                'extension_content_type_route_invalid'
            );
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $route)
                || isset($routes[$route]) || in_array($route, self::RESERVED_ROUTES, true)) {
                throw new RuntimeException('extension_content_type_route_invalid');
            }
            $fields = self::fields($contentType['fields'] ?? null);
            $ids[$id] = true;
            $routes[$route] = true;
            $result[] = ['id' => $id, 'label' => $label, 'route' => $route, 'fields' => $fields];
        }
        return $result;
    }

    private static function fields(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)
            || $value === [] || count($value) > 64) {
            throw new RuntimeException('extension_content_fields_invalid');
        }
        $result = [];
        $keys = [];
        foreach ($value as $field) {
            if (!is_array($field) || array_is_list($field)) {
                throw new RuntimeException('extension_content_field_invalid');
            }
            self::exactKeys(
                $field,
                ['key', 'label', 'type', 'required', 'searchable'],
                'extension_content_field_fields_invalid'
            );
            $key = self::text($field['key'] ?? null, 40, 'extension_field_key_invalid');
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) || isset($keys[$key])) {
                throw new RuntimeException('extension_field_key_invalid');
            }
            $label = self::text($field['label'] ?? null, 80, 'extension_field_label_invalid');
            $type = self::enum(
                $field['type'] ?? null,
                self::FIELD_TYPES,
                'extension_field_type_invalid'
            );
            if (!is_bool($field['required'] ?? null) || !is_bool($field['searchable'] ?? null)) {
                throw new RuntimeException('extension_field_flags_invalid');
            }
            $keys[$key] = true;
            $result[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => $field['required'],
                'searchable' => $field['searchable'],
            ];
        }
        return $result;
    }

    private static function dataPolicy(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('extension_data_policy_invalid');
        }
        self::exactKeys(
            $value,
            ['personal_data', 'retention_days', 'exportable', 'deletable'],
            'extension_data_policy_invalid'
        );
        $personal = self::stringList(
            $value['personal_data'] ?? null,
            5,
            'extension_personal_data_invalid'
        );
        foreach ($personal as $category) {
            if (!in_array($category, self::PERSONAL_DATA, true)) {
                throw new RuntimeException('extension_personal_data_unsupported');
            }
        }
        $retention = $value['retention_days'] ?? null;
        if (!is_int($retention) || $retention < 0 || $retention > 3650
            || !is_bool($value['exportable'] ?? null)
            || !is_bool($value['deletable'] ?? null)) {
            throw new RuntimeException('extension_data_policy_invalid');
        }
        if ($personal !== [] && (!$value['exportable'] || !$value['deletable'])) {
            throw new RuntimeException('extension_personal_data_controls_required');
        }
        return [
            'personal_data' => $personal,
            'retention_days' => $retention,
            'exportable' => $value['exportable'],
            'deletable' => $value['deletable'],
        ];
    }

    private static function network(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('extension_network_invalid');
        }
        self::exactKeys($value, ['outbound_hosts'], 'extension_network_invalid');
        $hosts = self::stringList(
            $value['outbound_hosts'] ?? null,
            16,
            'extension_network_hosts_invalid'
        );
        foreach ($hosts as $host) {
            if (strlen($host) > 253
                || !preg_match(
                    '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'
                    . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
                    $host
                )) {
                throw new RuntimeException('extension_network_host_invalid');
            }
        }
        return ['outbound_hosts' => $hosts];
    }

    private static function exactKeys(array $value, array $expected, string $error): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException($error);
        }
    }

    private static function text(
        mixed $value,
        int $maximumCharacters,
        string $error,
        bool $required = true
    ): string {
        if (!is_string($value) || !preg_match('//u', $value)
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new RuntimeException($error);
        }
        $value = trim($value);
        if (($required && $value === '') || strlen($value) > $maximumCharacters * 4) {
            throw new RuntimeException($error);
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $error): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new RuntimeException($error);
        }
        return $value;
    }

    private static function stringList(
        mixed $value,
        int $maximum,
        string $error,
        bool $allowEmpty = true
    ): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum
            || (!$allowEmpty && $value === [])) {
            throw new RuntimeException($error);
        }
        $seen = [];
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || isset($seen[$item])) {
                throw new RuntimeException($error);
            }
            $seen[$item] = true;
            $result[] = $item;
        }
        return $result;
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
