<?php
declare(strict_types=1);

/** Strict dependency-free validator for declarative Kuaiz Theme v2 manifests. */
final class KuaizCmsThemeManifest
{
    public const SCHEMA = 'kuaiz-theme/v2';
    private const MAX_MANIFEST_BYTES = 524288;
    private const COMPONENTS = [
        'hero', 'rich_text', 'card_grid', 'media_text', 'stats',
        'faq', 'cta', 'contact', 'extension_slot',
    ];
    private const VARIANTS = [
        'default', 'minimal', 'split', 'centered', 'editorial',
        'showcase', 'compact', 'image_top', 'alternating',
    ];
    private const BINDING_PREFIXES = ['site', 'page', 'content', 'collection'];
    private const REQUIRED_TEMPLATES = ['home', 'content_list', 'content_detail', 'not_found'];

    public static function fromFile(string $path): array
    {
        if ($path === '' || is_link($path) || !is_file($path)) {
            throw new RuntimeException('theme_manifest_file_unsafe');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('theme_manifest_size_invalid');
        }
        $body = file_get_contents($path);
        if (!is_string($body)) {
            throw new RuntimeException('theme_manifest_unreadable');
        }
        return self::parse($body);
    }

    public static function parse(string $json): array
    {
        if ($json === '' || strlen($json) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('theme_manifest_size_invalid');
        }
        try {
            $raw = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('theme_manifest_json_invalid', 0, $error);
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new RuntimeException('theme_manifest_object_required');
        }
        self::exactKeys($raw, [
            'schema', 'id', 'name', 'version', 'description', 'author',
            'compatibility', 'design', 'templates', 'assets', 'preview',
        ], 'theme_manifest_fields_invalid');
        if (($raw['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('theme_manifest_schema_unsupported');
        }
        $id = self::text($raw['id'] ?? null, 80, 'theme_id_invalid');
        if (!preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $id)) {
            throw new RuntimeException('theme_id_invalid');
        }
        $name = self::text($raw['name'] ?? null, 80, 'theme_name_invalid');
        $version = self::text($raw['version'] ?? null, 40, 'theme_version_invalid');
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)) {
            throw new RuntimeException('theme_version_invalid');
        }
        $description = self::text(
            $raw['description'] ?? null,
            500,
            'theme_description_invalid',
            true
        );
        $author = self::author($raw['author'] ?? null);
        $compatibility = self::compatibility($raw['compatibility'] ?? null);
        $design = self::design($raw['design'] ?? null);
        $templates = self::templates($raw['templates'] ?? null);
        $assets = self::assets($raw['assets'] ?? null);
        $preview = self::preview($raw['preview'] ?? null, $compatibility['directions']);

        return [
            'schema' => self::SCHEMA,
            'id' => $id,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'author' => $author,
            'compatibility' => $compatibility,
            'design' => $design,
            'templates' => $templates,
            'assets' => $assets,
            'preview' => $preview,
        ];
    }

    public static function canonicalJson(array $value): string
    {
        $body = json_encode(
            self::canonicalValue($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (!is_string($body)) {
            throw new RuntimeException('theme_manifest_encode_failed');
        }
        return $body;
    }

    private static function author(mixed $value): array
    {
        $value = self::object($value, 'theme_author_invalid');
        self::exactKeys($value, ['name', 'url'], 'theme_author_fields_invalid');
        $name = self::text($value['name'] ?? null, 80, 'theme_author_name_invalid');
        $url = $value['url'] ?? null;
        if ($url !== null) {
            $url = self::httpsUrl($url, 'theme_author_url_invalid');
        }
        return ['name' => $name, 'url' => $url];
    }

    private static function compatibility(mixed $value): array
    {
        $value = self::object($value, 'theme_compatibility_invalid');
        self::exactKeys(
            $value,
            ['cms', 'site_language_mode', 'directions'],
            'theme_compatibility_fields_invalid'
        );
        $cms = self::text($value['cms'] ?? null, 64, 'theme_cms_range_invalid');
        if (!preg_match('/^>=[0-9]+\.[0-9]+\.[0-9]+ <[0-9]+\.[0-9]+\.[0-9]+$/D', $cms)) {
            throw new RuntimeException('theme_cms_range_invalid');
        }
        if (($value['site_language_mode'] ?? null) !== 'single') {
            throw new RuntimeException('theme_language_mode_invalid');
        }
        $directions = self::enumList(
            $value['directions'] ?? null,
            ['ltr', 'rtl'],
            2,
            'theme_directions_invalid',
            false
        );
        return ['cms' => $cms, 'site_language_mode' => 'single', 'directions' => $directions];
    }

    private static function design(mixed $value): array
    {
        $value = self::object($value, 'theme_design_invalid');
        self::exactKeys($value, ['colors', 'typography', 'layout', 'shape'], 'theme_design_fields_invalid');
        $colors = self::colors($value['colors'] ?? null);
        $typography = self::object($value['typography'] ?? null, 'theme_typography_invalid');
        self::exactKeys($typography, ['body', 'heading', 'scale'], 'theme_typography_fields_invalid');
        $fontChoices = ['system', 'sans', 'serif', 'rounded', 'mono'];
        $typography = [
            'body' => self::enum($typography['body'] ?? null, $fontChoices, 'theme_body_font_invalid'),
            'heading' => self::enum($typography['heading'] ?? null, $fontChoices, 'theme_heading_font_invalid'),
            'scale' => self::enum(
                $typography['scale'] ?? null,
                ['compact', 'balanced', 'editorial'],
                'theme_type_scale_invalid'
            ),
        ];
        $layout = self::object($value['layout'] ?? null, 'theme_layout_invalid');
        self::exactKeys($layout, ['max_width', 'density', 'header', 'footer'], 'theme_layout_fields_invalid');
        $maxWidth = self::integer($layout['max_width'] ?? null, 880, 1440, 'theme_max_width_invalid');
        $layout = [
            'max_width' => $maxWidth,
            'density' => self::enum(
                $layout['density'] ?? null,
                ['compact', 'comfortable', 'spacious'],
                'theme_density_invalid'
            ),
            'header' => self::enum(
                $layout['header'] ?? null,
                ['minimal', 'split', 'centered', 'overlay'],
                'theme_header_invalid'
            ),
            'footer' => self::enum(
                $layout['footer'] ?? null,
                ['minimal', 'columns', 'split'],
                'theme_footer_invalid'
            ),
        ];
        $shape = self::object($value['shape'] ?? null, 'theme_shape_invalid');
        self::exactKeys($shape, ['radius', 'shadow'], 'theme_shape_fields_invalid');
        $shape = [
            'radius' => self::integer($shape['radius'] ?? null, 0, 40, 'theme_radius_invalid'),
            'shadow' => self::enum(
                $shape['shadow'] ?? null,
                ['none', 'soft', 'strong'],
                'theme_shadow_invalid'
            ),
        ];
        return ['colors' => $colors, 'typography' => $typography, 'layout' => $layout, 'shape' => $shape];
    }

    private static function colors(mixed $value): array
    {
        $value = self::object($value, 'theme_colors_invalid');
        $keys = [
            'background', 'surface', 'text', 'muted',
            'primary', 'primary_text', 'accent', 'border',
        ];
        self::exactKeys($value, $keys, 'theme_color_fields_invalid');
        $colors = [];
        foreach ($keys as $key) {
            $color = self::text($value[$key] ?? null, 7, 'theme_color_invalid');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/D', $color)) {
                throw new RuntimeException('theme_color_invalid');
            }
            $colors[$key] = strtolower($color);
        }
        foreach ([
            ['text', 'background'], ['text', 'surface'],
            ['muted', 'background'], ['muted', 'surface'],
            ['accent', 'background'], ['accent', 'surface'],
            ['primary_text', 'primary'],
        ] as [$foreground, $background]) {
            if (self::contrastRatio($colors[$foreground], $colors[$background]) < 4.5) {
                throw new RuntimeException('theme_color_contrast_insufficient:' . $foreground . ':' . $background);
            }
        }
        return $colors;
    }

    private static function templates(mixed $value): array
    {
        $value = self::object($value, 'theme_templates_invalid');
        self::exactKeys($value, self::REQUIRED_TEMPLATES, 'theme_template_fields_invalid');
        $templates = [];
        foreach (self::REQUIRED_TEMPLATES as $template) {
            $sections = $value[$template] ?? null;
            if (!is_array($sections) || !array_is_list($sections)
                || $sections === [] || count($sections) > 40) {
                throw new RuntimeException('theme_template_invalid:' . $template);
            }
            $seen = [];
            $normalized = [];
            foreach ($sections as $section) {
                $item = self::section($section);
                if (isset($seen[$item['id']])) {
                    throw new RuntimeException('theme_section_id_duplicate:' . $template);
                }
                $seen[$item['id']] = true;
                $normalized[] = $item;
            }
            $templates[$template] = $normalized;
        }
        return $templates;
    }

    private static function section(mixed $value): array
    {
        $value = self::object($value, 'theme_section_invalid');
        self::exactKeys($value, [
            'id', 'component', 'variant', 'width', 'tone',
            'bindings', 'options', 'visibility',
        ], 'theme_section_fields_invalid');
        $id = self::text($value['id'] ?? null, 40, 'theme_section_id_invalid');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $id)) {
            throw new RuntimeException('theme_section_id_invalid');
        }
        $component = self::enum(
            $value['component'] ?? null,
            self::COMPONENTS,
            'theme_component_invalid'
        );
        $bindings = self::bindings($value['bindings'] ?? null);
        $options = self::options($value['options'] ?? null);
        if ($component === 'extension_slot') {
            $slot = $options['slot'] ?? null;
            if (!is_string($slot)
                || !preg_match('/^[a-z][a-z0-9.-]{2,119}$/D', $slot)) {
                throw new RuntimeException('theme_extension_slot_invalid');
            }
        } elseif (isset($options['slot'])) {
            throw new RuntimeException('theme_extension_slot_unexpected');
        }
        $visibility = self::object($value['visibility'] ?? null, 'theme_visibility_invalid');
        self::exactKeys($visibility, ['when_empty', 'devices'], 'theme_visibility_fields_invalid');
        $visibility = [
            'when_empty' => self::enum(
                $visibility['when_empty'] ?? null,
                ['hide', 'show'],
                'theme_visibility_empty_invalid'
            ),
            'devices' => self::enumList(
                $visibility['devices'] ?? null,
                ['mobile', 'tablet', 'desktop'],
                3,
                'theme_visibility_devices_invalid',
                false
            ),
        ];
        return [
            'id' => $id,
            'component' => $component,
            'variant' => self::enum($value['variant'] ?? null, self::VARIANTS, 'theme_variant_invalid'),
            'width' => self::enum(
                $value['width'] ?? null,
                ['narrow', 'content', 'wide', 'full'],
                'theme_width_invalid'
            ),
            'tone' => self::enum(
                $value['tone'] ?? null,
                ['background', 'surface', 'primary', 'accent', 'muted'],
                'theme_tone_invalid'
            ),
            'bindings' => $bindings,
            'options' => $options,
            'visibility' => $visibility,
        ];
    }

    private static function bindings(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('theme_bindings_invalid');
        }
        if (count($value) > 20) {
            throw new RuntimeException('theme_bindings_invalid');
        }
        $bindings = [];
        foreach ($value as $key => $binding) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key)
                || !is_string($binding) || strlen($binding) > 130
                || !preg_match('/^(site|page|content|collection)\.[a-z][a-z0-9_.-]{0,119}$/D', $binding, $parts)
                || !in_array($parts[1], self::BINDING_PREFIXES, true)) {
                throw new RuntimeException('theme_binding_invalid');
            }
            $bindings[$key] = $binding;
        }
        return $bindings;
    }

    private static function options(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('theme_options_invalid');
        }
        if (count($value) > 20) {
            throw new RuntimeException('theme_options_invalid');
        }
        $options = [];
        foreach ($value as $key => $option) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key)
                || is_array($option) || is_object($option) || is_resource($option)
                || (is_string($option) && (strlen($option) > 500 || !preg_match('//u', $option)))
                || (is_float($option) && (is_nan($option) || is_infinite($option)))) {
                throw new RuntimeException('theme_option_invalid');
            }
            $options[$key] = $option;
        }
        return $options;
    }

    private static function assets(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 200) {
            throw new RuntimeException('theme_assets_invalid');
        }
        $assets = [];
        $paths = [];
        $mimeByExtension = [
            'avif' => 'image/avif', 'webp' => 'image/webp',
            'png' => 'image/png', 'jpg' => 'image/jpeg',
        ];
        foreach ($value as $asset) {
            $asset = self::object($asset, 'theme_asset_invalid');
            self::exactKeys(
                $asset,
                ['path', 'media_type', 'sha256', 'width', 'height'],
                'theme_asset_fields_invalid'
            );
            $path = self::text($asset['path'] ?? null, 180, 'theme_asset_path_invalid');
            if (!preg_match('#^assets/[a-z0-9][a-z0-9/_-]*\.(avif|webp|png|jpg)$#D', $path, $parts)
                || isset($paths[$path])) {
                throw new RuntimeException('theme_asset_path_invalid');
            }
            $mediaType = self::text($asset['media_type'] ?? null, 40, 'theme_asset_type_invalid');
            if (($mimeByExtension[$parts[1]] ?? null) !== $mediaType) {
                throw new RuntimeException('theme_asset_type_invalid');
            }
            $sha256 = self::text($asset['sha256'] ?? null, 64, 'theme_asset_hash_invalid');
            if (!preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
                throw new RuntimeException('theme_asset_hash_invalid');
            }
            $width = self::nullableInteger($asset['width'] ?? null, 1, 12000, 'theme_asset_width_invalid');
            $height = self::nullableInteger($asset['height'] ?? null, 1, 12000, 'theme_asset_height_invalid');
            if ($width === null || $height === null || $width * $height > 40000000) {
                throw new RuntimeException('theme_asset_dimensions_invalid');
            }
            $paths[$path] = true;
            $assets[] = [
                'path' => $path,
                'media_type' => $mediaType,
                'sha256' => $sha256,
                'width' => $width,
                'height' => $height,
            ];
        }
        return $assets;
    }

    private static function preview(mixed $value, array $directions): array
    {
        $value = self::object($value, 'theme_preview_invalid');
        self::exactKeys($value, ['required_seeds', 'viewports'], 'theme_preview_fields_invalid');
        $seeds = self::enumList(
            $value['required_seeds'] ?? null,
            ['short', 'long', 'complex', 'empty', 'rtl'],
            8,
            'theme_preview_seeds_invalid',
            false
        );
        foreach (['short', 'long', 'complex'] as $required) {
            if (!in_array($required, $seeds, true)) {
                throw new RuntimeException('theme_preview_seed_required:' . $required);
            }
        }
        if (in_array('rtl', $directions, true) && !in_array('rtl', $seeds, true)) {
            throw new RuntimeException('theme_preview_rtl_required');
        }
        $viewports = self::enumList(
            $value['viewports'] ?? null,
            ['mobile', 'tablet', 'desktop', 'wide'],
            4,
            'theme_preview_viewports_invalid',
            false
        );
        foreach (['mobile', 'desktop'] as $required) {
            if (!in_array($required, $viewports, true)) {
                throw new RuntimeException('theme_preview_viewport_required:' . $required);
            }
        }
        return ['required_seeds' => $seeds, 'viewports' => $viewports];
    }

    private static function contrastRatio(string $foreground, string $background): float
    {
        $luminance = static function (string $color): float {
            $channels = [];
            foreach ([1, 3, 5] as $offset) {
                $channel = hexdec(substr($color, $offset, 2)) / 255;
                $channels[] = $channel <= 0.04045
                    ? $channel / 12.92
                    : (($channel + 0.055) / 1.055) ** 2.4;
            }
            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };
        $first = $luminance($foreground);
        $second = $luminance($background);
        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    private static function object(mixed $value, string $errorCode): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException($errorCode);
        }
        return $value;
    }

    private static function exactKeys(array $value, array $keys, string $errorCode): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new RuntimeException($errorCode);
        }
    }

    private static function text(
        mixed $value,
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

    private static function enum(mixed $value, array $allowed, string $errorCode): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new RuntimeException($errorCode);
        }
        return $value;
    }

    private static function enumList(
        mixed $value,
        array $allowed,
        int $maximum,
        string $errorCode,
        bool $allowEmpty = true
    ): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum
            || (!$allowEmpty && $value === [])) {
            throw new RuntimeException($errorCode);
        }
        $seen = [];
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true) || isset($seen[$item])) {
                throw new RuntimeException($errorCode);
            }
            $seen[$item] = true;
            $result[] = $item;
        }
        return $result;
    }

    private static function integer(mixed $value, int $minimum, int $maximum, string $errorCode): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException($errorCode);
        }
        return $value;
    }

    private static function nullableInteger(
        mixed $value,
        int $minimum,
        int $maximum,
        string $errorCode
    ): ?int {
        if ($value === null) {
            return null;
        }
        return self::integer($value, $minimum, $maximum, $errorCode);
    }

    private static function httpsUrl(mixed $value, string $errorCode): string
    {
        $url = self::text($value, 2048, $errorCode);
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException($errorCode);
        }
        return $url;
    }

    private static function canonicalValue(mixed $value, ?string $parentKey = null): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === [] && in_array($parentKey, ['bindings', 'options'], true)) {
            return (object)[];
        }
        if (array_is_list($value)) {
            return array_map(
                static fn(mixed $item): mixed => self::canonicalValue($item),
                $value
            );
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalValue($item, (string)$key);
        }
        return $value;
    }
}
