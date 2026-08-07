<?php
declare(strict_types=1);

/** Core-owned HTML and SEO renderer for validated Theme v2 component trees. */
final class KuaizCmsThemeRenderer
{
    public static function render(
        array $settings,
        array $activeTheme,
        string $template,
        array $context,
        int $status = 200
    ): array {
        if (!in_array($template, ['home', 'content_list', 'content_detail', 'not_found'], true)
            || !isset($activeTheme['manifest']['templates'][$template])) {
            throw new RuntimeException('cms_public_template_invalid');
        }
        $manifest = $activeTheme['manifest'];
        if (!in_array($settings['direction'], $manifest['compatibility']['directions'], true)) {
            throw new RuntimeException('cms_public_theme_direction_unsupported');
        }
        $nonce = bin2hex(random_bytes(16));
        $page = is_array($context['page'] ?? null) ? $context['page'] : [];
        $pageTitle = self::plain($page['title'] ?? $settings['site_name'], 200);
        $description = self::plain($page['description'] ?? $settings['description'], 500);
        $canonicalPath = self::canonicalPath($page['canonical_path'] ?? '/');
        $canonical = $settings['base_url'] . ($canonicalPath === '/' ? '' : $canonicalPath);
        $title = $template === 'home'
            ? $settings['site_name']
            : $pageTitle . ' · ' . $settings['site_name'];
        $noindex = !$settings['search_indexing'] || $status >= 400;
        $sections = '';
        foreach ($manifest['templates'][$template] as $section) {
            $sections .= self::section($section, $settings, $context);
        }
        $navigation = self::navigation($context['navigation'] ?? []);
        $jsonLd = self::structuredData(
            $template,
            $settings,
            $pageTitle,
            $description,
            $canonical,
            $context
        );
        $html = '<!doctype html><html lang="' . self::h($settings['language'])
            . '" dir="' . self::h($settings['direction']) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::h($title) . '</title><meta name="description" content="'
            . self::h($description) . '"><meta name="robots" content="'
            . ($noindex ? 'noindex,nofollow,noarchive' : 'index,follow,max-image-preview:large') . '">'
            . '<link rel="canonical" href="' . self::h($canonical) . '">'
            . '<meta property="og:type" content="' . ($template === 'content_detail' ? 'article' : 'website') . '">'
            . '<meta property="og:title" content="' . self::h($title) . '">'
            . '<meta property="og:description" content="' . self::h($description) . '">'
            . '<meta property="og:url" content="' . self::h($canonical) . '">'
            . '<meta property="og:locale" content="' . self::h(str_replace('-', '_', $settings['language'])) . '">'
            . '<style nonce="' . $nonce . '">' . self::css($manifest['design']) . '</style>'
            . '<script type="application/ld+json" nonce="' . $nonce . '">' . $jsonLd . '</script>'
            . '</head><body><a class="skip" href="#main">跳到主要内容</a><header class="site-header"><div class="shell header-inner">'
            . '<a class="brand" href="/">' . self::h($settings['site_name']) . '</a>'
            . '<nav aria-label="主要导航"><a href="/">首页</a>' . $navigation . '</nav></div></header>'
            . '<main id="main">' . $sections . '</main><footer class="site-footer"><div class="shell footer-inner"><div><strong>'
            . self::h($settings['site_name']) . '</strong><p>' . self::h($settings['tagline'])
            . '</p></div><p>© ' . date('Y') . ' ' . self::h($settings['site_name'])
            . '</p></div></footer></body></html>';
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
            'Content-Security-Policy' => "default-src 'none'; style-src 'nonce-" . $nonce
                . "'; script-src 'nonce-" . $nonce
                . "'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Language' => $settings['language'],
        ];
        if ($noindex) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $html];
    }

    private static function section(array $section, array $settings, array $context): string
    {
        $data = [];
        foreach ($section['bindings'] as $key => $binding) {
            $data[$key] = self::resolve($binding, $settings, $context);
        }
        if ($section['visibility']['when_empty'] === 'hide') {
            if (self::emptyData($data)) {
                return '';
            }
            if ($section['component'] === 'card_grid'
                && (!isset($data['items']) || !is_array($data['items']) || $data['items'] === [])) {
                return '';
            }
        }
        $classes = 'section component-' . $section['component']
            . ' variant-' . $section['variant']
            . ' width-' . $section['width']
            . ' tone-' . $section['tone'];
        $content = match ($section['component']) {
            'hero' => self::hero($data, $context),
            'rich_text' => self::richText($data),
            'card_grid' => self::cardGrid($data, $section['options'], $context),
            'media_text' => self::mediaText($data, $context),
            'stats' => self::stats($data),
            'faq' => self::faq($data),
            'cta' => self::cta($data, $section['options']),
            'contact' => self::contact($data, $settings),
            'extension_slot' => '',
            default => '',
        };
        if ($content === '' && $section['visibility']['when_empty'] === 'hide') {
            return '';
        }
        $devices = implode(' ', array_map(
            static fn(string $device): string => 'show-' . $device,
            $section['visibility']['devices']
        ));
        return '<section id="section-' . self::h($section['id']) . '" class="'
            . self::h($classes . ' ' . $devices) . '"><div class="section-inner">'
            . $content . '</div></section>';
    }

    private static function hero(array $data, array $context): string
    {
        $eyebrow = self::optionalText($data['eyebrow'] ?? null, 200);
        $title = self::optionalText($data['title'] ?? null, 300);
        $summary = self::optionalText($data['summary'] ?? null, 1200);
        $image = self::image($data['image'] ?? null, $context, false);
        if ($title === '' && $summary === '' && $image === '') {
            return '';
        }
        return '<div class="hero-copy">' . ($eyebrow === '' ? '' : '<p class="eyebrow">' . self::h($eyebrow) . '</p>')
            . ($title === '' ? '' : '<h1>' . self::h($title) . '</h1>')
            . ($summary === '' ? '' : '<p class="lead">' . self::h($summary) . '</p>')
            . '</div>' . ($image === '' ? '' : '<div class="hero-media">' . $image . '</div>');
    }

    private static function richText(array $data): string
    {
        $body = self::optionalText($data['body'] ?? $data['text'] ?? null, 50000);
        if ($body === '') {
            return '';
        }
        $paragraphs = preg_split('/\R{2,}/u', $body) ?: [$body];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                $html .= '<p>' . nl2br(self::h($paragraph), false) . '</p>';
            }
        }
        return '<div class="prose">' . $html . '</div>';
    }

    private static function cardGrid(array $data, array $options, array $context): string
    {
        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return '<p class="empty-state">暂时还没有可展示的内容。</p>';
        }
        $columns = $options['columns'] ?? 3;
        if (!is_int($columns) || $columns < 1 || $columns > 4) {
            $columns = 3;
        }
        $title = self::optionalText($data['title'] ?? null, 200);
        $cards = '';
        foreach (array_slice($items, 0, 100) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemTitle = self::optionalText($item['title'] ?? $item['name'] ?? null, 300);
            $summary = self::optionalText($item['summary'] ?? $item['description'] ?? null, 800);
            $url = self::internalUrl($item['_url'] ?? '/');
            $image = self::image($item['cover'] ?? $item['image'] ?? null, $context, true);
            $cards .= '<article class="card">' . ($image === '' ? '' : '<a class="card-media" href="'
                . self::h($url) . '">' . $image . '</a>') . '<div class="card-body"><h3><a href="'
                . self::h($url) . '">' . self::h($itemTitle !== '' ? $itemTitle : '查看详情') . '</a></h3>'
                . ($summary === '' ? '' : '<p>' . self::h($summary) . '</p>') . '</div></article>';
        }
        return ($title === '' ? '' : '<h2>' . self::h($title) . '</h2>')
            . '<div class="cards cols-' . $columns . '">' . $cards . '</div>';
    }

    private static function mediaText(array $data, array $context): string
    {
        $title = self::optionalText($data['title'] ?? null, 300);
        $summary = self::optionalText($data['summary'] ?? $data['body'] ?? null, 3000);
        $image = self::image($data['image'] ?? null, $context, false);
        return '<div class="media-text-copy">' . ($title === '' ? '' : '<h2>' . self::h($title) . '</h2>')
            . ($summary === '' ? '' : '<p>' . self::h($summary) . '</p>') . '</div>'
            . ($image === '' ? '' : '<div class="media-text-image">' . $image . '</div>');
    }

    private static function stats(array $data): string
    {
        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            return '';
        }
        $html = '';
        foreach (array_slice($items, 0, 12) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = self::optionalText($item['value'] ?? null, 80);
            $label = self::optionalText($item['label'] ?? null, 160);
            if ($value !== '' || $label !== '') {
                $html .= '<div class="stat"><strong>' . self::h($value) . '</strong><span>'
                    . self::h($label) . '</span></div>';
            }
        }
        return '<div class="stats">' . $html . '</div>';
    }

    private static function faq(array $data): string
    {
        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return '';
        }
        $html = '<h2>' . self::h(self::optionalText($data['title'] ?? '常见问题', 200)) . '</h2>';
        foreach (array_slice($items, 0, 30) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = self::optionalText($item['question'] ?? $item['title'] ?? null, 300);
            $answer = self::optionalText($item['answer'] ?? $item['summary'] ?? null, 3000);
            if ($question !== '') {
                $html .= '<details><summary>' . self::h($question) . '</summary><p>'
                    . self::h($answer) . '</p></details>';
            }
        }
        return '<div class="faq">' . $html . '</div>';
    }

    private static function cta(array $data, array $options): string
    {
        $title = self::optionalText($data['title'] ?? null, 300);
        $summary = self::optionalText($data['summary'] ?? null, 1200);
        $action = $options['action'] ?? $options['primary_action'] ?? 'contact';
        $url = $action === 'home' ? '/' : '#contact';
        $label = $action === 'home' ? '返回首页' : '联系我们';
        return '<div class="cta-copy"><h2>' . self::h($title) . '</h2>'
            . ($summary === '' ? '' : '<p>' . self::h($summary) . '</p>')
            . '<a class="button" href="' . $url . '">' . $label . '</a></div>';
    }

    private static function contact(array $data, array $settings): string
    {
        $title = self::optionalText($data['title'] ?? $settings['contact_title'], 300);
        $summary = self::optionalText($data['summary'] ?? $settings['contact_summary'], 2000);
        if ($title === '' && $summary === '') {
            return '';
        }
        return '<div id="contact"><h2>' . self::h($title) . '</h2><p>' . self::h($summary) . '</p></div>';
    }

    private static function image(mixed $value, array $context, bool $thumbnail): string
    {
        if (!is_int($value) || $value < 1 || !isset($context['media'][$value])
            || !is_array($context['media'][$value])) {
            return '';
        }
        $media = $context['media'][$value];
        $url = $thumbnail ? ($media['thumbnail_url'] ?? null) : ($media['url'] ?? null);
        if (!is_string($url) || !str_starts_with($url, '/media/')) {
            return '';
        }
        return '<img src="' . self::h($url) . '" alt="' . self::h((string)($media['alt_text'] ?? ''))
            . '" width="' . (int)$media['width'] . '" height="' . (int)$media['height']
            . '" loading="lazy" decoding="async">';
    }

    private static function resolve(string $binding, array $settings, array $context): mixed
    {
        [$scope, $key] = explode('.', $binding, 2);
        $source = $scope === 'site' ? $settings : ($context[$scope] ?? []);
        if (!is_array($source)) {
            return null;
        }
        if ($scope === 'site') {
            if ($key === 'name') {
                return $settings['site_name'];
            }
            if ($key === 'cover') {
                return $settings['cover_media_id'];
            }
        }
        return $source[$key] ?? null;
    }

    private static function navigation(mixed $items): string
    {
        if (!is_array($items)) {
            return '';
        }
        $html = '';
        foreach (array_slice($items, 0, 20) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = self::optionalText($item['label'] ?? null, 100);
            $url = self::internalUrl($item['url'] ?? '/');
            if ($label !== '') {
                $html .= '<a href="' . self::h($url) . '">' . self::h($label) . '</a>';
            }
        }
        return $html;
    }

    private static function structuredData(
        string $template,
        array $settings,
        string $pageTitle,
        string $description,
        string $canonical,
        array $context
    ): string {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => $template === 'content_detail' ? 'Article' : 'WebPage',
            'name' => $pageTitle,
            'description' => $description,
            'url' => $canonical,
            'inLanguage' => $settings['language'],
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $settings['site_name'],
                'url' => $settings['base_url'],
            ],
        ];
        if ($template === 'content_detail' && isset($context['content']['published_at'])) {
            $data['datePublished'] = gmdate('c', (int)$context['content']['published_at']);
            $data['dateModified'] = gmdate(
                'c',
                (int)($context['content']['updated_at'] ?? $context['content']['published_at'])
            );
        }
        $body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_THROW_ON_ERROR
        );
        return is_string($body) ? $body : '{}';
    }

    private static function css(array $design): string
    {
        $color = $design['colors'];
        $layout = $design['layout'];
        $shape = $design['shape'];
        $fonts = [
            'system' => '-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC",sans-serif',
            'sans' => 'Arial,"Noto Sans",sans-serif',
            'serif' => 'Georgia,"Noto Serif",serif',
            'rounded' => 'ui-rounded,"Arial Rounded MT Bold",sans-serif',
            'mono' => 'ui-monospace,SFMono-Regular,Consolas,monospace',
        ];
        $shadow = match ($shape['shadow']) {
            'strong' => '0 26px 70px rgba(15,30,22,.18)',
            'soft' => '0 18px 48px rgba(15,30,22,.09)',
            default => 'none',
        };
        $gap = match ($layout['density']) {
            'compact' => 'clamp(42px,7vw,72px)',
            'spacious' => 'clamp(76px,12vw,150px)',
            default => 'clamp(56px,9vw,108px)',
        };
        return ':root{--bg:' . $color['background'] . ';--surface:' . $color['surface']
            . ';--text:' . $color['text'] . ';--muted:' . $color['muted']
            . ';--primary:' . $color['primary'] . ';--primary-text:' . $color['primary_text']
            . ';--accent:' . $color['accent'] . ';--border:' . $color['border']
            . ';--width:' . (int)$layout['max_width'] . 'px;--radius:' . (int)$shape['radius']
            . 'px;--shadow:' . $shadow . ';--section-gap:' . $gap . ';--body-font:'
            . $fonts[$design['typography']['body']] . ';--heading-font:'
            . $fonts[$design['typography']['heading']] . '}*{box-sizing:border-box}html{scroll-behavior:smooth}'
            . 'body{margin:0;background:var(--bg);color:var(--text);font:16px/1.7 var(--body-font)}'
            . 'a{color:var(--primary);text-decoration-thickness:.08em;text-underline-offset:.2em}img{display:block;max-width:100%;height:auto}'
            . '.skip{position:absolute;inset-inline-start:-9999px}.skip:focus{inset:12px auto auto 12px;background:var(--surface);padding:10px;z-index:20}'
            . '.shell{width:min(var(--width),calc(100% - 36px));margin-inline:auto}.site-header{background:color-mix(in srgb,var(--bg) 92%,transparent);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:10;backdrop-filter:blur(16px)}'
            . '.header-inner,.footer-inner{display:flex;align-items:center;justify-content:space-between;gap:24px;padding-block:18px}.brand{color:var(--text);font:700 20px/1.2 var(--heading-font);text-decoration:none}.site-header nav{display:flex;gap:18px;flex-wrap:wrap}.site-header nav a{text-decoration:none}.section{padding-block:var(--section-gap)}.section-inner{width:min(var(--width),calc(100% - 36px));margin-inline:auto}.width-narrow .section-inner{max-width:720px}.width-content .section-inner{max-width:920px}.width-full .section-inner{width:100%;max-width:none}.tone-surface{background:var(--surface)}.tone-primary{background:var(--primary);color:var(--primary-text)}.tone-primary a{color:var(--primary-text)}.tone-accent{border-block:1px solid var(--accent);background:var(--surface)}.tone-muted{background:color-mix(in srgb,var(--muted) 9%,var(--bg))}'
            . 'h1,h2,h3{font-family:var(--heading-font);line-height:1.1;letter-spacing:-.025em;margin-block:0 .55em}h1{font-size:clamp(42px,8vw,92px);max-width:14ch}h2{font-size:clamp(30px,5vw,56px)}h3{font-size:22px}.lead{font-size:clamp(18px,2.2vw,24px);max-width:62ch;color:var(--muted)}.tone-primary .lead{color:inherit}.eyebrow{color:var(--accent);font-weight:800;letter-spacing:.12em;text-transform:uppercase;font-size:12px}.component-hero .section-inner,.component-media_text .section-inner{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(280px,.95fr);align-items:center;gap:clamp(28px,6vw,80px)}.variant-centered .section-inner{display:block;text-align:center}.variant-centered h1{margin-inline:auto}.hero-media img,.media-text-image img{border-radius:var(--radius);box-shadow:var(--shadow);width:100%;max-height:680px;object-fit:cover}.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px}.cols-1{grid-template-columns:1fr}.cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow)}.card-media{aspect-ratio:4/3;display:block;overflow:hidden}.card-media img{height:100%;object-fit:cover;width:100%}.card-body{padding:20px}.card-body h3 a{color:var(--text);text-decoration:none}.card-body p{color:var(--muted)}.prose{font-size:18px}.prose p{margin-block:0 1.3em}.faq details{border-top:1px solid var(--border);padding-block:16px}.faq summary{cursor:pointer;font-weight:700}.button{background:var(--primary);border-radius:999px;color:var(--primary-text)!important;display:inline-block;font-weight:700;padding:11px 20px;text-decoration:none}.tone-primary .button{background:var(--primary-text);color:var(--primary)!important}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}.stat strong,.stat span{display:block}.stat strong{font:700 36px/1.1 var(--heading-font)}.site-footer{border-top:1px solid var(--border);padding-block:28px}.site-footer p{color:var(--muted);margin:4px 0}.empty-state{color:var(--muted)}'
            . '@media(max-width:800px){.header-inner,.footer-inner{align-items:flex-start;flex-direction:column}.component-hero .section-inner,.component-media_text .section-inner{grid-template-columns:1fr}.cards,.cols-2,.cols-3,.cols-4{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}.show-desktop:not(.show-mobile){display:none}}'
            . '@media(min-width:801px){.show-mobile:not(.show-desktop){display:none}}';
    }

    private static function emptyData(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                return false;
            }
        }
        return true;
    }

    private static function optionalText(mixed $value, int $maximum): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        $value = trim((string)$value);
        return strlen($value) <= $maximum * 4 && preg_match('//u', $value) ? $value : '';
    }

    private static function plain(mixed $value, int $maximum): string
    {
        $value = self::optionalText($value, $maximum);
        return $value === '' ? 'Untitled' : $value;
    }

    private static function internalUrl(mixed $value): string
    {
        if (!is_string($value) || !preg_match('#^/[a-z0-9/_-]*$#D', $value)
            || str_contains($value, '//') || str_contains($value, '..')) {
            return '/';
        }
        return $value;
    }

    private static function canonicalPath(mixed $value): string
    {
        $path = self::internalUrl($value);
        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
