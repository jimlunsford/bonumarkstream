<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/themes.php';

function bms_theme_options(): array
{
    // Deprecated legacy dark/light visual-mode options. Public theme packages now
    // own their own settings through theme.json.
    return [];
}

function bms_public_theme(): string
{
    // Backward-compatible helper for old code paths. New code should use
    // bms_active_public_theme_slug().
    return function_exists('bms_active_public_theme_slug') ? bms_active_public_theme_slug() : 'default';
}

function bms_public_theme_name(): string
{
    return bms_public_theme_package_name();
}

function bms_public_theme_package_name(): string
{
    return function_exists('bms_active_public_theme_name') ? bms_active_public_theme_name() : 'Midnight Ledger';
}

function bms_public_theme_class(string $context = ''): string
{
    $themeSlug = function_exists('bms_active_public_theme_slug') ? bms_active_public_theme_slug() : 'default';
    $themeSlug = preg_replace('/[^a-z0-9_-]+/i', '-', $themeSlug) ?: 'default';
    $context = preg_replace('/[^a-z0-9_-]+/i', '-', $context) ?: 'site';
    return trim('bonumark-public public-theme-' . strtolower($themeSlug) . ' context-' . strtolower($context));
}

function bms_homepage_eyebrow(): string
{
    return trim((string)bms_setting_or_config('homepage_eyebrow', 'Own your short-form publishing'));
}

function bms_site_footer_text(): string
{
    return trim((string)bms_setting_or_config('site_footer_text', ''));
}

function bms_show_powered_by(): bool
{
    return (string)bms_setting_or_config('show_powered_by', '1') === '1';
}






function bms_site_favicon_media_id(): int
{
    return max(0, (int)bms_setting_or_config('site_favicon_media_id', '0'));
}

function bms_site_favicon_path(): string
{
    $path = trim((string)bms_setting_or_config('site_favicon_path', ''));
    $path = str_replace('\\', '/', $path);
    $path = trim($path, '/');
    if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
        return '';
    }
    return str_starts_with($path, 'media/') ? $path : '';
}

function bms_site_favicon_is_image(array $media): bool
{
    if (function_exists('bms_media_is_trashed') && bms_media_is_trashed($media)) {
        return false;
    }
    $publicPath = trim((string)($media['public_path'] ?? ''));
    $mime = strtolower(trim((string)($media['mime_type'] ?? '')));
    if ($publicPath === '' || !str_starts_with($publicPath, 'media/')) {
        return false;
    }
    if ($mime !== '' && !str_starts_with($mime, 'image/')) {
        return false;
    }
    $extension = strtolower(pathinfo($publicPath, PATHINFO_EXTENSION));
    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function bms_site_favicon_media(): ?array
{
    $id = bms_site_favicon_media_id();
    if ($id > 0 && function_exists('bms_media_find')) {
        $media = bms_media_find($id);
        if (is_array($media) && bms_site_favicon_is_image($media)) {
            return $media;
        }
    }

    $path = bms_site_favicon_path();
    if ($path !== '' && function_exists('bms_media_find_by_public_path')) {
        $media = bms_media_find_by_public_path($path);
        if (is_array($media) && bms_site_favicon_is_image($media)) {
            return $media;
        }
    }

    return null;
}

function bms_site_favicon_view_data(): array
{
    $media = bms_site_favicon_media();
    if (!$media) {
        $path = bms_site_favicon_path();
        if ($path !== '') {
            $file = function_exists('bms_public_path') ? bms_public_path($path) : '';
            if ($file !== '' && is_file($file)) {
                $info = @getimagesize($file);
                $width = is_array($info) ? (int)($info[0] ?? 0) : 0;
                $height = is_array($info) ? (int)($info[1] ?? 0) : 0;
                $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
                $isSquare = $width > 0 && $height > 0 && abs($width - $height) <= 2;
                return [
                    'media' => null,
                    'id' => 0,
                    'url' => function_exists('bms_url_path') ? bms_url_path($path) : '',
                    'path' => $path,
                    'mime' => $mime,
                    'width' => $width,
                    'height' => $height,
                    'is_square' => $isSquare,
                    'apple_touch_icon' => $isSquare && $width >= 180 && $height >= 180,
                ];
            }
        }
        return [
            'media' => null,
            'id' => 0,
            'url' => '',
            'path' => '',
            'mime' => '',
            'width' => 0,
            'height' => 0,
            'is_square' => false,
            'apple_touch_icon' => false,
        ];
    }

    $width = max(0, (int)($media['width'] ?? 0));
    $height = max(0, (int)($media['height'] ?? 0));
    $isSquare = $width > 0 && $height > 0 && abs($width - $height) <= 2;
    return [
        'media' => $media,
        'id' => (int)($media['id'] ?? 0),
        'url' => function_exists('bms_media_public_url_for_item') ? bms_media_public_url_for_item($media) : '',
        'path' => (string)($media['public_path'] ?? ''),
        'mime' => (string)($media['mime_type'] ?? ''),
        'width' => $width,
        'height' => $height,
        'is_square' => $isSquare,
        'apple_touch_icon' => $isSquare && $width >= 180 && $height >= 180,
    ];
}

function bms_site_favicon_tags(): string
{
    $favicon = bms_site_favicon_view_data();
    $url = trim((string)($favicon['url'] ?? ''));
    if ($url === '') {
        return '';
    }

    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $mime = trim((string)($favicon['mime'] ?? ''));
    $typeAttribute = $mime !== '' ? ' type="' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . '"' : '';
    $tags = '  <link rel="icon" href="' . $safeUrl . '"' . $typeAttribute . '>' . "\n";
    $tags .= '  <link rel="shortcut icon" href="' . $safeUrl . '"' . $typeAttribute . '>' . "\n";
    if (!empty($favicon['apple_touch_icon'])) {
        $tags .= '  <link rel="apple-touch-icon" href="' . $safeUrl . '">' . "\n";
    }
    return $tags;
}

function bms_default_navigation_items(): array
{
    return [
        ['label' => 'Home', 'url' => '/', 'target' => '_self', 'order' => 10, 'source' => 'system'],
    ];
}

function bms_public_navigation_enabled(): bool
{
    return (string)bms_setting_or_config('primary_navigation_enabled', '0') === '1';
}

function bms_save_public_navigation_enabled(bool $enabled): void
{
    bms_set_setting('primary_navigation_enabled', $enabled ? '1' : '0');
}

function bms_public_navigation_account_links_enabled(): bool
{
    return (string)bms_setting_or_config('public_navigation_account_links_enabled', '1') === '1';
}

function bms_save_public_navigation_account_links_enabled(bool $enabled): void
{
    bms_set_setting('public_navigation_account_links_enabled', $enabled ? '1' : '0');
}

function bms_normalize_navigation_order(mixed $value, int $fallback): int
{
    if (is_numeric($value)) {
        return max(0, min(9999, (int)$value));
    }
    return max(0, min(9999, $fallback));
}

function bms_normalize_navigation_item(array $item, int $position = 0): ?array
{
    $label = trim((string)($item['label'] ?? ''));
    $url = trim((string)($item['url'] ?? ''));
    if ($label === '' || $url === '') {
        return null;
    }

    $target = (string)($item['target'] ?? '_self');
    $source = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($item['source'] ?? 'custom')) ?: 'custom';
    $objectType = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($item['object_type'] ?? '')) ?: '';
    $objectSlug = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($item['object_slug'] ?? '')) ?: '';

    return [
        'label' => substr($label, 0, 80),
        'url' => bms_sanitize_navigation_url($url),
        'target' => $target === '_blank' ? '_blank' : '_self',
        'order' => ($position + 1) * 10,
        'source' => substr(strtolower($source), 0, 40),
        'object_type' => substr(strtolower($objectType), 0, 40),
        'object_slug' => substr(strtolower($objectSlug), 0, 190),
        '_position' => $position,
    ];
}

function bms_sort_navigation_items(array $items): array
{
    usort($items, function (array $a, array $b): int {
        $orderCompare = ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0));
        if ($orderCompare !== 0) {
            return $orderCompare;
        }
        return ((int)($a['_position'] ?? 0)) <=> ((int)($b['_position'] ?? 0));
    });

    return array_values(array_map(function (array $item): array {
        unset($item['_position']);
        return $item;
    }, $items));
}

function bms_navigation_items(): array
{
    $raw = (string)bms_setting_or_config('primary_navigation', '');
    if ($raw === '') {
        return bms_sort_navigation_items(bms_default_navigation_items());
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return bms_sort_navigation_items(bms_default_navigation_items());
    }

    $items = [];
    foreach ($decoded as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized = bms_normalize_navigation_item($item, (int)$index);
        if ($normalized !== null) {
            $items[] = $normalized;
        }
    }

    return bms_sort_navigation_items($items);
}

function bms_navigation_url_key(string $url): string
{
    $resolved = bms_resolve_navigation_url($url);
    $parts = parse_url($resolved);
    if (!is_array($parts)) {
        return strtolower(trim($resolved));
    }

    $prefix = '';
    if (!empty($parts['scheme']) || !empty($parts['host'])) {
        $prefix = strtolower((string)($parts['scheme'] ?? '')) . '://' . strtolower((string)($parts['host'] ?? ''));
    }

    $path = (string)($parts['path'] ?? '/');
    $path = '/' . trim($path, '/');
    if ($path === '/') {
        $path = '/';
    }

    $query = (string)($parts['query'] ?? '');
    if ($query !== '') {
        parse_str($query, $queryParts);
        unset($queryParts['csrf_token'], $queryParts['_csrf']);
        ksort($queryParts);
        $query = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
    }

    $fragment = (string)($parts['fragment'] ?? '');
    return strtolower($prefix . $path . ($query !== '' ? '?' . $query : '') . ($fragment !== '' ? '#' . $fragment : ''));
}

function bms_navigation_has_url(array $items, string $url): bool
{
    $needle = bms_navigation_url_key($url);
    foreach ($items as $item) {
        if (bms_navigation_url_key((string)($item['url'] ?? '')) === $needle) {
            return true;
        }
    }
    return false;
}

function bms_public_navigation_account_items(): array
{
    if (!function_exists('bms_is_logged_in')) {
        return [];
    }

    $items = [];
    if (!bms_is_logged_in()) {
        $items[] = [
            'label' => 'Sign in',
            'url' => bms_url_path('account.php'),
            'target' => '_self',
            'source' => 'system-account',
        ];

        $registrationEnabled = function_exists('bms_public_registration_enabled') && bms_public_registration_enabled();
        if ($registrationEnabled) {
            $items[] = [
                'label' => 'Create account',
                'url' => bms_url_path('account.php#create-account'),
                'target' => '_self',
                'source' => 'system-account',
            ];
        }

        return $items;
    }

    $user = function_exists('bms_current_user') ? bms_current_user() : [];
    $canViewAdmin = function_exists('bms_current_user_can') && bms_current_user_can('view_admin');
    $items[] = [
        'label' => $canViewAdmin ? 'Dashboard' : 'Account',
        'url' => $canViewAdmin && function_exists('bms_admin_url') ? bms_admin_url() : bms_url_path('account.php'),
        'target' => '_self',
        'source' => 'system-account',
    ];

    if (is_array($user) && (int)($user['id'] ?? 0) > 0 && function_exists('bms_public_profile_url_for_user')) {
        $items[] = [
            'label' => 'Profile',
            'url' => bms_public_profile_url_for_user($user),
            'target' => '_self',
            'source' => 'system-account',
        ];
    }

    if (function_exists('bms_csrf_token')) {
        $items[] = [
            'label' => 'Sign out',
            'url' => bms_url_path('account.php?action=logout&csrf_token=' . rawurlencode(bms_csrf_token())),
            'target' => '_self',
            'source' => 'system-account',
        ];
    }

    return $items;
}

function bms_public_navigation_items(): array
{
    $items = bms_navigation_items();
    if (!$items) {
        $items = bms_default_navigation_items();
    }

    if (bms_public_navigation_account_links_enabled()) {
        foreach (bms_public_navigation_account_items() as $accountItem) {
            $normalized = bms_normalize_navigation_item($accountItem, count($items));
            if ($normalized === null || bms_navigation_has_url($items, (string)$normalized['url'])) {
                continue;
            }
            $items[] = $normalized;
        }
    }

    return bms_sort_navigation_items($items);
}

function bms_sanitize_navigation_url(string $url): string
{
    $url = trim(str_replace('\\', '/', $url));
    if ($url === '') {
        return '/';
    }

    if (str_contains($url, "\0") || preg_match('/[\r\n]/', $url)) {
        return '/';
    }

    if (str_starts_with($url, '#')) {
        return preg_match('/^#[A-Za-z0-9_\-:.]+$/', $url) === 1 ? $url : '/';
    }

    if (str_starts_with($url, '//')) {
        return '/';
    }

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (is_string($scheme) && $scheme !== '') {
        $scheme = strtolower($scheme);
        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return '/';
        }
        return $url;
    }

    $decoded = rawurldecode($url);
    $path = parse_url($decoded, PHP_URL_PATH);
    $path = is_string($path) ? $path : $decoded;
    if (str_starts_with($path, './') || str_starts_with($path, '../') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
        return '/';
    }

    return '/' . ltrim($url, '/');
}

function bms_resolve_navigation_url(string $url): string
{
    $url = bms_sanitize_navigation_url($url);
    if ($url === '/') {
        return bms_url_path();
    }
    if (str_starts_with($url, '#') || preg_match('#^(https?://|mailto:)#i', $url) === 1) {
        return $url;
    }
    return bms_url_path(ltrim($url, '/'));
}

function bms_normalize_navigation_path(string $path): string
{
    $path = parse_url($path, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    $path = '/' . trim($path, '/');
    if ($path !== '/') {
        $path .= '/';
    }
    return $path;
}

function bms_current_navigation_path(?string $currentPath = null): string
{
    if ($currentPath !== null && trim($currentPath) !== '') {
        $path = $currentPath;
    } else {
        $path = (string)($_SERVER['REQUEST_URI'] ?? '/');
    }

    if (preg_match('#^(https?://|mailto:)#i', $path) === 1) {
        return bms_normalize_navigation_path($path);
    }

    $base = parse_url(bms_url_path(), PHP_URL_PATH);
    $base = is_string($base) ? trim($base, '/') : '';
    $normalized = bms_normalize_navigation_path($path);
    if ($base !== '' && str_starts_with(trim($normalized, '/'), $base . '/')) {
        $normalized = '/' . substr(trim($normalized, '/'), strlen($base) + 1);
        if ($normalized !== '/') {
            $normalized = '/' . trim($normalized, '/') . '/';
        }
    }
    return $normalized;
}

function bms_navigation_item_is_active(array $item, ?string $currentPath = null): bool
{
    $url = (string)($item['url'] ?? '');
    if ($url === '' || str_starts_with($url, '#') || preg_match('#^(https?://|mailto:)#i', $url) === 1) {
        return false;
    }

    $itemPath = bms_current_navigation_path(bms_resolve_navigation_url($url));
    $current = bms_current_navigation_path($currentPath);

    if ($itemPath === '/') {
        return $current === '/';
    }

    return $current === $itemPath;
}

function bms_save_navigation_items(array $items): void
{
    $normalizedItems = [];
    $position = 0;
    foreach (array_slice($items, 0, 100) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized = bms_normalize_navigation_item($item, $position);
        if ($normalized === null) {
            continue;
        }
        $normalizedItems[] = $normalized;
        $position++;
    }

    bms_set_setting('primary_navigation', json_encode(bms_sort_navigation_items($normalizedItems), JSON_UNESCAPED_SLASHES));
}

function bms_render_public_navigation(string $class = 'public-nav', ?string $currentPath = null): string
{
    if (!bms_public_navigation_enabled()) {
        return '';
    }

    $items = bms_public_navigation_items();
    if (!$items) {
        return '';
    }

    $html = '<nav class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-label="Primary navigation">';
    foreach ($items as $item) {
        $label = htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars(bms_resolve_navigation_url((string)$item['url']), ENT_QUOTES, 'UTF-8');
        $active = bms_navigation_item_is_active($item, $currentPath);
        $target = (string)($item['target'] ?? '_self') === '_blank' ? ' target="_blank" rel="noopener"' : '';
        $classAttr = $active ? ' class="is-active" aria-current="page"' : '';
        $html .= '<a href="' . $url . '"' . $target . $classAttr . '>' . $label . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

function bms_render_public_navigation_list(string $class = 'site-nav', string $id = 'site-primary-nav', ?string $currentPath = null): string
{
    if (!bms_public_navigation_enabled()) {
        return '';
    }

    $items = bms_public_navigation_items();
    if (!$items) {
        return '';
    }

    $html = '<nav id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-label="Primary menu">';
    $html .= '<ul class="site-nav-list">';
    foreach ($items as $item) {
        $active = bms_navigation_item_is_active($item, $currentPath);
        $liClass = $active ? ' class="is-active"' : '';
        $linkClass = $active ? ' class="is-active" aria-current="page"' : '';
        $label = htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars(bms_resolve_navigation_url((string)$item['url']), ENT_QUOTES, 'UTF-8');
        $target = (string)($item['target'] ?? '_self') === '_blank' ? ' target="_blank" rel="noopener"' : '';
        $html .= '<li' . $liClass . '><a href="' . $url . '"' . $target . $linkClass . '>' . $label . '</a></li>';
    }
    $html .= '</ul></nav>';
    return $html;
}

function bms_navigation_prepare_page_item(array $page, string $label = ''): ?array
{
    $slug = bms_slugify((string)($page['slug'] ?? ''));
    if ($slug === '') {
        return null;
    }

    $pageLabel = trim($label) !== '' ? trim($label) : trim((string)($page['title'] ?? 'Page'));
    if ($pageLabel === '') {
        $pageLabel = 'Page';
    }

    return [
        'label' => substr($pageLabel, 0, 80),
        'url' => '/' . trim(bms_page_relative_directory($slug), '/') . '/',
        'target' => '_self',
        'source' => 'page',
        'object_type' => 'page',
        'object_slug' => $slug,
    ];
}

function bms_stream_published_count(?int $knownCount = null): int
{
    if ($knownCount !== null) {
        return max(0, $knownCount);
    }

    if (function_exists('bms_list_content_records') && function_exists('bms_filter_stream_posts')) {
        try {
            return count(bms_filter_stream_posts(bms_list_content_records('published')));
        } catch (Throwable $e) {
            return 0;
        }
    }

    return 0;
}

function bms_stream_count_label(int $count): string
{
    return number_format($count) . ' ' . ($count === 1 ? 'post' : 'posts');
}

function bms_render_public_header(string $context = 'page', ?int $streamPostCount = null, ?string $currentPath = null): string
{
    $siteNameRaw = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $taglineRaw = (string)bms_setting_or_config('site_tagline', '');
    $taglineHtml = function_exists('bms_sanitize_site_identity_html') ? bms_sanitize_site_identity_html($taglineRaw) : htmlspecialchars($taglineRaw, ENT_QUOTES, 'UTF-8');
    $homeUrlRaw = bms_url_path();
    $count = bms_stream_published_count($streamPostCount);
    $countLabelRaw = bms_stream_count_label($count);
    $navHtml = bms_render_public_navigation_list('site-nav stream-site-nav', 'site-primary-nav', $currentPath);
    $titleTag = $context === 'home' ? 'h1' : 'p';

    return bms_render_public_theme_template('header', [
        'context' => $context,
        'site_name' => $siteNameRaw,
        'tagline' => $taglineRaw,
        'tagline_html' => $taglineHtml,
        'home_url' => $homeUrlRaw,
        'count' => $count,
        'count_label' => $countLabelRaw,
        'navigation_html' => $navHtml,
        'title_tag' => $titleTag,
        'theme_settings' => bms_public_theme_settings(),
    ]);
}

function bms_render_public_footer(?string $currentPath = null): string
{
    $footerText = trim(bms_site_footer_text());
    $footerHtml = function_exists('bms_sanitize_site_identity_html') ? bms_sanitize_site_identity_html($footerText) : htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8');

    return bms_render_public_theme_template('footer', [
        'footer_text' => bms_site_identity_plain_text($footerText),
        'footer_html' => $footerHtml,
        'show_powered_by' => bms_show_powered_by(),
    ]);
}
