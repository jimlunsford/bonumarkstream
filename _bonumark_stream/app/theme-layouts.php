<?php
/**
 * Declarative Layout Themes foundation.
 *
 * This file provides the validated, code-free composition contract used by
 * layout-aware public surfaces. Core still owns every component renderer,
 * wrapper, semantic element, accessibility behavior, and application action.
 */

function bms_theme_layout_supported_schema_versions(): array
{
    return [1];
}

function bms_theme_layout_supported_surfaces(): array
{
    return ['profile', 'stream-card', 'site-header', 'home'];
}

function bms_theme_layout_surface_label(string $surface): string
{
    return match (strtolower(trim($surface))) {
        'profile' => 'Profile',
        'stream-card' => 'Stream Card',
        'site-header' => 'Site Header',
        default => ucwords(str_replace(['_', '-'], ' ', trim($surface))),
    };
}

function bms_theme_layout_renderer_label(array $theme): string
{
    return !empty($theme['layout_aware']) ? 'Declarative Layouts' : 'Legacy Core Renderer';
}

function bms_theme_layout_declared_surfaces(array $theme): array
{
    $layouts = is_array($theme['layouts'] ?? null) ? $theme['layouts'] : [];
    $surfaces = [];
    foreach (array_keys($layouts) as $surface) {
        $surface = strtolower(trim((string)$surface));
        if ($surface !== '' && !in_array($surface, $surfaces, true)) {
            $surfaces[] = $surface;
        }
    }
    sort($surfaces);
    return $surfaces;
}

function bms_theme_layout_component_registry(): array
{
    return [
        'profile' => [
            'profile.cover' => ['required' => true, 'max' => 1, 'template' => 'profile/cover.php'],
            'profile.avatar' => ['required' => true, 'max' => 1, 'template' => 'profile/avatar.php'],
            'profile.identity' => ['required' => true, 'max' => 1, 'template' => 'profile/identity.php'],
            'profile.about' => ['required' => true, 'max' => 1, 'template' => 'profile/about.php'],
            'profile.featured' => ['required' => true, 'max' => 1, 'template' => 'profile/featured.php'],
            'profile.photos' => ['required' => true, 'max' => 1, 'template' => 'profile/photos.php'],
            'profile.now' => ['required' => true, 'max' => 1, 'template' => 'profile/now.php'],
            'profile.interests' => ['required' => true, 'max' => 1, 'template' => 'profile/interests.php'],
            'profile.links' => ['required' => true, 'max' => 1, 'template' => 'profile/links.php'],
            'profile.details' => ['required' => true, 'max' => 1, 'template' => 'profile/details.php'],
        ],
        // Stream Card components remain core-owned even when a theme opts into
        // the Schema 1 stream-card composition surface.
        'stream-card' => [
            'stream-card.avatar' => ['required' => true, 'max' => 1, 'template' => 'stream-card/avatar.php'],
            'stream-card.header' => ['required' => true, 'max' => 1, 'template' => 'stream-card/header.php'],
            'stream-card.body' => ['required' => true, 'max' => 1, 'template' => 'stream-card/body.php'],
            'stream-card.location' => ['required' => true, 'max' => 1, 'template' => 'stream-card/location.php'],
            'stream-card.link-preview' => ['required' => true, 'max' => 1, 'template' => 'stream-card/link-preview.php'],
            'stream-card.media' => ['required' => true, 'max' => 1, 'template' => 'stream-card/media.php'],
            'stream-card.actions' => ['required' => true, 'max' => 1, 'template' => 'stream-card/actions.php'],
        ],
        // Site Header components remain core-owned when a theme opts into the
        // Schema 1 site-header composition surface.
        'site-header' => [
            'site-header.site-identity' => ['required' => true, 'max' => 1, 'template' => 'site-header/site-identity.php'],
            'site-header.primary-navigation' => ['required' => true, 'max' => 1, 'template' => 'site-header/primary-navigation.php'],
            'site-header.menu-toggle' => ['required' => false, 'max' => 1, 'template' => 'site-header/menu-toggle.php'],
            'site-header.stream-count' => ['required' => false, 'max' => 1, 'template' => 'site-header/stream-count.php'],
        ],
        // Home components remain core-owned when a theme opts into the
        // Schema 1 home composition surface.
        'home' => [
            'home.notices' => ['required' => true, 'max' => 1, 'template' => 'home/notices.php'],
            'home.composer' => ['required' => true, 'max' => 1, 'template' => 'home/composer.php'],
            'home.pinned-posts' => ['required' => true, 'max' => 1, 'template' => 'home/pinned-posts.php'],
            'home.feed' => ['required' => true, 'max' => 1, 'template' => 'home/feed.php'],
            'home.pagination' => ['required' => true, 'max' => 1, 'template' => 'home/pagination.php'],
        ],
    ];
}

function bms_theme_layout_reference(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || strlen($path) > 255 || str_contains($path, chr(0))) {
        return '';
    }
    if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 || str_starts_with($path, '//')) {
        return '';
    }
    if (preg_match('#(^|/)\.\.?(/|$)#', $path) === 1 || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
        return '';
    }
    if (!str_starts_with($path, 'layouts/') || !str_ends_with(strtolower($path), '.json')) {
        return '';
    }
    if (preg_match('#^layouts/[a-z0-9][a-z0-9_-]*\.json$#', $path) !== 1) {
        return '';
    }
    return $path;
}

function bms_theme_layout_manifest_errors(array $decoded): array
{
    $hasSchema = array_key_exists('layout_schema', $decoded);
    $hasLayouts = array_key_exists('layouts', $decoded);
    if (!$hasSchema && !$hasLayouts) {
        return [];
    }

    $errors = [];
    $rawSchema = $decoded['layout_schema'] ?? null;
    if (!is_int($rawSchema) && !(is_string($rawSchema) && preg_match('/^[0-9]+$/', $rawSchema) === 1)) {
        $errors[] = 'theme.json layout_schema must be an integer.';
        $schema = 0;
    } else {
        $schema = (int)$rawSchema;
        if (!in_array($schema, bms_theme_layout_supported_schema_versions(), true)) {
            $errors[] = 'theme.json layout_schema is not supported by this Bonumark Stream version.';
        }
    }

    $layouts = $decoded['layouts'] ?? null;
    if (!is_array($layouts) || array_is_list($layouts) || $layouts === []) {
        $errors[] = 'theme.json layouts must be a non-empty object keyed by supported surface.';
        return array_values(array_unique($errors));
    }

    $supported = bms_theme_layout_supported_surfaces();
    foreach ($layouts as $surface => $path) {
        $surface = strtolower(trim((string)$surface));
        if (!in_array($surface, $supported, true)) {
            $errors[] = 'theme.json declares an unsupported layout surface: ' . ($surface !== '' ? $surface : '(empty)') . '.';
            continue;
        }
        if (!is_string($path) || bms_theme_layout_reference($path) === '') {
            $errors[] = 'Invalid declarative layout path for surface ' . $surface . '. Layout files must be private JSON files inside layouts/.';
        }
    }

    return array_values(array_unique($errors));
}

function bms_normalize_theme_layout_manifest(array $decoded): array
{
    $schema = null;
    if (array_key_exists('layout_schema', $decoded)) {
        $rawSchema = $decoded['layout_schema'];
        if (is_int($rawSchema) || (is_string($rawSchema) && preg_match('/^[0-9]+$/', $rawSchema) === 1)) {
            $schema = (int)$rawSchema;
        }
    }

    $layouts = [];
    $rawLayouts = $decoded['layouts'] ?? [];
    if (is_array($rawLayouts) && !array_is_list($rawLayouts)) {
        foreach ($rawLayouts as $surface => $path) {
            $surface = strtolower(trim((string)$surface));
            $reference = is_string($path) ? bms_theme_layout_reference($path) : '';
            if ($surface !== '' && $reference !== '') {
                $layouts[$surface] = $reference;
            }
        }
    }

    return [
        'layout_schema' => $schema,
        'layouts' => $layouts,
        'layout_aware' => $schema !== null && $layouts !== [],
    ];
}

function bms_theme_layout_group_name(string $name): string
{
    $name = strtolower(trim($name));
    return preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name) === 1 ? $name : '';
}

function bms_theme_layout_document_errors(array $document, string $surface, int $schema = 1): array
{
    $errors = [];
    if (!in_array($schema, bms_theme_layout_supported_schema_versions(), true)) {
        return ['Layout schema is not supported by this Bonumark Stream version.'];
    }
    if (!in_array($surface, bms_theme_layout_supported_surfaces(), true)) {
        return ['Layout surface is not supported: ' . $surface . '.'];
    }

    $allowedTopLevel = ['surface', 'root'];
    foreach (array_keys($document) as $key) {
        if (!in_array((string)$key, $allowedTopLevel, true)) {
            $errors[] = 'Layout contains unsupported top-level property: ' . (string)$key . '.';
        }
    }

    if ((string)($document['surface'] ?? '') !== $surface) {
        $errors[] = 'Layout surface must match the theme.json declaration: ' . $surface . '.';
    }

    $root = $document['root'] ?? null;
    if (!is_array($root)) {
        $errors[] = 'Layout root must be a group node.';
        return array_values(array_unique($errors));
    }

    $componentCounts = [];
    $nodeCount = 0;
    $errors = array_merge($errors, bms_theme_layout_node_errors($root, $surface, 1, $nodeCount, $componentCounts));
    if (($root['type'] ?? '') !== 'group') {
        $errors[] = 'Layout root must be a group node.';
    }

    $registry = bms_theme_layout_component_registry()[$surface] ?? [];
    foreach ($registry as $component => $definition) {
        $count = (int)($componentCounts[$component] ?? 0);
        if (!empty($definition['required']) && $count === 0) {
            $errors[] = 'Layout is missing required component: ' . $component . '.';
        }
        $max = (int)($definition['max'] ?? 1);
        if ($max > 0 && $count > $max) {
            $errors[] = 'Layout includes component too many times: ' . $component . '.';
        }
    }

    return array_values(array_unique($errors));
}

function bms_theme_layout_node_errors(array $node, string $surface, int $depth, int &$nodeCount, array &$componentCounts): array
{
    $errors = [];
    $nodeCount++;
    if ($nodeCount > 64) {
        return ['Layout exceeds the maximum of 64 nodes.'];
    }
    if ($depth > 8) {
        return ['Layout exceeds the maximum nesting depth of 8.'];
    }

    $type = strtolower(trim((string)($node['type'] ?? '')));
    if ($type === 'group') {
        foreach (array_keys($node) as $key) {
            if (!in_array((string)$key, ['type', 'name', 'children'], true)) {
                $errors[] = 'Group node contains unsupported property: ' . (string)$key . '.';
            }
        }
        if (bms_theme_layout_group_name((string)($node['name'] ?? '')) === '') {
            $errors[] = 'Group node name is missing or invalid.';
        }
        $children = $node['children'] ?? null;
        if (!is_array($children) || !array_is_list($children)) {
            $errors[] = 'Group node children must be a list.';
            return $errors;
        }
        if (count($children) > 32) {
            $errors[] = 'Group node exceeds the maximum of 32 children.';
        }
        foreach (array_slice($children, 0, 32) as $child) {
            if (!is_array($child)) {
                $errors[] = 'Layout child nodes must be objects.';
                continue;
            }
            $errors = array_merge($errors, bms_theme_layout_node_errors($child, $surface, $depth + 1, $nodeCount, $componentCounts));
        }
        return $errors;
    }

    if ($type === 'component') {
        foreach (array_keys($node) as $key) {
            if (!in_array((string)$key, ['type', 'name'], true)) {
                $errors[] = 'Component node contains unsupported property: ' . (string)$key . '.';
            }
        }
        $name = trim((string)($node['name'] ?? ''));
        $registry = bms_theme_layout_component_registry()[$surface] ?? [];
        if ($name === '' || !array_key_exists($name, $registry)) {
            $errors[] = 'Layout references unknown component for ' . $surface . ': ' . ($name !== '' ? $name : '(empty)') . '.';
        } else {
            $componentCounts[$name] = (int)($componentCounts[$name] ?? 0) + 1;
        }
        return $errors;
    }

    $errors[] = 'Layout node type must be group or component.';
    return $errors;
}

function bms_theme_layout_file_errors(array $theme, string $privateThemeRoot): array
{
    $schema = $theme['layout_schema'] ?? null;
    $layouts = is_array($theme['layouts'] ?? null) ? $theme['layouts'] : [];
    if ($schema === null && $layouts === []) {
        return [];
    }
    if (!is_int($schema) || !in_array($schema, bms_theme_layout_supported_schema_versions(), true)) {
        return ['Theme layout schema is missing or unsupported.'];
    }

    $errors = [];
    foreach ($layouts as $surface => $reference) {
        $reference = bms_theme_layout_reference((string)$reference);
        if ($reference === '') {
            $errors[] = 'Invalid declarative layout path for surface ' . (string)$surface . '.';
            continue;
        }
        $path = rtrim($privateThemeRoot, '/\\') . '/' . $reference;
        if (!is_file($path)) {
            $errors[] = 'Missing declared layout file: ' . $reference . '.';
            continue;
        }
        $size = filesize($path);
        if ($size === false || $size > 131072) {
            $errors[] = 'Layout file exceeds the 128 KB safety limit: ' . $reference . '.';
            continue;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            $errors[] = 'Layout file is not valid JSON: ' . $reference . '.';
            continue;
        }
        foreach (bms_theme_layout_document_errors($decoded, (string)$surface, $schema) as $error) {
            $errors[] = $reference . ': ' . $error;
        }
    }
    return array_values(array_unique($errors));
}

/**
 * Resolve a registered core-owned public component definition.
 *
 * Theme layout documents may name registered components, but they never choose
 * PHP files or callbacks. The mapping remains entirely inside Bonumark core.
 */
function bms_theme_layout_component_definition(string $component): ?array
{
    $component = trim($component);
    if ($component === '') {
        return null;
    }

    foreach (bms_theme_layout_component_registry() as $surface => $components) {
        if (isset($components[$component]) && is_array($components[$component])) {
            $definition = $components[$component];
            $definition['surface'] = (string)$surface;
            return $definition;
        }
    }

    return null;
}

function bms_theme_layout_component_template_path(string $component): string
{
    $definition = bms_theme_layout_component_definition($component);
    $reference = is_array($definition) ? trim((string)($definition['template'] ?? '')) : '';
    if ($reference === '' || preg_match('#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*\.php$#', $reference) !== 1) {
        return '';
    }

    return __DIR__ . '/views/default/components/' . $reference;
}

/**
 * Render one core-owned component with already-prepared public view data.
 *
 * Components are presentation-only PHP files shipped by Bonumark Stream. They
 * do not fetch application data and they are never supplied by a theme.
 */
function bms_render_core_public_component(string $component, array $data): string
{
    $path = bms_theme_layout_component_template_path($component);
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Bonumark Stream core is missing registered public component: ' . $component);
    }

    $bms_component_data = $data;
    ob_start();
    include $path;
    return (string)ob_get_clean();
}


/**
 * Return true when a normalized theme explicitly declares a layout for a
 * supported public surface. Legacy themes return false and remain on the
 * existing fixed core composition.
 */
function bms_public_theme_declares_layout_surface(array $theme, string $surface): bool
{
    $surface = strtolower(trim($surface));
    if ($surface === '' || empty($theme['layout_aware'])) {
        return false;
    }

    $schema = $theme['layout_schema'] ?? null;
    $layouts = is_array($theme['layouts'] ?? null) ? $theme['layouts'] : [];
    return is_int($schema)
        && in_array($schema, bms_theme_layout_supported_schema_versions(), true)
        && isset($layouts[$surface])
        && bms_theme_layout_reference((string)$layouts[$surface]) !== '';
}

/**
 * Load and revalidate one private declarative layout document for rendering.
 * Theme files remain data only. A declared but invalid document is treated as
 * a runtime integrity failure rather than silently becoming executable input.
 */
function bms_public_theme_layout_document(array $theme, string $surface): ?array
{
    $surface = strtolower(trim($surface));
    if (!bms_public_theme_declares_layout_surface($theme, $surface)) {
        return null;
    }

    $slug = bms_theme_slug_or_empty((string)($theme['slug'] ?? ''));
    $schema = (int)($theme['layout_schema'] ?? 0);
    $reference = bms_theme_layout_reference((string)(($theme['layouts'] ?? [])[$surface] ?? ''));
    if ($slug === '' || $reference === '') {
        throw new RuntimeException('Active theme has an invalid declarative layout reference for ' . $surface . '.');
    }

    $path = bms_themes_path($slug . '/' . $reference);
    if (!is_file($path)) {
        throw new RuntimeException('Active theme is missing declared layout file: ' . $reference . '.');
    }

    $size = filesize($path);
    if ($size === false || $size > 131072) {
        throw new RuntimeException('Active theme layout exceeds the 128 KB safety limit: ' . $reference . '.');
    }

    $document = json_decode((string)file_get_contents($path), true);
    if (!is_array($document)) {
        throw new RuntimeException('Active theme layout is not valid JSON: ' . $reference . '.');
    }

    $errors = bms_theme_layout_document_errors($document, $surface, $schema);
    if ($errors !== []) {
        throw new RuntimeException('Active theme layout failed validation: ' . implode(' ', $errors));
    }

    return $document;
}

function bms_theme_layout_html_token(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace('.', '-', $value);
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
    $value = trim($value, '-_');
    return $value !== '' ? substr($value, 0, 96) : 'item';
}

function bms_theme_layout_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Render a previously validated layout node. Core owns every wrapper and
 * component. The layout may select only registered component names and safe
 * group names, never HTML, attributes, callbacks, expressions, or behavior.
 */
function bms_render_public_theme_layout_node(array $node, string $surface, int $schema, array $data, bool $isRoot = false): string
{
    $type = strtolower(trim((string)($node['type'] ?? '')));
    if ($type === 'component') {
        $component = trim((string)($node['name'] ?? ''));
        $definition = bms_theme_layout_component_definition($component);
        if (!is_array($definition) || (string)($definition['surface'] ?? '') !== $surface) {
            throw new RuntimeException('Declarative layout attempted to render an unregistered component: ' . $component . '.');
        }

        $componentHtml = bms_render_core_public_component($component, $data);
        if (trim($componentHtml) === '') {
            return '';
        }

        $componentToken = bms_theme_layout_html_token($component);
        return '<div class="bms-layout-component bms-component-' . bms_theme_layout_html_escape($componentToken) . '" data-bms-component="' . bms_theme_layout_html_escape($component) . '">' . $componentHtml . '</div>';
    }

    if ($type !== 'group') {
        throw new RuntimeException('Declarative layout attempted to render an unsupported node type.');
    }

    $group = bms_theme_layout_group_name((string)($node['name'] ?? ''));
    if ($group === '') {
        throw new RuntimeException('Declarative layout attempted to render an invalid group name.');
    }

    $childrenHtml = '';
    foreach ((array)($node['children'] ?? []) as $child) {
        if (!is_array($child)) {
            throw new RuntimeException('Declarative layout contains an invalid child node.');
        }
        $childrenHtml .= bms_render_public_theme_layout_node($child, $surface, $schema, $data, false);
    }

    if (!$isRoot && trim($childrenHtml) === '') {
        return '';
    }

    $groupToken = bms_theme_layout_html_token($group);
    if ($isRoot) {
        $surfaceToken = bms_theme_layout_html_token($surface);
        return '<div class="bms-layout bms-layout-' . bms_theme_layout_html_escape($surfaceToken) . ' bms-layout-group bms-layout-group-' . bms_theme_layout_html_escape($groupToken) . '" data-bms-layout="' . bms_theme_layout_html_escape($surface) . '" data-bms-layout-schema="' . $schema . '" data-bms-layout-group="' . bms_theme_layout_html_escape($group) . '">' . $childrenHtml . '</div>';
    }

    return '<div class="bms-layout-group bms-layout-group-' . bms_theme_layout_html_escape($groupToken) . '" data-bms-layout-group="' . bms_theme_layout_html_escape($group) . '">' . $childrenHtml . '</div>';
}

/**
 * Render one public surface through the active theme's validated declarative
 * composition. Returns null for legacy themes or surfaces they do not declare.
 */
function bms_render_public_theme_layout_surface(string $surface, array $data, ?array $theme = null): ?string
{
    $surface = strtolower(trim($surface));
    if ($surface === '' || !in_array($surface, bms_theme_layout_supported_surfaces(), true)) {
        return null;
    }

    if (!is_array($theme)) {
        $theme = is_array($data['theme'] ?? null) ? $data['theme'] : bms_active_public_theme();
    }
    if (!bms_public_theme_declares_layout_surface($theme, $surface)) {
        return null;
    }

    $document = bms_public_theme_layout_document($theme, $surface);
    if (!is_array($document)) {
        return null;
    }

    $schema = (int)($theme['layout_schema'] ?? 0);
    $root = $document['root'] ?? null;
    if (!is_array($root)) {
        throw new RuntimeException('Declarative layout root is unavailable for ' . $surface . '.');
    }

    return bms_render_public_theme_layout_node($root, $surface, $schema, $data, true);
}

