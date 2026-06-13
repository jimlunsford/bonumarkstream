<?php

function bms_set_public_preview_mode(bool $enabled): void
{
    $GLOBALS['bms_public_preview_mode'] = $enabled;
}

function bms_public_preview_mode(): bool
{
    return !empty($GLOBALS['bms_public_preview_mode']);
}

function bms_with_public_preview_mode(callable $callback): mixed
{
    $previous = !empty($GLOBALS['bms_public_preview_mode']);
    $GLOBALS['bms_public_preview_mode'] = true;
    try {
        return $callback();
    } finally {
        $GLOBALS['bms_public_preview_mode'] = $previous;
    }
}

function bms_config_path(): string
{
    return dirname(__DIR__) . '/config.php';
}

function bms_installed_lock_path(): string
{
    return dirname(__DIR__) . '/installed.lock';
}

function bms_default_config(): array
{
    return [
        'site_name' => 'Bonumark Stream',
        'site_tagline' => 'A self-hosted microblog CMS for publishing short-form posts on a site you control.',
        'active_public_theme' => 'default',
        'show_powered_by' => '1',
        'site_favicon_media_id' => '0',
        'site_favicon_path' => '',
        'public_navigation_account_links_enabled' => '1',
        'remote_posting_enabled' => '0',
        'remote_posting_direct_publish_enabled' => '0',
        'remote_posting_default_status' => 'draft',
        'remote_posting_publish_confirmation_required' => '1',
        'remote_posting_rate_limit_per_minute' => '60',
        'remote_media_upload_enabled' => '0',
        'version' => '0.5.0',
        'author_name' => 'Admin',
        'base_path' => '',
        'base_url' => '',
        'public_path' => '',
        'stream_composer_enabled' => '1',
        'stream_posts_per_page' => '20',
        'stream_show_dates' => '1',
        'stream_show_edit_links' => '0',
        'stream_index_policy' => 'smart',
        'sitemap_enabled' => '1',
        'sitemap_include_stream_posts' => '1',
        'sitemap_include_pages' => '1',
        'sitemap_include_profiles' => '0',
        'content_storage_mode' => 'database',
                'comments_enabled' => '1',
        'comment_registration_enabled' => '0',
        'comments_default_status' => 'approved',
        'registration_mode' => 'disabled',
        'registration_default_role' => 'commenter',
        'registration_require_email_verification' => '1',
        'registration_require_admin_approval' => '0',
        'registration_honeypot_enabled' => '1',
        'media_upload_limit_mb' => '32',
        'mail_transport' => 'disabled',
        'mail_from_name' => 'Bonumark Stream',
        'mail_from_email' => '',
        'mail_reply_to' => '',
        'mail_smtp_host' => '',
        'mail_smtp_port' => '587',
        'mail_smtp_encryption' => 'tls',
        'mail_smtp_username' => '',
        'mail_smtp_password' => '',
        'mail_sendmail_path' => '/usr/sbin/sendmail',
        'timezone' => 'UTC',
        'security_salt' => '',
        'database' => [
            'host' => '',
            'name' => '',
            'user' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'prefix' => 'bms_',
        ],
    ];
}

function bms_config_exists(): bool
{
    return is_file(bms_config_path());
}

function bms_config(bool $reload = false): array
{
    static $config = null;
    if ($reload) {
        $config = null;
    }
    if ($config === null) {
        $config = bms_default_config();
        if (bms_config_exists()) {
            $loaded = require bms_config_path();
            if (is_array($loaded)) {
                $config = array_replace_recursive($config, $loaded);
            }
        }
        date_default_timezone_set($config['timezone'] ?? 'UTC');
    }
    return $config;
}

function bms_is_installed(): bool
{
    $config = bms_config();
    $db = $config['database'] ?? [];
    return is_file(bms_installed_lock_path()) && is_array($db) && !empty($db['host']) && !empty($db['name']) && !empty($db['user']);
}

function bms_require_installed(): void
{
    if (!bms_is_installed()) {
        bms_redirect(bms_url_path('install.php'));
    }
}

function bms_setting_or_config(string $key, mixed $default = ''): mixed
{
    if (function_exists('bms_setting') && bms_is_installed()) {
        try {
            return bms_setting($key, bms_config()[$key] ?? $default);
        } catch (Throwable $e) {
            return bms_config()[$key] ?? $default;
        }
    }
    return bms_config()[$key] ?? $default;
}


function bms_plain_text(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}


function bms_site_identity_text_segment(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return $text;
}

function bms_site_identity_allowed_link_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '/')) {
        return preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*(?:\?[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]*)?(?:\#[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]*)?$#', $url) === 1 ? $url : '';
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function bms_site_identity_anchor_attributes(string $rawAttributes): array
{
    $attributes = [];
    if (preg_match_all('/([a-zA-Z][a-zA-Z0-9:-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $rawAttributes, $matches, PREG_SET_ORDER) !== false) {
        foreach ($matches as $match) {
            $name = strtolower((string)$match[1]);
            $value = (string)($match[3] ?? $match[4] ?? $match[5] ?? '');
            $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $attributes;
}

function bms_sanitize_site_identity_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $output = '';
    $offset = 0;
    $pattern = '/<a\s+([^>]*)>(.*?)<\/a>/is';
    if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
        return htmlspecialchars(bms_plain_text($html), ENT_QUOTES, 'UTF-8');
    }

    foreach ($matches as $match) {
        $start = (int)$match[0][1];
        $length = strlen((string)$match[0][0]);
        $before = substr($html, $offset, $start - $offset);
        $output .= htmlspecialchars(bms_site_identity_text_segment($before), ENT_QUOTES, 'UTF-8');

        $attributes = bms_site_identity_anchor_attributes((string)$match[1][0]);
        $href = bms_site_identity_allowed_link_url((string)($attributes['href'] ?? ''));
        $label = bms_plain_text((string)$match[2][0]);
        if ($href !== '' && $label !== '') {
            $title = bms_plain_text((string)($attributes['title'] ?? ''));
            $target = (string)($attributes['target'] ?? '');
            $target = in_array($target, ['_blank', '_self'], true) ? $target : '';
            $rel = bms_plain_text((string)($attributes['rel'] ?? ''));
            $relParts = preg_split('/\s+/', strtolower($rel), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($target === '_blank') {
                $relParts = array_merge($relParts, ['noopener', 'noreferrer']);
            }
            $relParts = array_values(array_unique(array_filter($relParts, static fn($part) => preg_match('/^[a-z0-9_-]+$/', $part) === 1)));

            $output .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
            if ($title !== '') {
                $output .= ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
            }
            if ($target !== '') {
                $output .= ' target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"';
            }
            if ($relParts) {
                $output .= ' rel="' . htmlspecialchars(implode(' ', $relParts), ENT_QUOTES, 'UTF-8') . '"';
            }
            $output .= '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        } else {
            $output .= htmlspecialchars($label !== '' ? $label : bms_plain_text((string)$match[0][0]), ENT_QUOTES, 'UTF-8');
        }
        $offset = $start + $length;
    }

    $remaining = substr($html, $offset);
    $output .= htmlspecialchars(bms_site_identity_text_segment($remaining), ENT_QUOTES, 'UTF-8');
    $output = preg_replace('/\s+/', ' ', $output) ?? $output;
    return trim($output);
}

function bms_site_identity_plain_text(string $html): string
{
    return bms_plain_text($html);
}

function bms_root_path(string $path = ''): string
{
    return dirname(__DIR__) . ($path ? '/' . ltrim($path, '/') : '');
}

function bms_public_path(string $path = ''): string
{
    $configured = trim((string)bms_setting_or_config('public_path', ''));
    $publicRoot = $configured !== '' ? rtrim($configured, '/\\') : dirname(dirname(__DIR__));
    return $publicRoot . ($path ? '/' . ltrim($path, '/') : '');
}

function bms_content_path(string $path = ''): string
{
    return bms_root_path('content' . ($path ? '/' . ltrim($path, '/') : ''));
}

function bms_base_path(): string
{
    $basePath = trim((string)bms_setting_or_config('base_path', ''));
    if ($basePath === '' || $basePath === '/') {
        return '';
    }
    return '/' . trim($basePath, '/');
}

function bms_url_path(string $path = ''): string
{
    $base = bms_base_path();
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || $path === '/') {
        return $base !== '' ? $base . '/' : '/';
    }

    $fragment = '';
    $query = '';
    $pathOnly = $path;

    $fragmentPosition = strpos($pathOnly, '#');
    if ($fragmentPosition !== false) {
        $fragment = substr($pathOnly, $fragmentPosition);
        $pathOnly = substr($pathOnly, 0, $fragmentPosition);
    }

    $queryPosition = strpos($pathOnly, '?');
    if ($queryPosition !== false) {
        $query = substr($pathOnly, $queryPosition);
        $pathOnly = substr($pathOnly, 0, $queryPosition);
    }

    $hasTrailingSlash = str_ends_with($pathOnly, '/');
    $pathOnly = trim($pathOnly, '/');

    if ($pathOnly === '') {
        $url = $base !== '' ? $base . '/' : '/';
        return $url . $query . $fragment;
    }

    $url = ($base !== '' ? $base : '') . '/' . $pathOnly;
    if ($hasTrailingSlash) {
        $url .= '/';
    }

    return $url . $query . $fragment;
}

function bms_admin_url(string $path = ''): string
{
    return bms_url_path('admin' . ($path ? '/' . ltrim($path, '/') : ''));
}

function bms_stream_safe_return_url(string $returnTo = ''): string
{
    $fallback = bms_url_path();
    $returnTo = trim(str_replace('\\', '/', $returnTo));
    if ($returnTo === '') {
        return $fallback;
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $returnTo) === 1 || str_starts_with($returnTo, '//')) {
        return $fallback;
    }

    $base = bms_base_path();
    $path = parse_url($returnTo, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return $fallback;
    }

    if ($base !== '' && !str_starts_with($path, $base . '/') && $path !== $base) {
        return $fallback;
    }

    $query = parse_url($returnTo, PHP_URL_QUERY);
    return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
}

function bms_asset_url(string $path): string
{
    $url = bms_url_path($path);
    $version = rawurlencode(bms_version());
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
}


function bms_stream_home_url(): string
{
    return bms_url_path();
}

function bms_stream_relative_directory(string $slug, string $category = ''): string
{
    $slug = bms_slugify($slug);
    return $slug !== '' ? 'stream/' . $slug : 'stream';
}

function bms_stream_url(string $slug, string $category = ''): string
{
    $slug = bms_slugify($slug);
    if ($slug === '') {
        return bms_stream_home_url();
    }
    return bms_url_path(bms_stream_relative_directory($slug, $category) . '/');
}

function bms_stream_relative_directory_for_post(array $page): string
{
    return 'stream/' . bms_slugify((string)($page['slug'] ?? ''));
}

function bms_stream_url_for_post(array $page): string
{
    return bms_url_path(bms_stream_relative_directory_for_post($page) . '/');
}


function bms_page_relative_directory(string $slug): string
{
    return 'pages/' . bms_slugify($slug);
}

function bms_page_relative_directory_for_page(array $page): string
{
    return bms_page_relative_directory((string)($page['slug'] ?? ''));
}

function bms_page_url(string $slug): string
{
    return bms_url_path(bms_page_relative_directory($slug) . '/');
}

function bms_page_url_for_page(array $page): string
{
    return bms_page_url((string)($page['slug'] ?? ''));
}




function bms_static_site_export_root(string $name = ''): string
{
    $root = bms_root_path('tmp/static-site-exports');
    if ($name !== '') {
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?? 'export';
        $root .= '/' . trim($name, '-');
    }
    return $root;
}

function bms_static_site_export_path(string $path = '', ?string $targetRoot = null): string
{
    $root = $targetRoot !== null && trim($targetRoot) !== ''
        ? rtrim($targetRoot, '/\\')
        : bms_static_site_export_root('current');
    return $root . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
}

function bms_stream_export_index_path_for_post(array $page, ?string $targetRoot = null): string
{
    return bms_static_site_export_path(bms_stream_relative_directory_for_post($page) . '/index.html', $targetRoot);
}

function bms_page_export_index_path_for_page(array $page, ?string $targetRoot = null): string
{
    return bms_static_site_export_path(bms_page_relative_directory_for_page($page) . '/index.html', $targetRoot);
}

function bms_site_url(string $path = ''): string
{
    $base = rtrim((string)bms_setting_or_config('base_url', ''), '/');
    $urlPath = bms_url_path($path);
    return $base !== '' ? $base . $urlPath : $urlPath;
}


function bms_normalize_username(string $username): string
{
    $username = strtolower(trim($username));
    $username = preg_replace('/[^a-z0-9._-]+/', '-', $username) ?? '';
    $username = trim($username, '.-_');
    return $username !== '' ? substr($username, 0, 64) : 'admin';
}

function bms_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim($text ?? '', '-');
    return $text !== '' ? $text : 'untitled-' . date('Ymd-His');
}

function bms_term_slug(string $text): string
{
    return bms_slugify($text);
}

function bms_stream_clean_text_for_seo(string $text): string
{
    $text = preg_replace('/```.*?```/s', ' ', $text) ?? $text;
    $text = preg_replace('/~~~.*?~~~/s', ' ', $text) ?? $text;
    $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text) ?? $text;
    $text = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', ' ', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\([^\)]*\)/', '$1', $text) ?? $text;
    $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
    $text = preg_replace('/^[\s>*_`#~\-+=|]+/m', ' ', $text) ?? $text;
    $text = preg_replace('/[`*_>#~|]+/', ' ', $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function bms_stream_first_heading_text(string $body): string
{
    if (preg_match('/^\s{0,3}#\s+(.+)$/m', $body, $match) !== 1) {
        return '';
    }

    return bms_stream_clean_text_for_seo((string)$match[1]);
}

function bms_stream_slug_candidate_from_text(string $text, int $maxWords = 10, int $maxLength = 72): string
{
    $text = bms_stream_clean_text_for_seo($text);
    if ($text === '') {
        return '';
    }

    $words = preg_split('/\s+/', $text) ?: [];
    $selected = [];
    foreach ($words as $word) {
        $word = trim((string)$word);
        if ($word === '') {
            continue;
        }

        $selected[] = $word;
        if (count($selected) >= $maxWords) {
            break;
        }
    }

    $candidate = bms_slugify(implode(' ', $selected));
    if ($candidate === '' || str_starts_with($candidate, 'untitled-')) {
        return '';
    }

    if (strlen($candidate) <= $maxLength) {
        return $candidate;
    }

    $candidate = substr($candidate, 0, $maxLength);
    $candidate = preg_replace('/-[^-]*$/', '', $candidate) ?: $candidate;
    return trim($candidate, '-');
}

function bms_stream_limit_text(string $text, int $limit, string $suffix = '…'): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '' || $limit < 1) {
        return '';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    if ($length <= $limit) {
        return $text;
    }

    $cut = function_exists('mb_substr') ? mb_substr($text, 0, max(1, $limit - 1)) : substr($text, 0, max(1, $limit - 1));
    $cut = preg_replace('/\s+\S*$/u', '', $cut) ?: $cut;
    return rtrim($cut, " \t\n\r\0\x0B.,;:!?") . $suffix;
}


function bms_seo_clean_title(string $title): string
{
    $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = trim(strip_tags($title));
    $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
    return trim($title);
}

function bms_seo_site_title(): string
{
    $siteTitle = bms_seo_clean_title((string)bms_setting_or_config('site_name', 'Bonumark Stream'));
    return $siteTitle !== '' ? $siteTitle : 'Bonumark Stream';
}

function bms_seo_site_tagline(): string
{
    if (function_exists('bms_site_identity_plain_text')) {
        return bms_seo_clean_title(bms_site_identity_plain_text((string)bms_setting_or_config('site_tagline', '')));
    }
    return bms_seo_clean_title((string)bms_setting_or_config('site_tagline', ''));
}

function bms_seo_title_lower(string $title): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
}

function bms_seo_strip_site_title(string $title, ?string $siteTitle = null): string
{
    $title = bms_seo_clean_title($title);
    $siteTitle = bms_seo_clean_title($siteTitle ?? bms_seo_site_title());
    if ($title === '' || $siteTitle === '') {
        return $title;
    }

    $separators = [' | ', ' - ', ' – ', ' — ', ' · ', ' • ', ': '];
    $changed = true;
    while ($changed && $title !== '') {
        $changed = false;
        foreach ($separators as $separator) {
            $suffix = $separator . $siteTitle;
            $prefix = $siteTitle . $separator;
            $titleLower = bms_seo_title_lower($title);
            $suffixLower = bms_seo_title_lower($suffix);
            $prefixLower = bms_seo_title_lower($prefix);

            if (str_ends_with($titleLower, $suffixLower)) {
                $title = trim((string)(function_exists('mb_substr') ? mb_substr($title, 0, -1 * (function_exists('mb_strlen') ? mb_strlen($suffix) : strlen($suffix)), 'UTF-8') : substr($title, 0, -1 * strlen($suffix))));
                $changed = true;
                break;
            }
            if (str_starts_with($titleLower, $prefixLower)) {
                $title = trim((string)(function_exists('mb_substr') ? mb_substr($title, (function_exists('mb_strlen') ? mb_strlen($prefix) : strlen($prefix)), null, 'UTF-8') : substr($title, strlen($prefix))));
                $changed = true;
                break;
            }
        }
    }

    return bms_seo_title_lower($title) === bms_seo_title_lower($siteTitle) ? '' : $title;
}

function bms_seo_join_title_parts(array $parts, int $limit = 70): string
{
    $clean = [];
    foreach ($parts as $part) {
        $part = bms_seo_clean_title((string)$part);
        if ($part !== '') {
            $clean[] = $part;
        }
    }
    if (!$clean) {
        return '';
    }

    $title = implode(' | ', $clean);
    return bms_stream_limit_text($title, $limit, '…');
}

function bms_seo_document_title(string $primaryTitle = '', int $limit = 70): string
{
    $siteTitle = bms_seo_site_title();
    $primaryTitle = bms_seo_strip_site_title($primaryTitle, $siteTitle);

    if ($primaryTitle === '') {
        return bms_stream_limit_text($siteTitle, $limit, '…');
    }

    $separator = ' | ';
    $siteLength = function_exists('mb_strlen') ? mb_strlen($siteTitle, 'UTF-8') : strlen($siteTitle);
    $separatorLength = function_exists('mb_strlen') ? mb_strlen($separator, 'UTF-8') : strlen($separator);
    $availableForPrimary = $limit - $siteLength - $separatorLength;

    if ($siteTitle !== '' && $availableForPrimary >= 18) {
        return bms_stream_limit_text($primaryTitle, $availableForPrimary, '…') . $separator . $siteTitle;
    }

    return bms_stream_limit_text($primaryTitle, $limit, '…');
}

function bms_seo_home_title(int $limit = 70): string
{
    $siteTitle = bms_seo_site_title();
    $tagline = bms_seo_strip_site_title(bms_seo_site_tagline(), $siteTitle);

    if ($tagline === '') {
        return bms_stream_limit_text($siteTitle, $limit, '…');
    }

    $separator = ' | ';
    $siteLength = function_exists('mb_strlen') ? mb_strlen($siteTitle, 'UTF-8') : strlen($siteTitle);
    $separatorLength = function_exists('mb_strlen') ? mb_strlen($separator, 'UTF-8') : strlen($separator);
    $availableForTagline = $limit - $siteLength - $separatorLength;

    if ($availableForTagline >= 18) {
        return $siteTitle . $separator . bms_stream_limit_text($tagline, $availableForTagline, '…');
    }

    return bms_stream_limit_text($siteTitle, $limit, '…');
}

function bms_public_seo_view_data(string $template, array $data): array
{
    $template = strtolower(trim($template));
    $siteTitle = bms_seo_site_title();
    $primary = '';
    $documentTitle = '';
    $socialTitle = '';

    $providedPrimary = bms_seo_strip_site_title((string)($data['seo_title_primary'] ?? ''), $siteTitle);

    if ($template === 'home') {
        $documentTitle = bms_seo_home_title();
        $socialTitle = $siteTitle;
        $primary = $siteTitle;
    } elseif ($providedPrimary !== '') {
        $primary = $providedPrimary;
    } elseif ($template === 'archive') {
        $pageNumber = max(1, (int)($data['page_number'] ?? 1));
        $primary = $pageNumber > 1 ? 'Stream, Page ' . $pageNumber : 'Stream';
    } elseif ($template === 'single') {
        $primary = bms_seo_strip_site_title((string)($data['page_title'] ?? $data['title'] ?? 'Stream post'), $siteTitle);
    } elseif ($template === 'page') {
        $primary = bms_seo_strip_site_title((string)($data['page_title'] ?? $data['title'] ?? 'Page'), $siteTitle);
    } elseif ($template === 'search') {
        $query = trim((string)($data['query'] ?? ''));
        $primary = $query !== '' ? 'Search results for ' . $query : 'Search';
    } elseif ($template === 'profile') {
        $primary = bms_seo_strip_site_title((string)($data['display_name'] ?? $data['title'] ?? 'Profile'), $siteTitle);
        if ($primary === '') {
            $primary = 'Profile';
        }
    } elseif ($template === 'account') {
        $primary = 'Account';
    } else {
        $primary = bms_seo_strip_site_title((string)($data['page_title'] ?? $data['title'] ?? ''), $siteTitle);
    }

    if ($documentTitle === '') {
        $documentTitle = bms_seo_document_title($primary);
    }
    if ($socialTitle === '') {
        $socialTitle = $primary !== '' ? $primary : $documentTitle;
    }

    $data['seo_title_primary'] = $data['seo_title_primary'] ?? $primary;
    $data['seo_document_title'] = $data['seo_document_title'] ?? $documentTitle;
    $data['seo_social_title'] = $data['seo_social_title'] ?? $socialTitle;
    $data['title'] = $data['seo_document_title'];

    return $data;
}

function bms_stream_first_sentence(string $body): string
{
    $text = bms_stream_clean_text_for_seo($body);
    if ($text === '') {
        return '';
    }

    if (preg_match('/^(.{20,110}?[.!?])\s/u', $text, $match) === 1) {
        return trim($match[1]);
    }

    return bms_stream_limit_text($text, 90, '');
}

function bms_stream_title_case(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $small = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'on', 'or', 'over', 'the', 'to', 'with'];
    $words = preg_split('/\s+/', strtolower($text)) ?: [];
    $out = [];
    $last = count($words) - 1;
    foreach ($words as $i => $word) {
        $word = trim($word);
        if ($word === '') {
            continue;
        }
        if ($i !== 0 && $i !== $last && in_array($word, $small, true)) {
            $out[] = $word;
            continue;
        }
        $out[] = function_exists('mb_convert_case') ? mb_convert_case($word, MB_CASE_TITLE, 'UTF-8') : ucfirst($word);
    }
    return implode(' ', $out);
}

function bms_stream_generated_post_title(string $body, string $createdAt = '', string $featuredMedia = '', array $media = [], int $limit = 70): string
{
    $candidate = bms_stream_first_sentence($body);
    if ($candidate !== '') {
        $candidate = trim($candidate, " \t\n\r\0\x0B.,;:!?");
        return bms_stream_limit_text($candidate, $limit, '…');
    }

    $mediaName = trim((string)($media['original_filename'] ?? $media['filename'] ?? ''));
    if ($mediaName === '' && $featuredMedia !== '') {
        $mediaName = basename($featuredMedia);
    }
    if ($mediaName !== '') {
        $mediaName = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $mediaName) ?? $mediaName;
        $mediaName = str_replace(['-', '_'], ' ', $mediaName);
        $mediaName = trim(preg_replace('/\s+/', ' ', $mediaName) ?? $mediaName);
        if ($mediaName !== '') {
            return bms_stream_limit_text($mediaName, $limit, '…');
        }
    }

    $time = strtotime($createdAt) ?: time();
    return 'Media post from ' . date('M j, Y', $time);
}

function bms_stream_generated_seo_title(string $body, string $createdAt = '', string $featuredMedia = '', array $media = []): string
{
    $postTitle = trim(bms_stream_generated_post_title($body, $createdAt, $featuredMedia, $media, 70));
    if ($postTitle === '') {
        $postTitle = 'Stream update';
    }

    return bms_stream_limit_text(bms_seo_strip_site_title($postTitle), 70, '…');
}

function bms_stream_generated_description(string $body, string $createdAt = '', string $featuredMedia = '', int $limit = 160): string
{
    $text = bms_stream_clean_text_for_seo($body);
    if ($text !== '') {
        return bms_stream_limit_text($text, $limit, '…');
    }

    $site = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $time = strtotime($createdAt) ?: time();
    if (trim($featuredMedia) !== '') {
        return 'Media post from ' . $site . ' on ' . date('F j, Y', $time) . '.';
    }
    return 'Short-form stream post from ' . $site . ' on ' . date('F j, Y', $time) . '.';
}

function bms_stream_slug_base(string $body, string $createdAt = '', array $media = [], string $title = ''): string
{
    $titleCandidate = trim($title);
    if ($titleCandidate !== '' && !bms_stream_title_needs_generation($titleCandidate)) {
        $candidate = bms_stream_slug_candidate_from_text($titleCandidate, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $headingCandidate = bms_stream_first_heading_text($body);
    if ($headingCandidate !== '') {
        $candidate = bms_stream_slug_candidate_from_text($headingCandidate, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $sentenceCandidate = bms_stream_first_sentence($body);
    if ($sentenceCandidate !== '') {
        $candidate = bms_stream_slug_candidate_from_text($sentenceCandidate, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $mediaName = trim((string)($media['original_filename'] ?? $media['filename'] ?? ''));
    if ($mediaName !== '') {
        $mediaName = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $mediaName) ?? $mediaName;
        $mediaName = str_replace(['-', '_'], ' ', $mediaName);
        $candidate = bms_stream_slug_candidate_from_text($mediaName, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return 'stream-post';
}

function bms_stream_unique_slug(string $baseSlug, string $currentSlug = ''): string
{
    $baseSlug = bms_slugify($baseSlug);
    if ($baseSlug === '') {
        $baseSlug = 'stream-post-' . date('Ymd');
    }

    $currentSlug = bms_slugify($currentSlug);
    $slug = $baseSlug;
    $counter = 2;
    while (true) {
        $published = bms_content_path('published/' . $slug . '.md');
        $draft = bms_content_path('drafts/' . $slug . '.md');
        $databaseConflict = function_exists('bms_database_slug_exists') && bms_database_slug_exists($slug, $currentSlug, 'stream');
        $conflicts = ($currentSlug === '' || $slug !== $currentSlug) && ($databaseConflict || is_file($published) || is_file($draft));
        if (!$conflicts) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function bms_page_clean_slug_candidate(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    return trim($slug, '-');
}

function bms_page_generated_seo_title(string $title): string
{
    $pageTitle = trim($title);
    if ($pageTitle === '') {
        $pageTitle = 'Untitled Page';
    }

    return bms_stream_limit_text(bms_seo_strip_site_title($pageTitle), 70, '…');
}

function bms_page_unique_slug(string $baseSlug, string $currentSlug = ''): string
{
    $baseSlug = bms_page_clean_slug_candidate($baseSlug);
    if ($baseSlug === '') {
        $baseSlug = 'page';
    }

    $reserved = ['admin', 'assets', 'install', 'stream', 'media', 'feed', 'account', 'profile', 'author', 'comments', 'search', 'page'];
    if (in_array($baseSlug, $reserved, true)) {
        $baseSlug = 'page-' . $baseSlug;
    }

    $currentSlug = bms_slugify($currentSlug);
    $slug = $baseSlug;
    $counter = 2;
    while (true) {
        $published = bms_content_path('pages/published/' . $slug . '.md');
        $draft = bms_content_path('pages/drafts/' . $slug . '.md');
        $streamPublished = bms_content_path('published/' . $slug . '.md');
        $streamDraft = bms_content_path('drafts/' . $slug . '.md');
        $databaseConflict = function_exists('bms_database_slug_exists') && (bms_database_slug_exists($slug, $currentSlug, 'page') || bms_database_slug_exists($slug, $currentSlug, 'stream'));
        $conflicts = ($currentSlug === '' || $slug !== $currentSlug) && ($databaseConflict || is_file($published) || is_file($draft) || is_file($streamPublished) || is_file($streamDraft));
        if (!$conflicts) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function bms_page_status_section(string $status): string
{
    return $status === 'published' ? 'pages/published' : 'pages/drafts';
}

function bms_page_slug_needs_generation(string $slug): bool
{
    $rawSlug = trim($slug);
    if ($rawSlug === '') {
        return true;
    }

    $cleanSlug = bms_page_clean_slug_candidate($rawSlug);
    if ($cleanSlug === '' || in_array($cleanSlug, ['untitled', 'generated-on-save'], true)) {
        return true;
    }

    return preg_match('/^untitled-\d{8}(?:-\d{6})?$/', $cleanSlug) === 1;
}

function bms_page_prepare_metadata_fields(array $fields, string $body, string $currentSlug = ''): array
{
    $title = trim((string)($fields['title'] ?? ''));
    if ($title === '') {
        $title = bms_first_heading($body) ?: 'Untitled Page';
    }

    $slugInput = trim((string)($fields['slug'] ?? ''));
    $slug = bms_page_slug_needs_generation($slugInput) ? bms_page_unique_slug($title, $currentSlug) : bms_page_unique_slug($slugInput, $currentSlug);

    $seoTitle = trim((string)($fields['seo_title'] ?? ''));
    if ($seoTitle === bms_page_generated_seo_title($title) || $seoTitle === $title) {
        $seoTitle = '';
    }

    $description = trim((string)($fields['description'] ?? ''));
    if ($description === '') {
        $description = bms_plain_excerpt($body, 160);
    }

    $fields['title'] = $title;
    $fields['slug'] = $slug;
    $fields['seo_title'] = $seoTitle;
    $fields['description'] = $description;
    $fields['content_type'] = 'page';
    $fields['category'] = 'Page';
    $fields['tags'] = '';

    return $fields;
}

function bms_build_page_markdown_from_request(string $forcedStatus = 'draft', string $currentSlug = ''): string
{
    $body = (string)($_POST['body_markdown'] ?? '');
    $fields = [
        'title' => (string)($_POST['page_title'] ?? ''),
        'slug' => (string)($_POST['page_slug'] ?? ''),
        'status' => $forcedStatus,
        'date' => (string)($_POST['page_date'] ?? date('Y-m-d')),
        'content_type' => 'page',
        'description' => (string)($_POST['page_description'] ?? ''),
        'category' => 'Page',
        'tags' => '',
        'seo_title' => (string)($_POST['page_seo_title'] ?? ''),
        'robots' => (string)($_POST['page_robots'] ?? ''),
    ];
    $fields = bms_page_prepare_metadata_fields($fields, $body, $currentSlug);
    return bms_build_markdown_document($fields, $body);
}

function bms_list_page_records(string $status = 'published'): array
{
    $section = $status === 'published' ? 'pages/published' : 'pages/drafts';
    return array_values(array_filter(bms_list_content_records($section), function (array $page): bool {
        return bms_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'page')) === 'page';
    }));
}




function bms_stream_slug_needs_generation(string $slug): bool
{
    $slug = bms_slugify($slug);
    if ($slug === '') {
        return true;
    }

    if (in_array($slug, ['untitled', 'generated-on-save'], true)) {
        return true;
    }

    return preg_match('/^(stream|stream-post|untitled)-\d{8}(?:-\d{6})?$/', $slug) === 1;
}

function bms_stream_title_needs_generation(string $title): bool
{
    $title = trim($title);
    if ($title === '' || strtolower($title) === 'untitled') {
        return true;
    }

    return preg_match('/^Stream Post:\s+/i', $title) === 1;
}

function bms_stream_media_context_from_path(string $featuredMedia): array
{
    $featuredMedia = trim($featuredMedia);
    if ($featuredMedia === '') {
        return [];
    }

    return ['filename' => basename($featuredMedia)];
}

function bms_stream_prepare_metadata_fields(array $fields, string $body, string $currentSlug = ''): array
{
    $createdAt = trim((string)($fields['stream_created_at'] ?? $fields['created_at'] ?? $fields['date'] ?? date('Y-m-d H:i:s')));
    $featuredMedia = trim((string)($fields['featured_media'] ?? ''));
    $mediaContext = bms_stream_media_context_from_path($featuredMedia);

    $manualTitle = trim((string)($fields['title'] ?? ''));
    $title = $manualTitle;
    if (bms_stream_title_needs_generation($title)) {
        $title = bms_stream_admin_title_from_body($body, $createdAt, $featuredMedia, $mediaContext);
    }

    $slugInput = trim((string)($fields['slug'] ?? ''));
    if (bms_stream_slug_needs_generation($slugInput)) {
        $slug = bms_stream_unique_slug(bms_stream_slug_base($body, $createdAt, $mediaContext, $manualTitle), $currentSlug);
    } else {
        $slug = bms_slugify($slugInput);
    }

    $seoTitle = trim((string)($fields['seo_title'] ?? ''));
    if ($seoTitle === '') {
        $seoTitle = bms_stream_generated_seo_title($body, $createdAt, $featuredMedia, $mediaContext);
    }

    $description = trim((string)($fields['description'] ?? ''));
    if ($description === '') {
        $description = bms_stream_generated_description($body, $createdAt, $featuredMedia);
    }

    $fields['title'] = $title;
    $fields['slug'] = $slug;
    $fields['seo_title'] = $seoTitle;
    $fields['description'] = $description;
    $fields['stream_created_at'] = $createdAt;

    return $fields;
}



function bms_parse_markdown_file(string $file): array
{
    $raw = file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException('Could not read Markdown file.');
    }
    return bms_parse_markdown_string($raw);
}

function bms_parse_markdown_string(string $raw): array
{
    $frontMatter = [];
    $body = $raw;

    if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $matches)) {
        $frontMatterRaw = trim($matches[1]);
        $body = $matches[2];
        $frontMatter = bms_parse_front_matter($frontMatterRaw);
    }

    $title = $frontMatter['title'] ?? bms_first_heading($body) ?? 'Untitled';
    $slug = $frontMatter['slug'] ?? bms_slugify($title);
    $description = $frontMatter['description'] ?? '';
    $date = $frontMatter['date'] ?? date('Y-m-d');
    $category = $frontMatter['category'] ?? 'Stream';
    $status = $frontMatter['status'] ?? 'draft';
    $contentType = bms_normalize_content_type((string)($frontMatter['content_type'] ?? $frontMatter['post_type'] ?? 'stream'));
    $tags = bms_normalize_terms($frontMatter['tags'] ?? []);
    $featuredMedia = trim((string)($frontMatter['featured_media'] ?? ''));
    $streamCreatedAt = trim((string)($frontMatter['stream_created_at'] ?? $frontMatter['created_at'] ?? ''));
    $seoTitle = trim((string)($frontMatter['seo_title'] ?? ''));
    $robots = trim((string)($frontMatter['robots'] ?? ''));
    $linkPreviewUrl = trim((string)($frontMatter['link_preview_url'] ?? ''));
    $linkPreviewTitle = trim((string)($frontMatter['link_preview_title'] ?? ''));
    $linkPreviewDescription = trim((string)($frontMatter['link_preview_description'] ?? ''));
    $linkPreviewImage = trim((string)($frontMatter['link_preview_image'] ?? ''));
    $linkPreviewSiteName = trim((string)($frontMatter['link_preview_site_name'] ?? ''));

    $category = trim((string)(is_array($category) ? reset($category) : $category));
    if ($category === '') {
        $category = 'Stream';
    }

    return [
        'front_matter' => $frontMatter,
        'body' => $body,
        'title' => trim((string)$title),
        'slug' => bms_slugify((string)$slug),
        'description' => trim((string)$description),
        'date' => trim((string)$date),
        'category' => $category,
        'category_slug' => bms_term_slug($category),
        'tags' => $tags,
        'tag_slugs' => array_map('bms_term_slug', $tags),
        'status' => trim((string)$status),
        'content_type' => $contentType,
        'post_type' => $contentType,
        'featured_media' => $featuredMedia,
        'stream_created_at' => $streamCreatedAt,
        'seo_title' => $seoTitle,
        'robots' => $robots,
        'link_preview_url' => $linkPreviewUrl,
        'link_preview_title' => $linkPreviewTitle,
        'link_preview_description' => $linkPreviewDescription,
        'link_preview_image' => $linkPreviewImage,
        'link_preview_site_name' => $linkPreviewSiteName,
        'raw' => $raw,
    ];
}

function bms_parse_front_matter(string $raw): array
{
    $data = [];
    $lines = preg_split('/\R/', $raw) ?: [];
    $currentKey = null;

    foreach ($lines as $line) {
        if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $m)) {
            $currentKey = $m[1];
            $value = trim($m[2]);
            $value = trim($value, '"\'');
            $data[$currentKey] = $value;
            continue;
        }

        if ($currentKey && preg_match('/^\s*-\s*(.+)$/', $line, $m)) {
            if (!is_array($data[$currentKey])) {
                $data[$currentKey] = $data[$currentKey] === '' ? [] : [$data[$currentKey]];
            }
            $data[$currentKey][] = trim($m[1], '"\'');
        }
    }

    return $data;
}

function bms_normalize_terms(mixed $value): array
{
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $terms = preg_split('/\s*,\s*/', $value) ?: [];
    } elseif (is_array($value)) {
        $terms = $value;
    } else {
        $terms = [];
    }

    $clean = [];
    foreach ($terms as $term) {
        if (is_array($term)) {
            continue;
        }
        $term = trim((string)$term);
        $term = trim($term, '"\'');
        if ($term === '') {
            continue;
        }
        $key = strtolower($term);
        if (!isset($clean[$key])) {
            $clean[$key] = $term;
        }
    }

    return array_values($clean);
}


function bms_front_matter_quote(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));
    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    return '"' . $value . '"';
}

function bms_build_markdown_document(array $fields, string $body): string
{
    $title = trim((string)($fields['title'] ?? 'Untitled'));
    if ($title === '') {
        $title = 'Untitled';
    }

    $slug = bms_slugify((string)($fields['slug'] ?? $title));
    $status = trim((string)($fields['status'] ?? 'draft'));
    if (!in_array($status, ['draft', 'published'], true)) {
        $status = 'draft';
    }

    $date = trim((string)($fields['date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $description = trim((string)($fields['description'] ?? ''));
    $contentType = bms_normalize_content_type((string)($fields['content_type'] ?? $fields['post_type'] ?? 'stream'));
    $category = $contentType === 'page' ? 'Page' : 'Stream';

    $tags = bms_normalize_terms($fields['tags'] ?? []);

    $lines = [
        '---',
        'title: ' . bms_front_matter_quote($title),
        'slug: ' . bms_front_matter_quote($slug),
        'status: ' . bms_front_matter_quote($status),
        'content_type: ' . bms_front_matter_quote($contentType),
        'date: ' . $date,
        'description: ' . bms_front_matter_quote($description),
        'category: ' . bms_front_matter_quote($category),
    ];

    if ($tags) {
        $lines[] = 'tags:';
        foreach ($tags as $tag) {
            $lines[] = '  - ' . bms_front_matter_quote($tag);
        }
    } else {
        $lines[] = 'tags: ""';
    }

    foreach (['featured_media', 'stream_created_at', 'seo_title', 'robots', 'link_preview_url', 'link_preview_title', 'link_preview_description', 'link_preview_image', 'link_preview_site_name'] as $streamKey) {
        $streamValue = trim((string)($fields[$streamKey] ?? ''));
        if ($streamValue !== '') {
            $lines[] = $streamKey . ': ' . bms_front_matter_quote($streamValue);
        }
    }

    $lines[] = '---';

    $body = str_replace(["\r\n", "\r"], "\n", trim($body));
    if ($body === '') {
        $hasStreamMedia = $contentType === 'stream' && trim((string)($fields['featured_media'] ?? '')) !== '';
        if (!$hasStreamMedia) {
            $body = '# ' . $title . "\n\nStart writing here.";
        }
    }

    return implode("\n", $lines) . "\n\n" . $body . ($body !== '' ? "\n" : '');
}

function bms_existing_stream_front_matter_for_slug(string $slug): array
{
    $slug = bms_slugify($slug);
    if ($slug === '' || !function_exists('bms_find_database_content_by_slug_status')) {
        return [];
    }
    foreach (['published', 'draft'] as $status) {
        try {
            $page = bms_find_database_content_by_slug_status($slug, $status, 'stream');
            if ($page && is_array($page['front_matter'] ?? null)) {
                return $page['front_matter'];
            }
        } catch (Throwable $e) {
            return [];
        }
    }
    return [];
}

function bms_stream_link_preview_request_fields(string $currentSlug = ''): array
{
    $keys = ['link_preview_url', 'link_preview_title', 'link_preview_description', 'link_preview_image', 'link_preview_site_name'];
    if (array_key_exists('link_preview_enabled', $_POST)) {
        if ((string)($_POST['link_preview_enabled'] ?? '0') !== '1') {
            return [];
        }
        $fields = [];
        foreach ($keys as $key) {
            $fields[$key] = trim((string)($_POST[$key] ?? ''));
        }
        return trim((string)$fields['link_preview_url']) !== '' ? $fields : [];
    }

    $existing = bms_existing_stream_front_matter_for_slug($currentSlug);
    $fields = [];
    foreach ($keys as $key) {
        $fields[$key] = trim((string)($existing[$key] ?? ''));
    }
    return trim((string)$fields['link_preview_url']) !== '' ? $fields : [];
}

function bms_build_markdown_from_request(string $forcedStatus = 'draft', string $currentSlug = ''): string
{
    $body = (string)($_POST['body_markdown'] ?? '');
    $fields = [
        'title' => (string)($_POST['stream_title'] ?? ''),
        'slug' => (string)($_POST['stream_slug'] ?? ''),
        'status' => $forcedStatus,
        'date' => (string)($_POST['stream_date'] ?? date('Y-m-d')),
        'content_type' => 'stream',
        'description' => (string)($_POST['stream_description'] ?? ''),
        'category' => 'Stream',
        'tags' => '',
        'featured_media' => (string)($_POST['featured_media'] ?? ''),
        'stream_created_at' => (string)($_POST['stream_created_at'] ?? ($_POST['stream_date'] ?? date('Y-m-d H:i:s'))),
        'seo_title' => (string)($_POST['stream_seo_title'] ?? ''),
        'robots' => (string)($_POST['stream_robots'] ?? ''),
    ];
    $fields = array_merge($fields, bms_stream_link_preview_request_fields($currentSlug));
    $fields = bms_stream_prepare_metadata_fields($fields, $body, $currentSlug);

    return bms_build_markdown_document($fields, $body);
}

function bms_first_heading(string $body): ?string
{
    if (preg_match('/^#\s+(.+)$/m', $body, $m)) {
        return trim($m[1]);
    }
    return null;
}

function bms_list_import_markdown_files(string $section): array
{
    $dir = bms_content_path($section);
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.md') ?: [];
    $items = [];
    foreach ($files as $file) {
        try {
            $parsed = bms_parse_markdown_file($file);
            $parsed['filename'] = basename($file);
            $parsed['path'] = $file;
            $parsed['section'] = $section;
            $parsed['content_storage'] = 'import-markdown';
            $items[] = $parsed;
        } catch (Throwable $e) {
            continue;
        }
    }

    usort($items, function ($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });

    return $items;
}

function bms_list_content_records(string $section): array
{
    if (function_exists('bms_database_content_enabled') && function_exists('bms_database_content_columns_ready') && bms_database_content_enabled() && bms_database_content_columns_ready()) {
        try {
            return bms_list_database_content_for_section($section);
        } catch (Throwable $e) {
            return [];
        }
    }

    return [];
}


function bms_write_file(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create directory: ' . $dir);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not write file: ' . $path);
    }
}

function bms_delete_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? bms_delete_directory($path) : unlink($path);
    }
    rmdir($dir);
}


function bms_normalize_content_type(string $type): string
{
    $type = strtolower(trim($type));
    if ($type === 'page') {
        return 'page';
    }
    // Legacy content-type values are tolerated and treated as stream posts.
    return 'stream';
}


function bms_homepage_mode(): string
{
    return 'stream';
}

function bms_stream_composer_enabled(): bool
{
    return (string)bms_setting_or_config('stream_composer_enabled', '1') === '1';
}

function bms_stream_show_dates(): bool
{
    return (string)bms_setting_or_config('stream_show_dates', '1') === '1';
}

function bms_stream_show_edit_links(): bool
{
    return (string)bms_setting_or_config('stream_show_edit_links', '0') === '1';
}


function bms_stream_index_policy(): string
{
    $policy = (string)bms_setting_or_config('stream_index_policy', 'smart');
    return in_array($policy, ['all', 'smart', 'noindex'], true) ? $policy : 'smart';
}



function bms_stream_posts_per_page(): int
{
    $count = (int)bms_setting_or_config('stream_posts_per_page', '20');
    if ($count < 1) {
        return 1;
    }
    if ($count > 100) {
        return 100;
    }
    return $count;
}

function bms_is_stream_post(array $page): bool
{
    return bms_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'stream')) === 'stream';
}

function bms_filter_stream_posts(array $pages): array
{
    return array_values(array_filter($pages, 'bms_is_stream_post'));
}

function bms_sort_stream_posts(array $pages): array
{
    usort($pages, function (array $a, array $b): int {
        $aTime = strtotime((string)($a['stream_created_at'] ?? $a['front_matter']['stream_created_at'] ?? $a['date'] ?? '')) ?: 0;
        $bTime = strtotime((string)($b['stream_created_at'] ?? $b['front_matter']['stream_created_at'] ?? $b['date'] ?? '')) ?: 0;
        if ($aTime !== $bTime) {
            return $bTime <=> $aTime;
        }
        return strcmp((string)($b['filename'] ?? ''), (string)($a['filename'] ?? ''));
    });
    return $pages;
}

function bms_apply_stream_reading_settings(array $pages): array
{
    return array_slice(bms_sort_stream_posts($pages), 0, bms_stream_posts_per_page());
}

function bms_stream_preview_text(array $page, int $limit = 90): string
{
    $body = bms_stream_clean_text_for_seo((string)($page['body'] ?? ''));
    if ($body === '') {
        return trim((string)($page['description'] ?? '')) ?: 'Media post';
    }
    return bms_stream_limit_text($body, $limit, '…');
}

function bms_stream_admin_title_from_body(string $body, string $createdAt = '', string $featuredMedia = '', array $media = []): string
{
    return bms_stream_generated_post_title($body, $createdAt, $featuredMedia, $media, 70);
}


function bms_autosave_enabled(): bool
{
    return (string)bms_setting_or_config('autosave_enabled', '1') === '1';
}


function bms_default_editor_mode(): string
{
    $mode = (string)bms_setting_or_config('default_editor_mode', 'visual');
    return in_array($mode, ['visual', 'markdown'], true) ? $mode : 'visual';
}


function bms_default_content_status(): string
{
    $status = (string)bms_setting_or_config('default_content_status', 'draft');
    return in_array($status, ['draft', 'published'], true) ? $status : 'draft';
}


function bms_query_string(array $params): string
{
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $clean[$key] = $value;
    }
    $query = http_build_query($clean);
    return $query !== '' ? '?' . $query : '';
}

function bms_version(): string
{
    $versionFile = bms_root_path('VERSION');
    if (is_file($versionFile)) {
        $version = trim((string)file_get_contents($versionFile));
        if ($version !== '') {
            return $version;
        }
    }

    $configured = trim((string)(bms_config()['version'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    return 'unknown';
}


function bms_abort_request(string $message, int $status = 400): void
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $message;
    exit;
}

function bms_flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function bms_get_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}


function bms_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return false;
}

function bms_start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $secure = bms_is_https();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function bms_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data: https: http:; style-src 'self'; script-src 'self'");
}

function bms_password_policy_error(string $password, string $username = '', string $email = ''): ?string
{
    $passwordLength = strlen($password);
    if ($passwordLength < 12) {
        return 'Password must be at least 12 characters.';
    }

    if ($passwordLength > 128) {
        return 'Password must be 128 characters or fewer.';
    }

    $lowerPassword = strtolower($password);
    $common = [
        'password', 'password1', 'password12', 'password123', 'admin123456',
        'changeme', 'change-this-password', 'qwerty123456', 'letmein123456',
        'bonumark123', '123456789012', '111111111111', 'aaaaaaaaaaaa'
    ];
    foreach ($common as $bad) {
        if ($lowerPassword === $bad || str_contains($lowerPassword, $bad)) {
            return 'Password is too common. Use a stronger unique password.';
        }
    }

    $normalizedUsername = strtolower(trim($username));
    if ($normalizedUsername !== '' && $lowerPassword === $normalizedUsername) {
        return 'Password cannot match the username.';
    }

    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail !== '' && $lowerPassword === $normalizedEmail) {
        return 'Password cannot match the email address.';
    }

    if ($passwordLength < 20) {
        $classes = 0;
        $classes += preg_match('/[a-z]/', $password) ? 1 : 0;
        $classes += preg_match('/[A-Z]/', $password) ? 1 : 0;
        $classes += preg_match('/[0-9]/', $password) ? 1 : 0;
        $classes += preg_match('/[^A-Za-z0-9]/', $password) ? 1 : 0;
        if ($classes < 3) {
            return 'Password must use at least three of these: lowercase letters, uppercase letters, numbers, and symbols. A 20+ character passphrase is also accepted.';
        }
    }

    return null;
}

function bms_validate_password_policy(string $password, string $username = '', string $email = ''): void
{
    $error = bms_password_policy_error($password, $username, $email);
    if ($error !== null) {
        throw new RuntimeException($error);
    }
}

function bms_request_origin(): string
{
    $scheme = bms_is_https() ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return $host !== '' ? $scheme . '://' . $host : '';
}

function bms_install_base_url_from_request(): string
{
    $origin = bms_request_origin();
    if ($origin === '') {
        return '';
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($script), '/\\');
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    return $origin . $dir;
}

function bms_probe_private_folder_exposure(?string $baseUrl = null): array
{
    $baseUrl = $baseUrl !== null && trim($baseUrl) !== '' ? rtrim(trim($baseUrl), '/') : bms_install_base_url_from_request();
    $secret = 'bonumark-private-probe-' . bin2hex(random_bytes(16));
    $probeFile = bms_root_path('security-probe-' . bin2hex(random_bytes(6)) . '.txt');

    try {
        bms_write_file($probeFile, $secret);
        if ($baseUrl === '') {
            return ['status' => 'unknown', 'message' => 'Could not determine the site URL to test private folder exposure.'];
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return ['status' => 'unknown', 'message' => 'PHP allow_url_fopen is disabled, so Bonumark Stream could not test private folder exposure automatically.'];
        }

        $probeUrl = $baseUrl . '/_bonumark_stream/' . basename($probeFile);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 4,
                'ignore_errors' => true,
                'header' => "User-Agent: BonumarkStreamSecurityProbe/1.0\r\n",
            ],
        ]);
        $response = @file_get_contents($probeUrl, false, $context);
        if (is_string($response) && str_contains($response, $secret)) {
            return ['status' => 'exposed', 'message' => 'The _bonumark_stream private folder appears publicly reachable. Installation should not continue until server rules block it.'];
        }

        return ['status' => 'protected', 'message' => 'The _bonumark_stream private folder did not expose the probe file.'];
    } catch (Throwable $e) {
        return ['status' => 'unknown', 'message' => 'Could not complete the private folder exposure test: ' . $e->getMessage()];
    } finally {
        if (is_file($probeFile)) {
            @unlink($probeFile);
        }
    }
}

function bms_security_status(): array
{
    $items = [];
    $items[] = [
        'label' => 'PHP version',
        'status' => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'pass' : 'fail',
        'message' => PHP_VERSION . (version_compare(PHP_VERSION, '8.2.0', '>=') ? ' is supported.' : (version_compare(PHP_VERSION, '8.1.0', '>=') ? ' is supported. PHP 8.2 or newer is recommended.' : ' is below the PHP 8.1 minimum target.')),
    ];
    $items[] = [
        'label' => 'HTTPS',
        'status' => bms_is_https() ? 'pass' : 'warn',
        'message' => bms_is_https() ? 'Admin requests appear to be using HTTPS.' : 'HTTPS was not detected. Use HTTPS for real sites.',
    ];
    $items[] = [
        'label' => 'PDO MySQL',
        'status' => function_exists('bms_db_supports_mysql') && bms_db_supports_mysql() ? 'pass' : 'fail',
        'message' => function_exists('bms_db_supports_mysql') && bms_db_supports_mysql() ? 'PDO MySQL is available.' : 'PDO MySQL is not available.',
    ];

    $dbStatus = 'warn';
    $dbMessage = 'Database configuration has not been verified.';
    if (function_exists('bms_has_database_config') && bms_has_database_config()) {
        try {
            if (function_exists('bms_db')) {
                bms_db()->query('SELECT 1');
                $dbStatus = 'pass';
                $dbMessage = 'Database connection is working.';
            }
        } catch (Throwable $e) {
            $dbStatus = 'fail';
            $dbMessage = 'Database connection failed: ' . $e->getMessage();
        }
    }
    $items[] = ['label' => 'Database connection', 'status' => $dbStatus, 'message' => $dbMessage];

    $items[] = [
        'label' => 'Config file',
        'status' => is_file(bms_config_path()) ? 'pass' : 'warn',
        'message' => is_file(bms_config_path()) ? '_bonumark_stream/config.php exists.' : 'Config file has not been created yet.',
    ];
    $probe = bms_probe_private_folder_exposure();
    $items[] = [
        'label' => 'Private folder exposure',
        'status' => $probe['status'] === 'protected' ? 'pass' : ($probe['status'] === 'exposed' ? 'fail' : 'warn'),
        'message' => $probe['message'],
    ];

    $writableChecks = [
        'Private data writable' => bms_root_path('data'),
        'Temporary export storage writable' => bms_root_path('tmp/exports'),
        'Static site export temp writable' => bms_static_site_export_root(),
        'Public media writable' => bms_public_path('media'),
        'Upgrade temp writable' => bms_root_path('tmp/upgrades'),
        'Upgrade backups writable' => bms_root_path('backups/upgrades'),
        'Markdown import folder writable' => bms_content_path('import-markdown'),
    ];
    foreach ($writableChecks as $label => $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $items[] = [
            'label' => $label,
            'status' => is_dir($path) && is_writable($path) ? 'pass' : 'fail',
            'message' => (is_dir($path) && is_writable($path)) ? $path . ' is writable.' : $path . ' is not writable.',
        ];
    }

    $items[] = [
        'label' => 'ZipArchive',
        'status' => class_exists('ZipArchive') ? 'pass' : 'warn',
        'message' => class_exists('ZipArchive') ? 'ZipArchive is available for admin ZIP upgrades, theme ZIP uploads, and package exports.' : 'ZipArchive is missing. Admin ZIP upgrades, theme ZIP uploads, and package exports may not work.',
    ];
    $items[] = [
        'label' => 'Image validation',
        'status' => function_exists('getimagesize') ? 'pass' : 'fail',
        'message' => function_exists('getimagesize') ? 'getimagesize is available for validating uploaded images.' : 'getimagesize is missing. Media uploads cannot be safely validated.',
    ];
    $items[] = [
        'label' => 'File info',
        'status' => function_exists('finfo_open') ? 'pass' : 'warn',
        'message' => function_exists('finfo_open') ? 'Fileinfo is available for MIME checking uploads.' : 'Fileinfo is missing. Bonumark Stream will rely on image validation fallback checks.',
    ];
    $items[] = [
        'label' => 'Public URL mode',
        'status' => 'pass',
        'message' => 'Stream permalink routing is active.',
    ];
    return $items;
}

function bms_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
