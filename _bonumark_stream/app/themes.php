<?php
require_once __DIR__ . '/functions.php';

function bms_theme_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-_');
    return $slug !== '' ? substr($slug, 0, 64) : 'default';
}

function bms_theme_slug_or_empty(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-_');
    return $slug !== '' ? substr($slug, 0, 64) : '';
}

function bms_theme_setting_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? '';
    $key = trim($key, '_');
    return $key !== '' ? substr($key, 0, 64) : '';
}

function bms_themes_path(string $path = ''): string
{
    return bms_root_path('themes' . ($path ? '/' . ltrim($path, '/') : ''));
}

function bms_public_theme_asset_path(string $path = ''): string
{
    return bms_public_path('assets/themes' . ($path ? '/' . ltrim($path, '/') : ''));
}

function bms_theme_asset_reference(string $assetPath): string
{
    $assetPath = trim(str_replace('\\', '/', $assetPath));
    if ($assetPath === '' || str_contains($assetPath, chr(0))) {
        return '';
    }
    if (str_starts_with($assetPath, '/') || preg_match('#^[A-Za-z]:#', $assetPath) === 1) {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $assetPath) === 1 || str_starts_with($assetPath, '//')) {
        return '';
    }
    if (preg_match('#(^|/)\.\.(/|$)#', $assetPath) === 1) {
        return '';
    }
    return substr($assetPath, 0, 255);
}


function bms_theme_template_reference(string $template): string
{
    $template = strtolower(trim(str_replace('\\', '/', $template)));
    $template = basename($template);
    $template = preg_replace('/[^a-z0-9_-]+/', '-', $template) ?? '';
    $template = trim($template, '-_');
    return $template !== '' ? substr($template, 0, 64) : '';
}

function bms_theme_asset_allowed_extension(string $asset, string $type): bool
{
    $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
    return match ($type) {
        'css' => $extension === 'css',
        'images' => in_array($extension, ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico'], true),
        'fonts' => in_array($extension, ['woff', 'woff2'], true),
        'screenshot' => in_array($extension, ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'], true),
        default => false,
    };
}

function bms_theme_asset_group_label(string $type): string
{
    return match ($type) {
        'css' => 'CSS',
        'images' => 'image',
        'fonts' => 'font',
        default => strtoupper($type),
    };
}

function bms_normalize_theme_assets(array $rawAssets): array
{
    $assets = ['css' => [], 'images' => [], 'fonts' => []];
    foreach (array_keys($assets) as $type) {
        $rawList = $rawAssets[$type] ?? [];
        if (!is_array($rawList)) {
            continue;
        }
        foreach ($rawList as $asset) {
            $reference = bms_theme_asset_reference((string)$asset);
            if ($reference !== '' && bms_theme_asset_allowed_extension($reference, $type) && !in_array($reference, $assets[$type], true)) {
                $assets[$type][] = $reference;
            }
        }
    }
    return $assets;
}

function bms_theme_manifest_asset_errors(array $decoded): array
{
    $errors = [];
    foreach (['templates', 'view_slots', 'required_templates', 'view', 'core_view'] as $legacyKey) {
        if (array_key_exists($legacyKey, $decoded)) {
            $errors[] = 'theme.json cannot include legacy layout key: ' . $legacyKey . '. Themes are code-free presentation packages.';
        }
    }
    $assets = $decoded['assets'] ?? [];
    if ($assets !== [] && !is_array($assets)) {
        return ['theme.json assets must be an object.'];
    }

    if (is_array($assets)) {
        if (!empty($assets['js'] ?? [])) {
            $errors[] = 'Theme JavaScript is not allowed. Bonumark Stream core owns all behavior.';
        }
        foreach (['css', 'images', 'fonts'] as $type) {
            if (!array_key_exists($type, $assets)) {
                continue;
            }
            if (!is_array($assets[$type])) {
                $errors[] = 'theme.json ' . bms_theme_asset_group_label($type) . ' assets must be a list.';
                continue;
            }
            foreach ($assets[$type] as $asset) {
                $raw = trim((string)$asset);
                if ($raw === '' || bms_theme_asset_reference($raw) === '' || !bms_theme_asset_allowed_extension($raw, $type)) {
                    $errors[] = 'Invalid ' . bms_theme_asset_group_label($type) . ' asset path in theme.json.';
                }
            }
        }
    }

    $rawScreenshot = trim((string)($decoded['screenshot'] ?? ''));
    if ($rawScreenshot !== '' && (bms_theme_asset_reference($rawScreenshot) === '' || !bms_theme_asset_allowed_extension($rawScreenshot, 'screenshot'))) {
        $errors[] = 'Invalid screenshot path in theme.json.';
    }

    return array_values(array_unique($errors));
}

function bms_public_theme_core_render_parts(): array
{
    return [
        'layout',
        'header',
        'footer',
        'home',
        'archive',
        'single',
        'page',
        'profile',
        'account',
        'comments',
        'comments-mount',
        'search',
        'card',
        'link-preview',
        'media',
        'composer',
        'pagination',
        'empty',
    ];
}

function bms_public_theme_available_core_views(): array
{
    $views = [];
    $root = bms_root_path('app/views');
    if (is_dir($root)) {
        foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
            $slug = bms_theme_slug_or_empty($entry);
            if ($slug === '' || $slug !== $entry || !is_dir($root . '/' . $entry . '/templates')) {
                continue;
            }
            $views[] = $slug;
        }
    }
    return $views ?: ['default'];
}

function bms_public_theme_core_view_slug(array $theme): string
{
    return 'default';
}

function bms_public_theme_core_view_errors(array $theme): array
{
    if (!in_array('default', bms_public_theme_available_core_views(), true)) {
        return ['Bonumark Stream core is missing the default public view layer.'];
    }
    return [];
}

function bms_public_theme_asset_file_path(string $slug, string $asset): string
{
    $asset = bms_theme_asset_reference($asset);
    if ($asset === '') {
        return '';
    }
    return bms_public_theme_asset_path(bms_theme_slug($slug) . '/' . $asset);
}

function bms_theme_directory_disallowed_code_errors(string $path): array
{
    $errors = [];
    if (!is_dir($path)) {
        return $errors;
    }

    $badExtensions = ['php', 'phtml', 'phar', 'js', 'mjs', 'cjs', 'html', 'htm'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if (in_array($extension, $badExtensions, true)) {
            $errors[] = 'Themes must be code-free. Executable or script files are not allowed: ' . $file->getFilename();
        }
        $basename = strtolower($file->getBasename());
        if ($basename === '.htaccess' || str_starts_with($basename, '.user') || str_starts_with($basename, 'php.ini')) {
            $errors[] = 'Themes cannot include server configuration files: ' . $file->getFilename();
        }
        if (count($errors) >= 5) {
            break;
        }
    }
    return array_values(array_unique($errors));
}

function bms_public_theme_package_health_at_paths(array $theme, string $privateThemeRoot, string $publicAssetRoot): array
{
    $errors = is_array($theme['manifest_errors'] ?? null) ? array_values((array)$theme['manifest_errors']) : [];
    $warnings = [];
    foreach (['name', 'version', 'author', 'description'] as $field) {
        if (trim((string)($theme[$field] ?? '')) === '') {
            $errors[] = 'Missing required manifest field: ' . $field;
        }
    }

    $errors = array_merge($errors, bms_theme_directory_disallowed_code_errors($privateThemeRoot));
    $errors = array_merge($errors, bms_theme_directory_disallowed_code_errors($publicAssetRoot));
    $errors = array_merge($errors, bms_public_theme_core_view_errors($theme));


    $assets = is_array($theme['assets'] ?? null) ? $theme['assets'] : [];
    foreach (['css', 'images', 'fonts'] as $type) {
        foreach ((array)($assets[$type] ?? []) as $asset) {
            $asset = bms_theme_asset_reference((string)$asset);
            if ($asset === '' || !bms_theme_asset_allowed_extension($asset, $type)) {
                $errors[] = 'Invalid ' . bms_theme_asset_group_label($type) . ' asset path in theme.json.';
                continue;
            }
            $assetPath = rtrim($publicAssetRoot, '/\\') . '/' . $asset;
            if (!is_file($assetPath)) {
                $errors[] = 'Missing declared ' . bms_theme_asset_group_label($type) . ' asset: ' . $asset;
            }
        }
    }

    $screenshot = bms_theme_asset_reference((string)($theme['screenshot'] ?? ''));
    if ($screenshot !== '') {
        $screenshotPath = rtrim($publicAssetRoot, '/\\') . '/' . $screenshot;
        if (!is_file($screenshotPath)) {
            $errors[] = 'Missing declared screenshot: ' . $screenshot;
        }
    } else {
        $warnings[] = 'No screenshot is declared in theme.json.';
    }

    $status = empty($errors) ? 'valid' : 'invalid';
    return [
        'valid' => $status === 'valid',
        'status' => $status,
        'label' => $status === 'valid' ? 'Safe to activate' : 'Not safe to activate',
        'errors' => array_values(array_unique($errors)),
        'warnings' => array_values(array_unique($warnings)),
        'core_view' => bms_public_theme_core_view_slug($theme),
    ];
}

function bms_public_theme_package_health(array $theme): array
{
    $slug = bms_theme_slug((string)($theme['slug'] ?? 'default'));
    return bms_public_theme_package_health_at_paths(
        $theme,
        bms_themes_path($slug),
        bms_public_theme_asset_path($slug)
    );
}

function bms_public_theme_can_activate(array $theme): bool
{
    $health = is_array($theme['health'] ?? null) ? $theme['health'] : bms_public_theme_package_health($theme);
    return !empty($health['valid']);
}

function bms_public_theme_activation_error(array $theme): string
{
    $health = is_array($theme['health'] ?? null) ? $theme['health'] : bms_public_theme_package_health($theme);
    $errors = is_array($health['errors'] ?? null) ? $health['errors'] : [];
    if (!$errors) {
        return '';
    }
    return 'The selected theme is not safe to activate: ' . implode(' ', $errors);
}

function bms_normalize_theme_settings_schema(array $rawSettings): array
{
    $schema = [];
    foreach ($rawSettings as $rawKey => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $keySource = is_string($rawKey) ? $rawKey : (string)($definition['key'] ?? '');
        $key = bms_theme_setting_key($keySource);
        if ($key === '') {
            continue;
        }

        $type = strtolower(trim((string)($definition['type'] ?? 'text')));
        if (!in_array($type, ['text', 'textarea', 'checkbox', 'select'], true)) {
            $type = 'text';
        }

        $options = [];
        if ($type === 'select' && is_array($definition['options'] ?? null)) {
            foreach ($definition['options'] as $optionKey => $optionLabel) {
                $value = is_string($optionKey) ? $optionKey : (string)$optionLabel;
                $value = substr(trim($value), 0, 120);
                $label = substr(trim((string)$optionLabel), 0, 120);
                if ($value !== '' && $label !== '') {
                    $options[$value] = $label;
                }
            }
            if (!$options) {
                $type = 'text';
            }
        }

        $default = $definition['default'] ?? ($type === 'checkbox' ? '0' : '');
        if ($type === 'checkbox') {
            $default = !empty($default) && (string)$default !== '0' ? '1' : '0';
        } elseif ($type === 'select') {
            $default = (string)$default;
            if (!array_key_exists($default, $options)) {
                $default = (string)array_key_first($options);
            }
        } else {
            $default = substr(trim((string)$default), 0, $type === 'textarea' ? 1000 : 255);
        }

        $schema[$key] = [
            'key' => $key,
            'type' => $type,
            'label' => substr(trim((string)($definition['label'] ?? ucwords(str_replace('_', ' ', $key)))), 0, 120),
            'description' => substr(trim((string)($definition['description'] ?? '')), 0, 255),
            'default' => $default,
            'options' => $options,
        ];
    }

    return $schema;
}

function bms_read_theme_manifest_file(string $manifestPath, ?string $expectedSlug = null): ?array
{
    if (!is_file($manifestPath)) {
        return null;
    }

    $decoded = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($decoded)) {
        return null;
    }

    $manifestSlug = bms_theme_slug_or_empty((string)($decoded['slug'] ?? ''));
    if ($manifestSlug === '') {
        return null;
    }

    $expected = $expectedSlug !== null ? bms_theme_slug_or_empty($expectedSlug) : $manifestSlug;
    if ($expected === '' || $manifestSlug !== $expected) {
        return null;
    }

    $decoded['slug'] = $manifestSlug;
    $decoded['name'] = trim((string)($decoded['name'] ?? 'Midnight Ledger')) ?: 'Midnight Ledger';
    $decoded['version'] = trim((string)($decoded['version'] ?? '1.0.0')) ?: '1.0.0';
    $decoded['author'] = trim((string)($decoded['author'] ?? 'Bonumark')) ?: 'Bonumark';
    $decoded['description'] = trim((string)($decoded['description'] ?? 'A Bonumark Stream public theme.')) ?: 'A Bonumark Stream public theme.';
    $decoded['manifest_errors'] = bms_theme_manifest_asset_errors($decoded);
    $decoded['screenshot'] = bms_theme_asset_reference((string)($decoded['screenshot'] ?? ''));
    $decoded['supports'] = is_array($decoded['supports'] ?? null) ? $decoded['supports'] : [];
    $decoded['assets'] = is_array($decoded['assets'] ?? null) ? bms_normalize_theme_assets($decoded['assets']) : ['css' => [], 'images' => [], 'fonts' => []];
    $decoded['settings'] = is_array($decoded['settings'] ?? null) ? bms_normalize_theme_settings_schema($decoded['settings']) : [];

    return $decoded;
}

function bms_read_theme_manifest(string $slug): ?array
{
    $slug = bms_theme_slug($slug);
    return bms_read_theme_manifest_file(bms_themes_path($slug . '/theme.json'), $slug);
}

function bms_public_theme_discovery_issues(): array
{
    $issues = [];
    $root = bms_themes_path();
    if (!is_dir($root)) {
        return $issues;
    }

    foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
        $path = $root . '/' . $entry;
        if (!is_dir($path)) {
            continue;
        }
        $slug = bms_theme_slug($entry);
        if ($slug !== $entry) {
            $issues[] = [
                'slug' => $entry,
                'message' => 'Theme folder name contains unsupported characters. Use lowercase letters, numbers, hyphens, or underscores.',
            ];
            continue;
        }
        $manifestPath = $path . '/theme.json';
        if (!is_file($manifestPath)) {
            $issues[] = [
                'slug' => $entry,
                'message' => 'Missing theme.json manifest.',
            ];
            continue;
        }
        $decoded = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($decoded)) {
            $issues[] = [
                'slug' => $entry,
                'message' => 'theme.json is not valid JSON.',
            ];
            continue;
        }
        $manifestSlug = bms_theme_slug_or_empty((string)($decoded['slug'] ?? ''));
        if ($manifestSlug === '') {
            $issues[] = [
                'slug' => $entry,
                'message' => 'theme.json is missing a valid slug.',
            ];
            continue;
        }
        if ($manifestSlug !== $slug) {
            $issues[] = [
                'slug' => $entry,
                'message' => 'theme.json slug does not match the theme folder name.',
            ];
        }
    }

    return $issues;
}

function bms_public_theme_packages(): array
{
    $themes = [];
    $root = bms_themes_path();
    if (is_dir($root)) {
        foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
            $slug = bms_theme_slug($entry);
            if ($slug !== $entry || !is_dir($root . '/' . $entry)) {
                continue;
            }
            $manifest = bms_read_theme_manifest($slug);
            if ($manifest !== null) {
                $manifest['health'] = bms_public_theme_package_health($manifest);
                $themes[$slug] = $manifest;
            }
        }
    }

    if (!isset($themes['default'])) {
        $themes['default'] = [
            'slug' => 'default',
            'name' => 'Midnight Ledger',
            'version' => '1.0.0',
            'author' => 'Bonumark',
            'description' => 'The default Bonumark Stream public theme, a restrained dark editorial design for readable short-form publishing.',
            'screenshot' => '',
            'supports' => ['profiles' => true, 'comments' => true, 'avatars' => true, 'media' => true],
            'assets' => ['css' => [], 'images' => [], 'fonts' => []],
            'settings' => [],
        ];
        $themes['default']['health'] = bms_public_theme_package_health($themes['default']);
    }

    ksort($themes);
    return $themes;
}

function bms_active_public_theme_slug(): string
{
    $configured = (string)bms_setting_or_config('active_public_theme', 'default');
    $slug = bms_theme_slug($configured);
    $themes = bms_public_theme_packages();
    if (isset($themes[$slug]) && bms_public_theme_can_activate($themes[$slug])) {
        return $slug;
    }
    if (isset($themes['default']) && bms_public_theme_can_activate($themes['default'])) {
        return 'default';
    }
    return 'default';
}

function bms_active_public_theme(): array
{
    $themes = bms_public_theme_packages();
    $slug = bms_active_public_theme_slug();
    return $themes[$slug] ?? $themes['default'] ?? reset($themes);
}

function bms_active_public_theme_name(): string
{
    return (string)(bms_active_public_theme()['name'] ?? 'Midnight Ledger');
}

function bms_public_theme_asset_url(string $assetPath, ?string $slug = null): string
{
    $assetPath = trim(str_replace('\\', '/', $assetPath));
    $assetPath = ltrim($assetPath, '/');
    if ($assetPath === '' || preg_match('#(^|/)\.\.(/|$)#', $assetPath) === 1) {
        return '';
    }

    if (preg_match('#^(https?://|/)#i', $assetPath) === 1) {
        return bms_asset_url(ltrim($assetPath, '/'));
    }

    $themeSlug = bms_theme_slug($slug ?? bms_active_public_theme_slug());
    return bms_asset_url('assets/themes/' . $themeSlug . '/' . $assetPath);
}

function bms_public_theme_screenshot_url(array|string|null $theme = null): string
{
    if (is_string($theme)) {
        $theme = bms_read_theme_manifest($theme);
    }
    if (!is_array($theme)) {
        $theme = bms_active_public_theme();
    }

    $screenshot = (string)($theme['screenshot'] ?? '');
    if ($screenshot === '') {
        return '';
    }

    return bms_public_theme_asset_url($screenshot, (string)($theme['slug'] ?? bms_active_public_theme_slug()));
}

function bms_public_theme_settings_schema(?string $slug = null): array
{
    $theme = $slug !== null ? bms_read_theme_manifest($slug) : bms_active_public_theme();
    if (!is_array($theme)) {
        return [];
    }
    return is_array($theme['settings'] ?? null) ? $theme['settings'] : [];
}

function bms_public_theme_settings_storage_key(string $slug): string
{
    return 'public_theme_settings_' . bms_theme_slug($slug);
}

function bms_sanitize_public_theme_setting_value(array $definition, mixed $value): string
{
    $type = (string)($definition['type'] ?? 'text');
    if ($type === 'checkbox') {
        return !empty($value) && (string)$value !== '0' ? '1' : '0';
    }

    if ($type === 'select') {
        $value = (string)$value;
        $options = is_array($definition['options'] ?? null) ? $definition['options'] : [];
        return array_key_exists($value, $options) ? $value : (string)($definition['default'] ?? '');
    }

    $value = trim((string)$value);
    $limit = $type === 'textarea' ? 1000 : 255;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }
    return substr($value, 0, $limit);
}

function bms_default_public_theme_settings(?string $slug = null): array
{
    $defaults = [];
    foreach (bms_public_theme_settings_schema($slug) as $key => $definition) {
        $defaults[$key] = (string)($definition['default'] ?? '');
    }
    return $defaults;
}

function bms_public_theme_settings(?string $slug = null): array
{
    $slug = bms_theme_slug($slug ?? bms_active_public_theme_slug());
    $schema = bms_public_theme_settings_schema($slug);
    $values = bms_default_public_theme_settings($slug);
    if (!$schema) {
        return $values;
    }

    $storedRaw = (string)bms_setting_or_config(bms_public_theme_settings_storage_key($slug), '');
    $stored = json_decode($storedRaw, true);
    if (is_array($stored)) {
        foreach ($schema as $key => $definition) {
            if (array_key_exists($key, $stored)) {
                $values[$key] = bms_sanitize_public_theme_setting_value($definition, $stored[$key]);
            }
        }
    }

    return $values;
}

function bms_public_theme_setting(string $key, mixed $default = '', ?string $slug = null): mixed
{
    $key = bms_theme_setting_key($key);
    if ($key === '') {
        return $default;
    }
    $values = bms_public_theme_settings($slug);
    return array_key_exists($key, $values) ? $values[$key] : $default;
}

function bms_save_public_theme_settings(string $slug, array $rawSettings): void
{
    $slug = bms_theme_slug($slug);
    $schema = bms_public_theme_settings_schema($slug);
    $values = [];
    foreach ($schema as $key => $definition) {
        if (array_key_exists($key, $rawSettings)) {
            $rawValue = $rawSettings[$key];
        } elseif (($definition['type'] ?? '') === 'checkbox') {
            $rawValue = '0';
        } else {
            $rawValue = $definition['default'] ?? '';
        }
        $values[$key] = bms_sanitize_public_theme_setting_value($definition, $rawValue);
    }

    bms_set_setting(bms_public_theme_settings_storage_key($slug), json_encode($values, JSON_UNESCAPED_SLASHES));
}

function bms_public_theme_supports_list(array $theme): array
{
    $supports = is_array($theme['supports'] ?? null) ? $theme['supports'] : [];
    $labels = [];
    foreach ($supports as $key => $enabled) {
        if (!$enabled) {
            continue;
        }
        $labelKey = strtolower((string)$key);
        $label = match ($labelKey) {
            'rss' => 'RSS',
            'theme_settings' => 'Theme Settings',
            default => ucwords(str_replace(['_', '-'], ' ', (string)$key)),
        };
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    sort($labels);
    return $labels;
}


function bms_public_theme_bundled_slugs(): array
{
    return ['default'];
}

function bms_public_theme_is_bundled(string $slug): bool
{
    return in_array(bms_theme_slug($slug), bms_public_theme_bundled_slugs(), true);
}

function bms_public_theme_delete_status(string $slug): array
{
    $slug = bms_theme_slug_or_empty($slug);
    $packages = bms_public_theme_packages();
    $active = bms_active_public_theme_slug();
    $privatePath = $slug !== '' ? bms_themes_path($slug) : '';
    $publicAssetPath = $slug !== '' ? bms_public_theme_asset_path($slug) : '';

    $errors = [];
    if ($slug === '') {
        $errors[] = 'Invalid theme slug.';
    }
    if ($slug !== '' && !isset($packages[$slug]) && !is_dir($privatePath)) {
        $errors[] = 'Theme does not exist.';
    }
    if ($slug === $active) {
        $errors[] = 'The active theme cannot be deleted. Activate another valid theme first.';
    }
    if (bms_public_theme_is_bundled($slug)) {
        $errors[] = 'Protected themes cannot be deleted.';
    }

    return [
        'slug' => $slug,
        'can_delete' => empty($errors),
        'errors' => $errors,
        'private_path' => $privatePath,
        'public_asset_path' => $publicAssetPath,
    ];
}

function bms_public_theme_can_delete(string $slug): bool
{
    $status = bms_public_theme_delete_status($slug);
    return !empty($status['can_delete']);
}

function bms_public_theme_delete_error(string $slug): string
{
    $status = bms_public_theme_delete_status($slug);
    $errors = is_array($status['errors'] ?? null) ? $status['errors'] : [];
    return $errors ? implode(' ', $errors) : 'Theme cannot be deleted.';
}

function bms_public_theme_assert_safe_delete_target(string $target, string $root, string $label): void
{
    if (!is_dir($target)) {
        return;
    }

    $rootReal = realpath($root);
    $targetReal = realpath($target);
    if ($rootReal === false || $targetReal === false) {
        throw new RuntimeException('Could not resolve ' . $label . ' path safely.');
    }

    $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');
    $targetReal = rtrim(str_replace('\\', '/', $targetReal), '/');
    if ($targetReal === $rootReal || !str_starts_with($targetReal . '/', $rootReal . '/')) {
        throw new RuntimeException('Refusing to delete unsafe ' . $label . ' path.');
    }
}


function bms_public_theme_delete_directory_tree(string $dir, string $root, string $label): void
{
    if (!is_dir($dir)) {
        return;
    }

    bms_public_theme_assert_safe_delete_target($dir, $root, $label);
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Could not delete ' . $label . ' file: ' . $item);
            }
            continue;
        }
        if (is_dir($path)) {
            bms_public_theme_delete_directory_tree($path, $root, $label);
            continue;
        }
        if (file_exists($path) && !unlink($path)) {
            throw new RuntimeException('Could not delete ' . $label . ' item: ' . $item);
        }
    }

    if (!rmdir($dir)) {
        throw new RuntimeException('Could not remove ' . $label . ' directory.');
    }
}

function bms_delete_setting_record(string $key): void
{
    if (!bms_is_installed() || !function_exists('bms_db') || !function_exists('bms_table')) {
        return;
    }

    try {
        $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('settings') . ' WHERE setting_key = :setting_key');
        $stmt->execute(['setting_key' => $key]);
    } catch (Throwable $e) {
        // Deleting theme files is the important operation. A stale settings row is harmless.
    }
}

function bms_delete_public_theme(string $slug): array
{
    $status = bms_public_theme_delete_status($slug);
    if (empty($status['can_delete'])) {
        throw new RuntimeException(bms_public_theme_delete_error($slug));
    }

    $slug = (string)$status['slug'];
    $privatePath = (string)$status['private_path'];
    $publicAssetPath = (string)$status['public_asset_path'];
    $deleted = [];

    if (is_dir($privatePath)) {
        bms_public_theme_delete_directory_tree($privatePath, bms_themes_path(), 'theme');
        $deleted[] = '_bonumark_stream/themes/' . $slug;
    }

    if (is_dir($publicAssetPath)) {
        bms_public_theme_delete_directory_tree($publicAssetPath, bms_public_theme_asset_path(), 'theme assets');
        $deleted[] = 'assets/themes/' . $slug;
    }

    bms_delete_setting_record(bms_public_theme_settings_storage_key($slug));

    return [
        'slug' => $slug,
        'deleted' => $deleted,
    ];
}


function bms_public_theme_asset_inventory(array $theme): array
{
    $slug = bms_theme_slug((string)($theme['slug'] ?? 'default'));
    $rows = [];
    $assets = is_array($theme['assets'] ?? null) ? $theme['assets'] : ['css' => [], 'images' => [], 'fonts' => []];
    foreach (['css', 'images', 'fonts'] as $type) {
        foreach ((array)($assets[$type] ?? []) as $asset) {
            $asset = bms_theme_asset_reference((string)$asset);
            if ($asset === '') {
                continue;
            }
            $rows[] = [
                'type' => bms_theme_asset_group_label($type),
                'file' => $asset,
                'exists' => is_file(bms_public_theme_asset_file_path($slug, $asset)),
                'url' => bms_public_theme_asset_url($asset, $slug),
            ];
        }
    }

    $screenshot = bms_theme_asset_reference((string)($theme['screenshot'] ?? ''));
    if ($screenshot !== '') {
        $rows[] = [
            'type' => 'Screenshot',
            'file' => $screenshot,
            'exists' => is_file(bms_public_theme_asset_file_path($slug, $screenshot)),
            'url' => bms_public_theme_asset_url($screenshot, $slug),
        ];
    }

    return $rows;
}

function bms_public_theme_status_class(array $theme): string
{
    $health = is_array($theme['health'] ?? null) ? $theme['health'] : bms_public_theme_package_health($theme);
    if (empty($health['valid'])) {
        return 'needs-attention';
    }
    $warnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];
    return $warnings ? 'warning' : 'ready';
}

function bms_activate_public_theme(string $slug): array
{
    $slug = bms_theme_slug($slug);
    $packages = bms_public_theme_packages();
    if (!isset($packages[$slug])) {
        throw new RuntimeException('Theme does not exist.');
    }
    if (!bms_public_theme_can_activate($packages[$slug])) {
        throw new RuntimeException(bms_public_theme_activation_error($packages[$slug]));
    }

    bms_set_setting('active_public_theme', $slug);
    return $packages[$slug];
}

function bms_public_theme_manager_summary(array $theme): array
{
    $health = is_array($theme['health'] ?? null) ? $theme['health'] : bms_public_theme_package_health($theme);
    $errors = is_array($health['errors'] ?? null) ? $health['errors'] : [];
    $warnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];
    $assets = bms_public_theme_asset_inventory($theme);

    return [
        'valid' => !empty($health['valid']),
        'label' => (string)($health['label'] ?? (!empty($health['valid']) ? 'Ready' : 'Needs attention')),
        'status_class' => bms_public_theme_status_class($theme),
        'errors' => $errors,
        'warnings' => $warnings,
        'asset_total' => count($assets),
        'asset_missing' => count(array_filter($assets, static fn($row) => empty($row['exists']))),
        'setting_total' => count((array)($theme['settings'] ?? [])),
        'support_total' => count(bms_public_theme_supports_list($theme)),
    ];
}

function bms_public_theme_stylesheet_links(): string
{
    $theme = bms_active_public_theme();
    $css = $theme['assets']['css'] ?? [];
    if (!is_array($css)) {
        return '';
    }

    $links = '';
    foreach ($css as $asset) {
        $url = bms_public_theme_asset_url((string)$asset, (string)$theme['slug']);
        if ($url !== '') {
            $links .= '  <link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
    }
    return $links;
}

function bms_public_theme_script_tags(): string
{
    // Presentation themes cannot ship JavaScript. Public behavior belongs to
    // Bonumark Stream core assets such as assets/stream.js.
    return '';
}

function bms_public_theme_template_path(string $template, ?string $slug = null): string
{
    $template = strtolower(trim($template));
    $template = preg_replace('/[^a-z0-9_-]+/', '-', $template) ?? '';
    $template = trim($template, '-_');
    $template = $template !== '' ? $template : 'layout';
    $coreView = bms_theme_slug($slug ?? 'default');
    return bms_root_path('app/views/' . $coreView . '/templates/' . $template . '.php');
}

function bms_public_head_has_favicon_tags(string $headHtml): bool
{
    return preg_match('/<link\s+[^>]*rel=["\']?(?:shortcut\s+)?icon(?:["\']|\s|>)/i', $headHtml) === 1
        || preg_match('/<link\s+[^>]*rel=["\']?apple-touch-icon(?:["\']|\s|>)/i', $headHtml) === 1;
}

function bms_inject_public_favicon_tags(string $html): string
{
    if ($html === '' || !function_exists('bms_site_favicon_tags')) {
        return $html;
    }

    $faviconTags = bms_site_favicon_tags();
    if (trim($faviconTags) === '') {
        return $html;
    }

    if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $matches) !== 1) {
        return $html;
    }

    $headHtml = (string)($matches[1] ?? '');
    if (bms_public_head_has_favicon_tags($headHtml)) {
        return $html;
    }

    return preg_replace('/<\/head>/i', rtrim($faviconTags) . "
</head>", $html, 1) ?? $html;
}


function bms_public_head_replace_or_add_tag(string $html, string $pattern, string $replacement): string
{
    if (preg_match($pattern, $html) === 1) {
        return preg_replace($pattern, $replacement, $html, 1) ?? $html;
    }

    return preg_replace('/<\/head>/i', $replacement . "\n</head>", $html, 1) ?? $html;
}

function bms_inject_public_seo_head(string $html, array $data): string
{
    if ($html === '' || preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html) !== 1) {
        return $html;
    }

    $documentTitle = trim((string)($data['seo_document_title'] ?? ''));
    $socialTitle = trim((string)($data['seo_social_title'] ?? ''));
    if ($documentTitle === '' && function_exists('bms_seo_document_title')) {
        $documentTitle = bms_seo_document_title((string)($data['title'] ?? $data['page_title'] ?? ''));
    }
    if ($socialTitle === '') {
        $socialTitle = $documentTitle;
    }

    if ($documentTitle !== '') {
        $safeTitle = htmlspecialchars($documentTitle, ENT_QUOTES, 'UTF-8');
        if (preg_match('/<title\b[^>]*>.*?<\/title>/is', $html) === 1) {
            $html = preg_replace('/<title\b[^>]*>.*?<\/title>/is', '<title>' . $safeTitle . '</title>', $html, 1) ?? $html;
        } else {
            $html = preg_replace('/<head\b([^>]*)>/i', '<head$1>' . "\n  <title>" . $safeTitle . '</title>', $html, 1) ?? $html;
        }
    }

    if ($socialTitle !== '') {
        $safeSocialTitle = htmlspecialchars($socialTitle, ENT_QUOTES, 'UTF-8');
        $html = bms_public_head_replace_or_add_tag(
            $html,
            '/<meta\s+[^>]*property=["\']og:title["\'][^>]*>/i',
            '<meta property="og:title" content="' . $safeSocialTitle . '">'
        );
        $html = bms_public_head_replace_or_add_tag(
            $html,
            '/<meta\s+[^>]*name=["\']twitter:title["\'][^>]*>/i',
            '<meta name="twitter:title" content="' . $safeSocialTitle . '">'
        );
    }

    return $html;
}

function bms_render_public_theme_template(string $template, array $data = []): ?string
{
    $template = bms_theme_template_reference($template);
    if ($template === '') {
        $template = 'layout';
    }

    $activeTheme = bms_active_public_theme();
    $activeSlug = bms_theme_slug((string)($activeTheme['slug'] ?? bms_active_public_theme_slug()));
    $coreView = bms_public_theme_core_view_slug($activeTheme);
    $path = bms_public_theme_template_path($template, $coreView);

    if (!is_file($path)) {
        $fallbackPath = bms_public_theme_template_path($template, 'default');
        if (is_file($fallbackPath)) {
            $path = $fallbackPath;
            $coreView = 'default';
        }
    }

    if (!is_file($path)) {
        throw new RuntimeException('Bonumark Stream core is missing required render file: app/views/' . $coreView . '/templates/' . $template . '.php');
    }

    $data['theme'] = $data['theme'] ?? $activeTheme;
    $data['theme_settings'] = $data['theme_settings'] ?? bms_public_theme_settings($activeSlug);
    $data['theme_core_view'] = $data['theme_core_view'] ?? $coreView;
    $data['favicon_tags'] = $data['favicon_tags'] ?? (function_exists('bms_site_favicon_tags') ? bms_site_favicon_tags() : '');
    if (function_exists('bms_public_seo_view_data')) {
        $data = bms_public_seo_view_data($template, $data);
    }

    $bms_theme_data = $data;
    ob_start();
    include $path;
    $html = (string)ob_get_clean();
    $html = bms_inject_public_seo_head($html, $data);
    return bms_inject_public_favicon_tags($html);
}
