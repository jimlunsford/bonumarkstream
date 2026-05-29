<?php
require_once __DIR__ . '/functions.php';

function mp_theme_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-_');
    return $slug !== '' ? substr($slug, 0, 64) : 'default';
}

function mp_theme_slug_or_empty(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-_');
    return $slug !== '' ? substr($slug, 0, 64) : '';
}

function mp_theme_setting_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? '';
    $key = trim($key, '_');
    return $key !== '' ? substr($key, 0, 64) : '';
}

function mp_themes_path(string $path = ''): string
{
    return mp_root_path('themes' . ($path ? '/' . ltrim($path, '/') : ''));
}

function mp_public_theme_asset_path(string $path = ''): string
{
    return mp_public_path('assets/themes' . ($path ? '/' . ltrim($path, '/') : ''));
}

function mp_theme_asset_reference(string $assetPath): string
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


function mp_theme_template_reference(string $template): string
{
    $template = strtolower(trim(str_replace('\\', '/', $template)));
    $template = basename($template);
    $template = preg_replace('/[^a-z0-9_-]+/', '-', $template) ?? '';
    $template = trim($template, '-_');
    return $template !== '' ? substr($template, 0, 64) : '';
}

function mp_normalize_theme_assets(array $rawAssets): array
{
    $assets = ['css' => [], 'js' => []];
    foreach (['css', 'js'] as $type) {
        $rawList = $rawAssets[$type] ?? [];
        if (!is_array($rawList)) {
            continue;
        }
        foreach ($rawList as $asset) {
            $reference = mp_theme_asset_reference((string)$asset);
            if ($reference !== '' && !in_array($reference, $assets[$type], true)) {
                $assets[$type][] = $reference;
            }
        }
    }
    return $assets;
}

function mp_theme_manifest_asset_errors(array $decoded): array
{
    $errors = [];
    $assets = $decoded['assets'] ?? [];
    if ($assets !== [] && !is_array($assets)) {
        return ['theme.json assets must be an object.'];
    }

    if (is_array($assets)) {
        foreach (['css', 'js'] as $type) {
            if (!array_key_exists($type, $assets)) {
                continue;
            }
            if (!is_array($assets[$type])) {
                $errors[] = 'theme.json ' . strtoupper($type) . ' assets must be a list.';
                continue;
            }
            foreach ($assets[$type] as $asset) {
                $raw = trim((string)$asset);
                if ($raw === '' || mp_theme_asset_reference($raw) === '') {
                    $errors[] = 'Invalid ' . strtoupper($type) . ' asset path in theme.json.';
                }
            }
        }
    }

    $rawScreenshot = trim((string)($decoded['screenshot'] ?? ''));
    if ($rawScreenshot !== '' && mp_theme_asset_reference($rawScreenshot) === '') {
        $errors[] = 'Invalid screenshot path in theme.json.';
    }

    return array_values(array_unique($errors));
}

function mp_public_theme_required_templates(): array
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

function mp_public_theme_declared_templates(array $theme): array
{
    $templates = [];
    foreach ((array)($theme['templates'] ?? []) as $template) {
        $template = mp_theme_template_reference((string)$template);
        if ($template !== '' && !in_array($template, $templates, true)) {
            $templates[] = $template;
        }
    }
    return $templates;
}

function mp_public_theme_installable_templates(array $theme): array
{
    return array_values(array_unique(array_merge(mp_public_theme_required_templates(), mp_public_theme_declared_templates($theme))));
}

function mp_public_theme_asset_file_path(string $slug, string $asset): string
{
    $asset = mp_theme_asset_reference($asset);
    if ($asset === '') {
        return '';
    }
    return mp_public_theme_asset_path(mp_theme_slug($slug) . '/' . $asset);
}

function mp_public_theme_package_health_at_paths(array $theme, string $privateThemeRoot, string $publicAssetRoot): array
{
    $errors = is_array($theme['manifest_errors'] ?? null) ? array_values((array)$theme['manifest_errors']) : [];
    $warnings = [];
    $requiredTemplates = mp_public_theme_required_templates();
    $missingTemplates = [];
    $declaredTemplates = [];

    foreach ((array)($theme['templates'] ?? []) as $template) {
        $template = mp_theme_template_reference((string)$template);
        if ($template !== '' && !in_array($template, $declaredTemplates, true)) {
            $declaredTemplates[] = $template;
        }
    }

    foreach ($requiredTemplates as $template) {
        $path = rtrim($privateThemeRoot, '/\\') . '/templates/' . $template . '.php';
        if (!is_file($path)) {
            $missingTemplates[] = $template;
        }
        if (!in_array($template, $declaredTemplates, true)) {
            $warnings[] = 'Required template is not declared in theme.json: ' . $template . '.php';
        }
    }

    foreach ($missingTemplates as $template) {
        $errors[] = 'Missing required template: templates/' . $template . '.php';
    }

    foreach ($declaredTemplates as $template) {
        $path = rtrim($privateThemeRoot, '/\\') . '/templates/' . $template . '.php';
        if (!is_file($path)) {
            $warnings[] = 'Declared template file is missing: templates/' . $template . '.php';
        }
    }

    $assets = is_array($theme['assets'] ?? null) ? $theme['assets'] : [];
    foreach (['css', 'js'] as $type) {
        foreach ((array)($assets[$type] ?? []) as $asset) {
            $asset = mp_theme_asset_reference((string)$asset);
            if ($asset === '') {
                $errors[] = 'Invalid ' . strtoupper($type) . ' asset path in theme.json.';
                continue;
            }
            $assetPath = rtrim($publicAssetRoot, '/\\') . '/' . $asset;
            if (!is_file($assetPath)) {
                $errors[] = 'Missing declared ' . strtoupper($type) . ' asset: ' . $asset;
            }
        }
    }

    $screenshot = mp_theme_asset_reference((string)($theme['screenshot'] ?? ''));
    if ($screenshot !== '') {
        $screenshotPath = rtrim($publicAssetRoot, '/\\') . '/' . $screenshot;
        if (!is_file($screenshotPath)) {
            $errors[] = 'Missing declared screenshot: ' . $screenshot;
        }
    } else {
        $warnings[] = 'No screenshot is declared in theme.json.';
    }

    foreach (['name', 'version', 'author', 'description'] as $field) {
        if (trim((string)($theme[$field] ?? '')) === '') {
            $errors[] = 'Missing required manifest field: ' . $field;
        }
    }

    $status = empty($errors) ? 'valid' : 'invalid';
    return [
        'valid' => $status === 'valid',
        'status' => $status,
        'label' => $status === 'valid' ? 'Safe to activate' : 'Not safe to activate',
        'errors' => array_values(array_unique($errors)),
        'warnings' => array_values(array_unique($warnings)),
        'required_templates' => $requiredTemplates,
        'declared_templates' => $declaredTemplates,
        'missing_templates' => $missingTemplates,
    ];
}

function mp_public_theme_package_health(array $theme): array
{
    $slug = mp_theme_slug((string)($theme['slug'] ?? 'default'));
    return mp_public_theme_package_health_at_paths(
        $theme,
        mp_themes_path($slug),
        mp_public_theme_asset_path($slug)
    );
}

function mp_public_theme_can_activate(array $theme): bool
{
    $health = is_array($theme['health'] ?? null) ? $theme['health'] : mp_public_theme_package_health($theme);
    return !empty($health['valid']);
}

function mp_public_theme_activation_error(array $theme): string
{
    $health = is_array($theme['health'] ?? null) ? $theme['health'] : mp_public_theme_package_health($theme);
    $errors = is_array($health['errors'] ?? null) ? $health['errors'] : [];
    if (!$errors) {
        return '';
    }
    return 'The selected theme is not safe to activate: ' . implode(' ', $errors);
}

function mp_normalize_theme_settings_schema(array $rawSettings): array
{
    $schema = [];
    foreach ($rawSettings as $rawKey => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $keySource = is_string($rawKey) ? $rawKey : (string)($definition['key'] ?? '');
        $key = mp_theme_setting_key($keySource);
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

function mp_read_theme_manifest_file(string $manifestPath, ?string $expectedSlug = null): ?array
{
    if (!is_file($manifestPath)) {
        return null;
    }

    $decoded = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($decoded)) {
        return null;
    }

    $manifestSlug = mp_theme_slug_or_empty((string)($decoded['slug'] ?? ''));
    if ($manifestSlug === '') {
        return null;
    }

    $expected = $expectedSlug !== null ? mp_theme_slug_or_empty($expectedSlug) : $manifestSlug;
    if ($expected === '' || $manifestSlug !== $expected) {
        return null;
    }

    $decoded['slug'] = $manifestSlug;
    $decoded['name'] = trim((string)($decoded['name'] ?? 'Midnight Ledger')) ?: 'Midnight Ledger';
    $decoded['version'] = trim((string)($decoded['version'] ?? '1.0.0')) ?: '1.0.0';
    $decoded['author'] = trim((string)($decoded['author'] ?? 'Bonumark')) ?: 'Bonumark';
    $decoded['description'] = trim((string)($decoded['description'] ?? 'A Bonumark Stream public theme.')) ?: 'A Bonumark Stream public theme.';
    $decoded['manifest_errors'] = mp_theme_manifest_asset_errors($decoded);
    $decoded['screenshot'] = mp_theme_asset_reference((string)($decoded['screenshot'] ?? ''));
    $decoded['supports'] = is_array($decoded['supports'] ?? null) ? $decoded['supports'] : [];
    $decoded['assets'] = is_array($decoded['assets'] ?? null) ? mp_normalize_theme_assets($decoded['assets']) : ['css' => [], 'js' => []];
    $rawTemplates = is_array($decoded['templates'] ?? null) ? $decoded['templates'] : [];
    $decoded['templates'] = [];
    foreach ($rawTemplates as $template) {
        $template = mp_theme_template_reference((string)$template);
        if ($template !== '' && !in_array($template, $decoded['templates'], true)) {
            $decoded['templates'][] = $template;
        }
    }
    $decoded['settings'] = is_array($decoded['settings'] ?? null) ? mp_normalize_theme_settings_schema($decoded['settings']) : [];

    return $decoded;
}

function mp_read_theme_manifest(string $slug): ?array
{
    $slug = mp_theme_slug($slug);
    return mp_read_theme_manifest_file(mp_themes_path($slug . '/theme.json'), $slug);
}

function mp_public_theme_discovery_issues(): array
{
    $issues = [];
    $root = mp_themes_path();
    if (!is_dir($root)) {
        return $issues;
    }

    foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
        $path = $root . '/' . $entry;
        if (!is_dir($path)) {
            continue;
        }
        $slug = mp_theme_slug($entry);
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
        $manifestSlug = mp_theme_slug_or_empty((string)($decoded['slug'] ?? ''));
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

function mp_public_theme_packages(): array
{
    $themes = [];
    $root = mp_themes_path();
    if (is_dir($root)) {
        foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
            $slug = mp_theme_slug($entry);
            if ($slug !== $entry || !is_dir($root . '/' . $entry)) {
                continue;
            }
            $manifest = mp_read_theme_manifest($slug);
            if ($manifest !== null) {
                $manifest['health'] = mp_public_theme_package_health($manifest);
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
            'assets' => [],
            'templates' => [],
            'settings' => [],
        ];
        $themes['default']['health'] = mp_public_theme_package_health($themes['default']);
    }

    ksort($themes);
    return $themes;
}

function mp_active_public_theme_slug(): string
{
    $configured = (string)mp_setting_or_config('active_public_theme', 'default');
    $slug = mp_theme_slug($configured);
    $themes = mp_public_theme_packages();
    if (isset($themes[$slug]) && mp_public_theme_can_activate($themes[$slug])) {
        return $slug;
    }
    if (isset($themes['default']) && mp_public_theme_can_activate($themes['default'])) {
        return 'default';
    }
    return 'default';
}

function mp_active_public_theme(): array
{
    $themes = mp_public_theme_packages();
    $slug = mp_active_public_theme_slug();
    return $themes[$slug] ?? $themes['default'] ?? reset($themes);
}

function mp_active_public_theme_name(): string
{
    return (string)(mp_active_public_theme()['name'] ?? 'Midnight Ledger');
}

function mp_public_theme_asset_url(string $assetPath, ?string $slug = null): string
{
    $assetPath = trim(str_replace('\\', '/', $assetPath));
    $assetPath = ltrim($assetPath, '/');
    if ($assetPath === '' || preg_match('#(^|/)\.\.(/|$)#', $assetPath) === 1) {
        return '';
    }

    if (preg_match('#^(https?://|/)#i', $assetPath) === 1) {
        return mp_asset_url(ltrim($assetPath, '/'));
    }

    $themeSlug = mp_theme_slug($slug ?? mp_active_public_theme_slug());
    return mp_asset_url('assets/themes/' . $themeSlug . '/' . $assetPath);
}

function mp_public_theme_screenshot_url(array|string|null $theme = null): string
{
    if (is_string($theme)) {
        $theme = mp_read_theme_manifest($theme);
    }
    if (!is_array($theme)) {
        $theme = mp_active_public_theme();
    }

    $screenshot = (string)($theme['screenshot'] ?? '');
    if ($screenshot === '') {
        return '';
    }

    return mp_public_theme_asset_url($screenshot, (string)($theme['slug'] ?? mp_active_public_theme_slug()));
}

function mp_public_theme_settings_schema(?string $slug = null): array
{
    $theme = $slug !== null ? mp_read_theme_manifest($slug) : mp_active_public_theme();
    if (!is_array($theme)) {
        return [];
    }
    return is_array($theme['settings'] ?? null) ? $theme['settings'] : [];
}

function mp_public_theme_settings_storage_key(string $slug): string
{
    return 'public_theme_settings_' . mp_theme_slug($slug);
}

function mp_sanitize_public_theme_setting_value(array $definition, mixed $value): string
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

function mp_default_public_theme_settings(?string $slug = null): array
{
    $defaults = [];
    foreach (mp_public_theme_settings_schema($slug) as $key => $definition) {
        $defaults[$key] = (string)($definition['default'] ?? '');
    }
    return $defaults;
}

function mp_public_theme_settings(?string $slug = null): array
{
    $slug = mp_theme_slug($slug ?? mp_active_public_theme_slug());
    $schema = mp_public_theme_settings_schema($slug);
    $values = mp_default_public_theme_settings($slug);
    if (!$schema) {
        return $values;
    }

    $storedRaw = (string)mp_setting_or_config(mp_public_theme_settings_storage_key($slug), '');
    $stored = json_decode($storedRaw, true);
    if (is_array($stored)) {
        foreach ($schema as $key => $definition) {
            if (array_key_exists($key, $stored)) {
                $values[$key] = mp_sanitize_public_theme_setting_value($definition, $stored[$key]);
            }
        }
    }

    return $values;
}

function mp_public_theme_setting(string $key, mixed $default = '', ?string $slug = null): mixed
{
    $key = mp_theme_setting_key($key);
    if ($key === '') {
        return $default;
    }
    $values = mp_public_theme_settings($slug);
    return array_key_exists($key, $values) ? $values[$key] : $default;
}

function mp_save_public_theme_settings(string $slug, array $rawSettings): void
{
    $slug = mp_theme_slug($slug);
    $schema = mp_public_theme_settings_schema($slug);
    $values = [];
    foreach ($schema as $key => $definition) {
        if (array_key_exists($key, $rawSettings)) {
            $rawValue = $rawSettings[$key];
        } elseif (($definition['type'] ?? '') === 'checkbox') {
            $rawValue = '0';
        } else {
            $rawValue = $definition['default'] ?? '';
        }
        $values[$key] = mp_sanitize_public_theme_setting_value($definition, $rawValue);
    }

    mp_set_setting(mp_public_theme_settings_storage_key($slug), json_encode($values, JSON_UNESCAPED_SLASHES));
}

function mp_public_theme_supports_list(array $theme): array
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


function mp_public_theme_bundled_slugs(): array
{
    return ['default'];
}

function mp_public_theme_is_bundled(string $slug): bool
{
    return in_array(mp_theme_slug($slug), mp_public_theme_bundled_slugs(), true);
}

function mp_public_theme_delete_status(string $slug): array
{
    $slug = mp_theme_slug_or_empty($slug);
    $packages = mp_public_theme_packages();
    $active = mp_active_public_theme_slug();
    $privatePath = $slug !== '' ? mp_themes_path($slug) : '';
    $publicAssetPath = $slug !== '' ? mp_public_theme_asset_path($slug) : '';

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
    if (mp_public_theme_is_bundled($slug)) {
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

function mp_public_theme_can_delete(string $slug): bool
{
    $status = mp_public_theme_delete_status($slug);
    return !empty($status['can_delete']);
}

function mp_public_theme_delete_error(string $slug): string
{
    $status = mp_public_theme_delete_status($slug);
    $errors = is_array($status['errors'] ?? null) ? $status['errors'] : [];
    return $errors ? implode(' ', $errors) : 'Theme cannot be deleted.';
}

function mp_public_theme_assert_safe_delete_target(string $target, string $root, string $label): void
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


function mp_public_theme_delete_directory_tree(string $dir, string $root, string $label): void
{
    if (!is_dir($dir)) {
        return;
    }

    mp_public_theme_assert_safe_delete_target($dir, $root, $label);
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Could not delete ' . $label . ' file: ' . $item);
            }
            continue;
        }
        if (is_dir($path)) {
            mp_public_theme_delete_directory_tree($path, $root, $label);
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

function mp_delete_setting_record(string $key): void
{
    if (!mp_is_installed() || !function_exists('mp_db') || !function_exists('mp_table')) {
        return;
    }

    try {
        $stmt = mp_db()->prepare('DELETE FROM ' . mp_table('settings') . ' WHERE setting_key = :setting_key');
        $stmt->execute(['setting_key' => $key]);
    } catch (Throwable $e) {
        // Deleting theme files is the important operation. A stale settings row is harmless.
    }
}

function mp_delete_public_theme(string $slug): array
{
    $status = mp_public_theme_delete_status($slug);
    if (empty($status['can_delete'])) {
        throw new RuntimeException(mp_public_theme_delete_error($slug));
    }

    $slug = (string)$status['slug'];
    $privatePath = (string)$status['private_path'];
    $publicAssetPath = (string)$status['public_asset_path'];
    $deleted = [];

    if (is_dir($privatePath)) {
        mp_public_theme_delete_directory_tree($privatePath, mp_themes_path(), 'theme');
        $deleted[] = '_bonumark_stream/themes/' . $slug;
    }

    if (is_dir($publicAssetPath)) {
        mp_public_theme_delete_directory_tree($publicAssetPath, mp_public_theme_asset_path(), 'theme assets');
        $deleted[] = 'assets/themes/' . $slug;
    }

    mp_delete_setting_record(mp_public_theme_settings_storage_key($slug));

    return [
        'slug' => $slug,
        'deleted' => $deleted,
    ];
}


function mp_public_theme_template_inventory(array $theme): array
{
    $slug = mp_theme_slug((string)($theme['slug'] ?? 'default'));
    $declared = [];
    foreach ((array)($theme['templates'] ?? []) as $template) {
        $template = mp_theme_template_reference((string)$template);
        if ($template !== '' && !in_array($template, $declared, true)) {
            $declared[] = $template;
        }
    }

    $rows = [];
    foreach (mp_public_theme_required_templates() as $template) {
        $path = mp_themes_path($slug . '/templates/' . $template . '.php');
        $rows[] = [
            'template' => $template,
            'file' => 'templates/' . $template . '.php',
            'declared' => in_array($template, $declared, true),
            'exists' => is_file($path),
            'required' => true,
        ];
    }

    foreach ($declared as $template) {
        if (in_array($template, mp_public_theme_required_templates(), true)) {
            continue;
        }
        $path = mp_themes_path($slug . '/templates/' . $template . '.php');
        $rows[] = [
            'template' => $template,
            'file' => 'templates/' . $template . '.php',
            'declared' => true,
            'exists' => is_file($path),
            'required' => false,
        ];
    }

    return $rows;
}

function mp_public_theme_asset_inventory(array $theme): array
{
    $slug = mp_theme_slug((string)($theme['slug'] ?? 'default'));
    $rows = [];
    $assets = is_array($theme['assets'] ?? null) ? $theme['assets'] : ['css' => [], 'js' => []];
    foreach (['css', 'js'] as $type) {
        foreach ((array)($assets[$type] ?? []) as $asset) {
            $asset = mp_theme_asset_reference((string)$asset);
            if ($asset === '') {
                continue;
            }
            $rows[] = [
                'type' => strtoupper($type),
                'file' => $asset,
                'exists' => is_file(mp_public_theme_asset_file_path($slug, $asset)),
                'url' => mp_public_theme_asset_url($asset, $slug),
            ];
        }
    }

    $screenshot = mp_theme_asset_reference((string)($theme['screenshot'] ?? ''));
    if ($screenshot !== '') {
        $rows[] = [
            'type' => 'Screenshot',
            'file' => $screenshot,
            'exists' => is_file(mp_public_theme_asset_file_path($slug, $screenshot)),
            'url' => mp_public_theme_asset_url($screenshot, $slug),
        ];
    }

    return $rows;
}

function mp_public_theme_status_class(array $theme): string
{
    $health = is_array($theme['health'] ?? null) ? $theme['health'] : mp_public_theme_package_health($theme);
    if (empty($health['valid'])) {
        return 'needs-attention';
    }
    $warnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];
    return $warnings ? 'warning' : 'ready';
}

function mp_activate_public_theme(string $slug): array
{
    $slug = mp_theme_slug($slug);
    $packages = mp_public_theme_packages();
    if (!isset($packages[$slug])) {
        throw new RuntimeException('Theme does not exist.');
    }
    if (!mp_public_theme_can_activate($packages[$slug])) {
        throw new RuntimeException(mp_public_theme_activation_error($packages[$slug]));
    }

    mp_set_setting('active_public_theme', $slug);
    return $packages[$slug];
}

function mp_public_theme_manager_summary(array $theme): array
{
    $health = is_array($theme['health'] ?? null) ? $theme['health'] : mp_public_theme_package_health($theme);
    $errors = is_array($health['errors'] ?? null) ? $health['errors'] : [];
    $warnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];
    $templates = mp_public_theme_template_inventory($theme);
    $assets = mp_public_theme_asset_inventory($theme);

    return [
        'valid' => !empty($health['valid']),
        'label' => (string)($health['label'] ?? (!empty($health['valid']) ? 'Ready' : 'Needs attention')),
        'status_class' => mp_public_theme_status_class($theme),
        'errors' => $errors,
        'warnings' => $warnings,
        'template_total' => count($templates),
        'template_missing' => count(array_filter($templates, static fn($row) => empty($row['exists']))),
        'asset_total' => count($assets),
        'asset_missing' => count(array_filter($assets, static fn($row) => empty($row['exists']))),
        'setting_total' => count((array)($theme['settings'] ?? [])),
        'support_total' => count(mp_public_theme_supports_list($theme)),
    ];
}

function mp_public_theme_stylesheet_links(): string
{
    $theme = mp_active_public_theme();
    $css = $theme['assets']['css'] ?? [];
    if (!is_array($css)) {
        return '';
    }

    $links = '';
    foreach ($css as $asset) {
        $url = mp_public_theme_asset_url((string)$asset, (string)$theme['slug']);
        if ($url !== '') {
            $links .= '  <link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
    }
    return $links;
}

function mp_public_theme_script_tags(): string
{
    $theme = mp_active_public_theme();
    $js = $theme['assets']['js'] ?? [];
    if (!is_array($js)) {
        return '';
    }

    $tags = '';
    foreach ($js as $asset) {
        $url = mp_public_theme_asset_url((string)$asset, (string)$theme['slug']);
        if ($url !== '') {
            $tags .= '  <script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
        }
    }
    return $tags;
}

function mp_public_theme_template_path(string $template, ?string $slug = null): string
{
    $template = strtolower(trim($template));
    $template = preg_replace('/[^a-z0-9_-]+/', '-', $template) ?? '';
    $template = trim($template, '-_');
    $template = $template !== '' ? $template : 'layout';
    $themeSlug = mp_theme_slug($slug ?? mp_active_public_theme_slug());
    return mp_themes_path($themeSlug . '/templates/' . $template . '.php');
}

function mp_public_head_has_favicon_tags(string $headHtml): bool
{
    return preg_match('/<link\s+[^>]*rel=["\']?(?:shortcut\s+)?icon(?:["\']|\s|>)/i', $headHtml) === 1
        || preg_match('/<link\s+[^>]*rel=["\']?apple-touch-icon(?:["\']|\s|>)/i', $headHtml) === 1;
}

function mp_inject_public_favicon_tags(string $html): string
{
    if ($html === '' || !function_exists('mp_site_favicon_tags')) {
        return $html;
    }

    $faviconTags = mp_site_favicon_tags();
    if (trim($faviconTags) === '') {
        return $html;
    }

    if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $matches) !== 1) {
        return $html;
    }

    $headHtml = (string)($matches[1] ?? '');
    if (mp_public_head_has_favicon_tags($headHtml)) {
        return $html;
    }

    return preg_replace('/<\/head>/i', rtrim($faviconTags) . "
</head>", $html, 1) ?? $html;
}


function mp_public_head_replace_or_add_tag(string $html, string $pattern, string $replacement): string
{
    if (preg_match($pattern, $html) === 1) {
        return preg_replace($pattern, $replacement, $html, 1) ?? $html;
    }

    return preg_replace('/<\/head>/i', $replacement . "\n</head>", $html, 1) ?? $html;
}

function mp_inject_public_seo_head(string $html, array $data): string
{
    if ($html === '' || preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html) !== 1) {
        return $html;
    }

    $documentTitle = trim((string)($data['seo_document_title'] ?? ''));
    $socialTitle = trim((string)($data['seo_social_title'] ?? ''));
    if ($documentTitle === '' && function_exists('mp_seo_document_title')) {
        $documentTitle = mp_seo_document_title((string)($data['title'] ?? $data['page_title'] ?? ''));
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
        $html = mp_public_head_replace_or_add_tag(
            $html,
            '/<meta\s+[^>]*property=["\']og:title["\'][^>]*>/i',
            '<meta property="og:title" content="' . $safeSocialTitle . '">'
        );
        $html = mp_public_head_replace_or_add_tag(
            $html,
            '/<meta\s+[^>]*name=["\']twitter:title["\'][^>]*>/i',
            '<meta name="twitter:title" content="' . $safeSocialTitle . '">'
        );
    }

    return $html;
}

function mp_render_public_theme_template(string $template, array $data = []): ?string
{
    $template = mp_theme_template_reference($template);
    if ($template === '') {
        $template = 'layout';
    }

    $activeSlug = mp_active_public_theme_slug();
    $path = mp_public_theme_template_path($template, $activeSlug);
    $renderSlug = $activeSlug;

    if (!is_file($path)) {
        foreach (['default'] as $fallbackSlug) {
            if ($activeSlug === $fallbackSlug) {
                continue;
            }
            $fallbackPath = mp_public_theme_template_path($template, $fallbackSlug);
            if (is_file($fallbackPath)) {
                $path = $fallbackPath;
                $renderSlug = $fallbackSlug;
                break;
            }
        }
    }

    if (!is_file($path)) {
        throw new RuntimeException('Active public theme is missing required template: templates/' . $template . '.php');
    }

    $data['theme'] = $data['theme'] ?? ($renderSlug === $activeSlug ? mp_active_public_theme() : (mp_public_theme_packages()[$renderSlug] ?? mp_active_public_theme()));
    $data['theme_settings'] = $data['theme_settings'] ?? mp_public_theme_settings($renderSlug);
    $data['favicon_tags'] = $data['favicon_tags'] ?? (function_exists('mp_site_favicon_tags') ? mp_site_favicon_tags() : '');
    if (function_exists('mp_public_seo_view_data')) {
        $data = mp_public_seo_view_data($template, $data);
    }

    $mp_theme_data = $data;
    ob_start();
    include $path;
    $html = (string)ob_get_clean();
    $html = mp_inject_public_seo_head($html, $data);
    return mp_inject_public_favicon_tags($html);
}
