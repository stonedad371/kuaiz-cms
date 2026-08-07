<?php
declare(strict_types=1);

/** Read-only public routing, media delivery, sitemap and robots policy. */
final class KuaizCmsPublicApplication
{
    public static function handle(PDO $pdo, array $server, string $storageRoot): array
    {
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return self::plain(405, 'Method not allowed.', true, ['Allow' => 'GET, HEAD']);
        }
        try {
            $path = self::requestPath((string)($server['REQUEST_URI'] ?? '/'));
        } catch (RuntimeException) {
            return self::plain(400, 'Invalid request path.', true);
        }
        $settings = KuaizCmsSiteSettings::get($pdo);
        if ($settings === null) {
            return self::plain(503, 'This site has not been configured.', true);
        }
        if ($path === '/robots.txt') {
            return self::head(self::robots($settings), $method);
        }
        if ($path === '/sitemap.xml') {
            return self::head(self::sitemap($pdo, $settings), $method);
        }
        if (preg_match('#^/media/([1-9][0-9]*)/([a-f0-9]{64})(-thumb)?\.webp$#D', $path, $media)) {
            return self::head(self::media(
                $pdo,
                $storageRoot,
                (int)$media[1],
                $media[2],
                ($media[3] ?? '') === '-thumb'
            ), $method);
        }

        $activeTheme = KuaizCmsThemeRegistry::active($pdo);
        if ($activeTheme === null) {
            return self::plain(503, 'This site does not have an active theme.', true);
        }
        $types = KuaizCmsContentRepository::publicContentTypes($pdo);
        $navigation = array_map(static fn(array $type): array => [
            'label' => $type['label'],
            'url' => '/' . $type['route_slug'],
        ], $types);
        $extensionSlots = KuaizCmsExtensionRegistry::activeThemeSlots($pdo);

        if ($path === '/') {
            $featured = [];
            foreach ($types as $type) {
                foreach (KuaizCmsContentRepository::publishedList(
                    $pdo,
                    $type['extension_id'],
                    $type['type_key'],
                    6,
                    0
                ) as $entry) {
                    $featured[] = self::publicEntry($entry, $type['route_slug']);
                    if (count($featured) >= 6) {
                        break 2;
                    }
                }
            }
            $context = [
                'page' => [
                    'title' => $settings['site_name'],
                    'description' => $settings['description'],
                    'canonical_path' => '/',
                    'featured_title' => '精选内容',
                ],
                'content' => [],
                'collection' => ['featured' => $featured, 'faq' => []],
                'navigation' => $navigation,
                'extension_slots' => $extensionSlots,
            ];
            return self::head(KuaizCmsThemeRenderer::render(
                $settings,
                $activeTheme,
                'home',
                self::withMedia($pdo, $settings, $context)
            ), $method);
        }

        foreach ($types as $type) {
            $basePath = '/' . $type['route_slug'];
            if ($path === $basePath) {
                $entries = array_map(
                    static fn(array $entry): array => self::publicEntry(
                        $entry,
                        $type['route_slug']
                    ),
                    KuaizCmsContentRepository::publishedList(
                        $pdo,
                        $type['extension_id'],
                        $type['type_key'],
                        100,
                        0
                    )
                );
                $context = [
                    'page' => [
                        'title' => $type['label'],
                        'description' => $type['label'] . '列表',
                        'canonical_path' => $basePath,
                    ],
                    'content' => [],
                    'collection' => ['current' => $entries],
                    'navigation' => $navigation,
                    'extension_slots' => $extensionSlots,
                ];
                return self::head(KuaizCmsThemeRenderer::render(
                    $settings,
                    $activeTheme,
                    'content_list',
                    self::withMedia($pdo, $settings, $context)
                ), $method);
            }
            if (preg_match('#^' . preg_quote($basePath, '#') . '/([a-z0-9][a-z0-9-]{0,119})$#D', $path, $detail)) {
                $entry = KuaizCmsContentRepository::published(
                    $pdo,
                    $type['extension_id'],
                    $type['type_key'],
                    $detail[1]
                );
                if ($entry !== null) {
                    $content = $entry['payload'] + [
                        'slug' => $entry['slug'],
                        'published_at' => $entry['published_at'],
                        'updated_at' => $entry['published_at'],
                    ];
                    $title = self::entryTitle($content, $entry['slug']);
                    $description = self::entryDescription($content, $settings['description']);
                    $context = [
                        'page' => [
                            'title' => $title,
                            'description' => $description,
                            'canonical_path' => $basePath . '/' . $entry['slug'],
                        ],
                        'content' => $content,
                        'collection' => [],
                        'navigation' => $navigation,
                        'extension_slots' => $extensionSlots,
                    ];
                    return self::head(KuaizCmsThemeRenderer::render(
                        $settings,
                        $activeTheme,
                        'content_detail',
                        self::withMedia($pdo, $settings, $context)
                    ), $method);
                }
            }
        }
        $notFound = [
            'page' => [
                'title' => '页面不存在',
                'description' => '你访问的页面不存在或已经下线。',
                'canonical_path' => $path,
            ],
            'content' => [],
            'collection' => [],
            'navigation' => $navigation,
            'extension_slots' => $extensionSlots,
        ];
        return self::head(KuaizCmsThemeRenderer::render(
            $settings,
            $activeTheme,
            'not_found',
            self::withMedia($pdo, $settings, $notFound),
            404
        ), $method);
    }

    private static function withMedia(PDO $pdo, array $settings, array $context): array
    {
        $ids = [];
        if (is_int($settings['cover_media_id'])) {
            $ids[$settings['cover_media_id']] = true;
        }
        self::collectMediaIds($context['content'] ?? [], $ids);
        foreach ($context['collection'] ?? [] as $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item)) {
                    self::collectMediaIds($item, $ids);
                }
            }
        }
        $context['media'] = [];
        foreach (array_keys($ids) as $mediaId) {
            try {
                $media = KuaizCmsMediaRepository::item($pdo, (int)$mediaId);
            } catch (RuntimeException) {
                continue;
            }
            if ($media['status'] !== 'active') {
                continue;
            }
            $base = '/media/' . $media['id'] . '/' . $media['sha256'];
            $context['media'][$media['id']] = [
                'url' => $base . '.webp',
                'thumbnail_url' => $base . '-thumb.webp',
                'alt_text' => $media['alt_text'],
                'width' => $media['width'],
                'height' => $media['height'],
            ];
        }
        return $context;
    }

    private static function collectMediaIds(array $value, array &$ids): void
    {
        foreach (['cover', 'image', 'logo'] as $key) {
            if (isset($value[$key]) && is_int($value[$key]) && $value[$key] > 0) {
                $ids[$value[$key]] = true;
            }
        }
    }

    private static function publicEntry(array $entry, string $routeSlug): array
    {
        return $entry['payload'] + [
            '_url' => '/' . $routeSlug . '/' . $entry['slug'],
            'slug' => $entry['slug'],
            'published_at' => $entry['published_at'],
            'updated_at' => $entry['updated_at'],
        ];
    }

    private static function media(
        PDO $pdo,
        string $storageRoot,
        int $mediaId,
        string $sha256,
        bool $thumbnail
    ): array {
        try {
            $media = KuaizCmsMediaRepository::item($pdo, $mediaId);
            if ($media['status'] !== 'active' || !hash_equals($media['sha256'], $sha256)) {
                throw new RuntimeException('cms_public_media_not_found');
            }
            $file = KuaizCmsMediaRepository::readFile($pdo, $storageRoot, $mediaId, $thumbnail);
            $body = file_get_contents($file['path']);
            if (!is_string($body) || strlen($body) !== $file['byte_size']) {
                throw new RuntimeException('cms_public_media_read_failed');
            }
        } catch (RuntimeException) {
            return self::plain(404, 'Image not found.', true);
        }
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'image/webp',
                'Content-Length' => (string)$file['byte_size'],
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'inline',
            ],
            'body' => $body,
        ];
    }

    private static function robots(array $settings): array
    {
        $body = "User-agent: *\n";
        if ($settings['search_indexing']) {
            $body .= "Allow: /\nSitemap: " . $settings['base_url'] . "/sitemap.xml\n";
        } else {
            $body .= "Disallow: /\n";
        }
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'public, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => 'noindex',
            ],
            'body' => $body,
        ];
    }

    private static function sitemap(PDO $pdo, array $settings): array
    {
        $urls = [];
        if ($settings['search_indexing']) {
            $urls[] = ['path' => '/', 'updated_at' => $settings['updated_at']];
            foreach (KuaizCmsContentRepository::publicContentTypes($pdo) as $type) {
                $basePath = '/' . $type['route_slug'];
                $urls[] = ['path' => $basePath, 'updated_at' => $settings['updated_at']];
                foreach (KuaizCmsContentRepository::publishedList(
                    $pdo,
                    $type['extension_id'],
                    $type['type_key'],
                    100,
                    0
                ) as $entry) {
                    $urls[] = [
                        'path' => $basePath . '/' . $entry['slug'],
                        'updated_at' => $entry['updated_at'],
                    ];
                }
            }
        }
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $location = $settings['base_url'] . ($url['path'] === '/' ? '' : $url['path']);
            $body .= '<url><loc>' . self::xml($location) . '</loc><lastmod>'
                . gmdate('Y-m-d', (int)$url['updated_at']) . '</lastmod></url>';
        }
        $body .= '</urlset>';
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => $settings['search_indexing'] ? 'index, follow' : 'noindex, nofollow',
            ],
            'body' => $body,
        ];
    }

    private static function plain(
        int $status,
        string $body,
        bool $noindex,
        array $extraHeaders = []
    ): array {
        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];
        if ($noindex) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private static function head(array $response, string $method): array
    {
        if ($method === 'HEAD') {
            $response['body'] = '';
        }
        return $response;
    }

    private static function requestPath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || strlen($path) > 2048
            || str_contains($path, "\0") || str_contains($path, '//')
            || str_contains($path, '..') || str_contains($path, '%')) {
            throw new RuntimeException('cms_public_path_invalid');
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    private static function entryTitle(array $content, string $fallback): string
    {
        foreach (['title', 'name', 'headline'] as $key) {
            if (isset($content[$key]) && is_string($content[$key]) && trim($content[$key]) !== '') {
                return trim($content[$key]);
            }
        }
        return $fallback;
    }

    private static function entryDescription(array $content, string $fallback): string
    {
        foreach (['summary', 'description', 'excerpt'] as $key) {
            if (isset($content[$key]) && is_string($content[$key]) && trim($content[$key]) !== '') {
                return trim($content[$key]);
            }
        }
        return $fallback;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
