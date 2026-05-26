<?php
function mp_config_path(): string
{
    return dirname(__DIR__) . '/config.php';
}

function mp_installed_lock_path(): string
{
    return dirname(__DIR__) . '/installed.lock';
}

function mp_default_config(): array
{
    return [
        'site_name' => 'Bonumark Stream',
        'site_tagline' => 'A self-hosted microblog stream for owning short-form publishing.',
        'active_public_theme' => 'default',
        'show_powered_by' => '1',
        'site_favicon_media_id' => '0',
        'site_favicon_path' => '',
        'public_navigation_account_links_enabled' => '1',
        'version' => '0.3.11',
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
        'database_first_import_complete' => '0',
        'comments_enabled' => '1',
        'comment_registration_enabled' => '0',
        'comments_default_status' => 'approved',
        'registration_mode' => 'disabled',
        'registration_default_role' => 'commenter',
        'registration_require_email_verification' => '1',
        'registration_require_admin_approval' => '0',
        'registration_user_role_requires_approval' => '1',
        'registration_honeypot_enabled' => '1',
        'user_publish_mode' => 'draft_review',
        'media_limit_administrator_mb' => '32',
        'media_limit_user_mb' => '8',
        'media_limit_commenter_mb' => '2',
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

function mp_config_exists(): bool
{
    return is_file(mp_config_path());
}

function mp_config(bool $reload = false): array
{
    static $config = null;
    if ($reload) {
        $config = null;
    }
    if ($config === null) {
        $config = mp_default_config();
        if (mp_config_exists()) {
            $loaded = require mp_config_path();
            if (is_array($loaded)) {
                $config = array_replace_recursive($config, $loaded);
            }
        }
        date_default_timezone_set($config['timezone'] ?? 'UTC');
    }
    return $config;
}

function mp_is_installed(): bool
{
    $config = mp_config();
    $db = $config['database'] ?? [];
    return is_file(mp_installed_lock_path()) && is_array($db) && !empty($db['host']) && !empty($db['name']) && !empty($db['user']);
}

function mp_require_installed(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }
}

function mp_setting_or_config(string $key, mixed $default = ''): mixed
{
    if (function_exists('mp_setting') && mp_is_installed()) {
        try {
            return mp_setting($key, mp_config()[$key] ?? $default);
        } catch (Throwable $e) {
            return mp_config()[$key] ?? $default;
        }
    }
    return mp_config()[$key] ?? $default;
}


function mp_plain_text(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}


function mp_site_identity_text_segment(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return $text;
}

function mp_site_identity_allowed_link_url(string $url): string
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

function mp_site_identity_anchor_attributes(string $rawAttributes): array
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

function mp_sanitize_site_identity_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $output = '';
    $offset = 0;
    $pattern = '/<a\s+([^>]*)>(.*?)<\/a>/is';
    if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
        return htmlspecialchars(mp_plain_text($html), ENT_QUOTES, 'UTF-8');
    }

    foreach ($matches as $match) {
        $start = (int)$match[0][1];
        $length = strlen((string)$match[0][0]);
        $before = substr($html, $offset, $start - $offset);
        $output .= htmlspecialchars(mp_site_identity_text_segment($before), ENT_QUOTES, 'UTF-8');

        $attributes = mp_site_identity_anchor_attributes((string)$match[1][0]);
        $href = mp_site_identity_allowed_link_url((string)($attributes['href'] ?? ''));
        $label = mp_plain_text((string)$match[2][0]);
        if ($href !== '' && $label !== '') {
            $title = mp_plain_text((string)($attributes['title'] ?? ''));
            $target = (string)($attributes['target'] ?? '');
            $target = in_array($target, ['_blank', '_self'], true) ? $target : '';
            $rel = mp_plain_text((string)($attributes['rel'] ?? ''));
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
            $output .= htmlspecialchars($label !== '' ? $label : mp_plain_text((string)$match[0][0]), ENT_QUOTES, 'UTF-8');
        }
        $offset = $start + $length;
    }

    $remaining = substr($html, $offset);
    $output .= htmlspecialchars(mp_site_identity_text_segment($remaining), ENT_QUOTES, 'UTF-8');
    $output = preg_replace('/\s+/', ' ', $output) ?? $output;
    return trim($output);
}

function mp_site_identity_plain_text(string $html): string
{
    return mp_plain_text($html);
}

function mp_root_path(string $path = ''): string
{
    return dirname(__DIR__) . ($path ? '/' . ltrim($path, '/') : '');
}

function mp_public_path(string $path = ''): string
{
    $configured = trim((string)mp_setting_or_config('public_path', ''));
    $publicRoot = $configured !== '' ? rtrim($configured, '/\\') : dirname(dirname(__DIR__));
    return $publicRoot . ($path ? '/' . ltrim($path, '/') : '');
}

function mp_content_path(string $path = ''): string
{
    return mp_root_path('content' . ($path ? '/' . ltrim($path, '/') : ''));
}

function mp_base_path(): string
{
    $basePath = trim((string)mp_setting_or_config('base_path', ''));
    if ($basePath === '' || $basePath === '/') {
        return '';
    }
    return '/' . trim($basePath, '/');
}

function mp_url_path(string $path = ''): string
{
    $base = mp_base_path();
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

function mp_admin_url(string $path = ''): string
{
    return mp_url_path('admin' . ($path ? '/' . ltrim($path, '/') : ''));
}

function mp_stream_safe_return_url(string $returnTo = ''): string
{
    $fallback = mp_url_path();
    $returnTo = trim(str_replace('\\', '/', $returnTo));
    if ($returnTo === '') {
        return $fallback;
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $returnTo) === 1 || str_starts_with($returnTo, '//')) {
        return $fallback;
    }

    $base = mp_base_path();
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

function mp_asset_url(string $path): string
{
    $url = mp_url_path($path);
    $version = rawurlencode(mp_version());
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
}


function mp_stream_relative_directory(string $slug, string $category = ''): string
{
    return 'stream/' . mp_slugify($slug);
}

function mp_stream_url(string $slug, string $category = ''): string
{
    return mp_url_path(mp_stream_relative_directory($slug, $category) . '/');
}

function mp_stream_relative_directory_for_post(array $page): string
{
    return 'stream/' . mp_slugify((string)($page['slug'] ?? ''));
}

function mp_stream_url_for_post(array $page): string
{
    return mp_url_path(mp_stream_relative_directory_for_post($page) . '/');
}


function mp_page_relative_directory(string $slug): string
{
    return 'pages/' . mp_slugify($slug);
}

function mp_page_relative_directory_for_page(array $page): string
{
    return mp_page_relative_directory((string)($page['slug'] ?? ''));
}

function mp_page_url(string $slug): string
{
    return mp_url_path(mp_page_relative_directory($slug) . '/');
}

function mp_page_url_for_page(array $page): string
{
    return mp_page_url((string)($page['slug'] ?? ''));
}




function mp_static_site_export_root(string $name = ''): string
{
    $root = mp_root_path('tmp/static-site-exports');
    if ($name !== '') {
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?? 'export';
        $root .= '/' . trim($name, '-');
    }
    return $root;
}

function mp_static_site_export_path(string $path = '', ?string $targetRoot = null): string
{
    $root = $targetRoot !== null && trim($targetRoot) !== ''
        ? rtrim($targetRoot, '/\\')
        : mp_static_site_export_root('current');
    return $root . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
}

function mp_stream_export_index_path_for_post(array $page, ?string $targetRoot = null): string
{
    return mp_static_site_export_path(mp_stream_relative_directory_for_post($page) . '/index.html', $targetRoot);
}

function mp_page_export_index_path_for_page(array $page, ?string $targetRoot = null): string
{
    return mp_static_site_export_path(mp_page_relative_directory_for_page($page) . '/index.html', $targetRoot);
}

function mp_site_url(string $path = ''): string
{
    $base = rtrim((string)mp_setting_or_config('base_url', ''), '/');
    $urlPath = mp_url_path($path);
    return $base !== '' ? $base . $urlPath : $urlPath;
}


function mp_normalize_username(string $username): string
{
    $username = strtolower(trim($username));
    $username = preg_replace('/[^a-z0-9._-]+/', '-', $username) ?? '';
    $username = trim($username, '.-_');
    return $username !== '' ? substr($username, 0, 64) : 'admin';
}

function mp_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim($text ?? '', '-');
    return $text !== '' ? $text : 'untitled-' . date('Ymd-His');
}

function mp_term_slug(string $text): string
{
    return mp_slugify($text);
}

function mp_stream_clean_text_for_seo(string $text): string
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

function mp_stream_first_heading_text(string $body): string
{
    if (preg_match('/^\s{0,3}#\s+(.+)$/m', $body, $match) !== 1) {
        return '';
    }

    return mp_stream_clean_text_for_seo((string)$match[1]);
}

function mp_stream_slug_candidate_from_text(string $text, int $maxWords = 10, int $maxLength = 72): string
{
    $text = mp_stream_clean_text_for_seo($text);
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

    $candidate = mp_slugify(implode(' ', $selected));
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

function mp_stream_limit_text(string $text, int $limit, string $suffix = '…'): string
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

function mp_stream_first_sentence(string $body): string
{
    $text = mp_stream_clean_text_for_seo($body);
    if ($text === '') {
        return '';
    }

    if (preg_match('/^(.{20,110}?[.!?])\s/u', $text, $match) === 1) {
        return trim($match[1]);
    }

    return mp_stream_limit_text($text, 90, '');
}

function mp_stream_title_case(string $text): string
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

function mp_stream_generated_post_title(string $body, string $createdAt = '', string $featuredMedia = '', array $media = [], int $limit = 70): string
{
    $candidate = mp_stream_first_sentence($body);
    if ($candidate !== '') {
        $candidate = trim($candidate, " \t\n\r\0\x0B.,;:!?");
        return mp_stream_limit_text($candidate, $limit, '…');
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
            return mp_stream_limit_text($mediaName, $limit, '…');
        }
    }

    $time = strtotime($createdAt) ?: time();
    return 'Media post from ' . date('M j, Y', $time);
}

function mp_stream_generated_seo_title(string $body, string $createdAt = '', string $featuredMedia = '', array $media = []): string
{
    $siteTitle = trim((string)mp_setting_or_config('site_name', 'Bonumark Stream'));
    $postTitle = mp_stream_generated_post_title($body, $createdAt, $featuredMedia, $media, 70);
    $postTitle = trim($postTitle);

    if ($postTitle === '') {
        $postTitle = 'Stream update';
    }

    if ($siteTitle === '') {
        return mp_stream_limit_text($postTitle, 65, '…');
    }

    $separator = ' | ';
    $siteLength = function_exists('mb_strlen') ? mb_strlen($siteTitle) : strlen($siteTitle);
    $separatorLength = function_exists('mb_strlen') ? mb_strlen($separator) : strlen($separator);
    $availableForPost = 65 - $siteLength - $separatorLength;

    if ($availableForPost >= 15) {
        $postPart = mp_stream_limit_text($postTitle, $availableForPost, '…');
        return $postPart . $separator . $siteTitle;
    }

    return mp_stream_limit_text($postTitle, 65, '…');
}

function mp_stream_generated_description(string $body, string $createdAt = '', string $featuredMedia = '', int $limit = 160): string
{
    $text = mp_stream_clean_text_for_seo($body);
    if ($text !== '') {
        return mp_stream_limit_text($text, $limit, '…');
    }

    $site = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
    $time = strtotime($createdAt) ?: time();
    if (trim($featuredMedia) !== '') {
        return 'Media post from ' . $site . ' on ' . date('F j, Y', $time) . '.';
    }
    return 'Short-form stream post from ' . $site . ' on ' . date('F j, Y', $time) . '.';
}

function mp_stream_slug_base(string $body, string $createdAt = '', array $media = [], string $title = ''): string
{
    $titleCandidate = trim($title);
    if ($titleCandidate !== '' && !mp_stream_title_needs_generation($titleCandidate)) {
        $candidate = mp_stream_slug_candidate_from_text($titleCandidate, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $headingCandidate = mp_stream_first_heading_text($body);
    if ($headingCandidate !== '') {
        $candidate = mp_stream_slug_candidate_from_text($headingCandidate, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $sentenceCandidate = mp_stream_first_sentence($body);
    if ($sentenceCandidate !== '') {
        $candidate = mp_stream_slug_candidate_from_text($sentenceCandidate, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $mediaName = trim((string)($media['original_filename'] ?? $media['filename'] ?? ''));
    if ($mediaName !== '') {
        $mediaName = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $mediaName) ?? $mediaName;
        $mediaName = str_replace(['-', '_'], ' ', $mediaName);
        $candidate = mp_stream_slug_candidate_from_text($mediaName, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return 'stream-post';
}

function mp_stream_unique_slug(string $baseSlug, string $currentSlug = ''): string
{
    $baseSlug = mp_slugify($baseSlug);
    if ($baseSlug === '') {
        $baseSlug = 'stream-post-' . date('Ymd');
    }

    $currentSlug = mp_slugify($currentSlug);
    $slug = $baseSlug;
    $counter = 2;
    while (true) {
        $published = mp_content_path('published/' . $slug . '.md');
        $draft = mp_content_path('drafts/' . $slug . '.md');
        $databaseConflict = function_exists('mp_database_slug_exists') && mp_database_slug_exists($slug, $currentSlug, 'stream');
        $conflicts = ($currentSlug === '' || $slug !== $currentSlug) && ($databaseConflict || is_file($published) || is_file($draft));
        if (!$conflicts) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function mp_page_clean_slug_candidate(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    return trim($slug, '-');
}

function mp_page_generated_seo_title(string $title): string
{
    $pageTitle = trim($title);
    if ($pageTitle === '') {
        $pageTitle = 'Untitled Page';
    }

    $siteTitle = trim((string)mp_setting_or_config('site_name', 'Bonumark Stream'));
    if ($siteTitle === '') {
        return mp_stream_limit_text($pageTitle, 65, '…');
    }

    return mp_stream_limit_text($pageTitle . ' | ' . $siteTitle, 65, '…');
}

function mp_page_unique_slug(string $baseSlug, string $currentSlug = ''): string
{
    $baseSlug = mp_page_clean_slug_candidate($baseSlug);
    if ($baseSlug === '') {
        $baseSlug = 'page';
    }

    $reserved = ['admin', 'assets', 'install', 'stream', 'media', 'feed', 'account', 'profile', 'author', 'comments', 'search', 'page'];
    if (in_array($baseSlug, $reserved, true)) {
        $baseSlug = 'page-' . $baseSlug;
    }

    $currentSlug = mp_slugify($currentSlug);
    $slug = $baseSlug;
    $counter = 2;
    while (true) {
        $published = mp_content_path('pages/published/' . $slug . '.md');
        $draft = mp_content_path('pages/drafts/' . $slug . '.md');
        $streamPublished = mp_content_path('published/' . $slug . '.md');
        $streamDraft = mp_content_path('drafts/' . $slug . '.md');
        $databaseConflict = function_exists('mp_database_slug_exists') && (mp_database_slug_exists($slug, $currentSlug, 'page') || mp_database_slug_exists($slug, $currentSlug, 'stream'));
        $conflicts = ($currentSlug === '' || $slug !== $currentSlug) && ($databaseConflict || is_file($published) || is_file($draft) || is_file($streamPublished) || is_file($streamDraft));
        if (!$conflicts) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function mp_page_status_section(string $status): string
{
    return $status === 'published' ? 'pages/published' : 'pages/drafts';
}

function mp_page_slug_needs_generation(string $slug): bool
{
    $rawSlug = trim($slug);
    if ($rawSlug === '') {
        return true;
    }

    $cleanSlug = mp_page_clean_slug_candidate($rawSlug);
    if ($cleanSlug === '' || in_array($cleanSlug, ['untitled', 'generated-on-save'], true)) {
        return true;
    }

    return preg_match('/^untitled-\d{8}(?:-\d{6})?$/', $cleanSlug) === 1;
}

function mp_page_prepare_metadata_fields(array $fields, string $body, string $currentSlug = ''): array
{
    $title = trim((string)($fields['title'] ?? ''));
    if ($title === '') {
        $title = mp_first_heading($body) ?: 'Untitled Page';
    }

    $slugInput = trim((string)($fields['slug'] ?? ''));
    $slug = mp_page_slug_needs_generation($slugInput) ? mp_page_unique_slug($title, $currentSlug) : mp_page_unique_slug($slugInput, $currentSlug);

    $seoTitle = trim((string)($fields['seo_title'] ?? ''));
    if ($seoTitle === mp_page_generated_seo_title($title) || $seoTitle === $title) {
        $seoTitle = '';
    }

    $description = trim((string)($fields['description'] ?? ''));
    if ($description === '') {
        $description = mp_plain_excerpt($body, 160);
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

function mp_build_page_markdown_from_request(string $forcedStatus = 'draft', string $currentSlug = ''): string
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
    $fields = mp_page_prepare_metadata_fields($fields, $body, $currentSlug);
    return mp_build_markdown_document($fields, $body);
}

function mp_list_page_records(string $status = 'published'): array
{
    $section = $status === 'published' ? 'pages/published' : 'pages/drafts';
    return array_values(array_filter(mp_list_content_records($section), function (array $page): bool {
        return mp_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'page')) === 'page';
    }));
}




function mp_stream_slug_needs_generation(string $slug): bool
{
    $slug = mp_slugify($slug);
    if ($slug === '') {
        return true;
    }

    if (in_array($slug, ['untitled', 'generated-on-save'], true)) {
        return true;
    }

    return preg_match('/^(stream|stream-post|untitled)-\d{8}(?:-\d{6})?$/', $slug) === 1;
}

function mp_stream_title_needs_generation(string $title): bool
{
    $title = trim($title);
    if ($title === '' || strtolower($title) === 'untitled') {
        return true;
    }

    return preg_match('/^Stream Post:\s+/i', $title) === 1;
}

function mp_stream_media_context_from_path(string $featuredMedia): array
{
    $featuredMedia = trim($featuredMedia);
    if ($featuredMedia === '') {
        return [];
    }

    return ['filename' => basename($featuredMedia)];
}

function mp_stream_prepare_metadata_fields(array $fields, string $body, string $currentSlug = ''): array
{
    $createdAt = trim((string)($fields['stream_created_at'] ?? $fields['created_at'] ?? $fields['date'] ?? date('Y-m-d H:i:s')));
    $featuredMedia = trim((string)($fields['featured_media'] ?? ''));
    $mediaContext = mp_stream_media_context_from_path($featuredMedia);

    $manualTitle = trim((string)($fields['title'] ?? ''));
    $title = $manualTitle;
    if (mp_stream_title_needs_generation($title)) {
        $title = mp_stream_admin_title_from_body($body, $createdAt, $featuredMedia, $mediaContext);
    }

    $slugInput = trim((string)($fields['slug'] ?? ''));
    if (mp_stream_slug_needs_generation($slugInput)) {
        $slug = mp_stream_unique_slug(mp_stream_slug_base($body, $createdAt, $mediaContext, $manualTitle), $currentSlug);
    } else {
        $slug = mp_slugify($slugInput);
    }

    $seoTitle = trim((string)($fields['seo_title'] ?? ''));
    if ($seoTitle === '') {
        $seoTitle = mp_stream_generated_seo_title($body, $createdAt, $featuredMedia, $mediaContext);
    }

    $description = trim((string)($fields['description'] ?? ''));
    if ($description === '') {
        $description = mp_stream_generated_description($body, $createdAt, $featuredMedia);
    }

    $fields['title'] = $title;
    $fields['slug'] = $slug;
    $fields['seo_title'] = $seoTitle;
    $fields['description'] = $description;
    $fields['stream_created_at'] = $createdAt;

    return $fields;
}



function mp_parse_markdown_file(string $file): array
{
    $raw = file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException('Could not read Markdown file.');
    }
    return mp_parse_markdown_string($raw);
}

function mp_parse_markdown_string(string $raw): array
{
    $frontMatter = [];
    $body = $raw;

    if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $matches)) {
        $frontMatterRaw = trim($matches[1]);
        $body = $matches[2];
        $frontMatter = mp_parse_front_matter($frontMatterRaw);
    }

    $title = $frontMatter['title'] ?? mp_first_heading($body) ?? 'Untitled';
    $slug = $frontMatter['slug'] ?? mp_slugify($title);
    $description = $frontMatter['description'] ?? '';
    $date = $frontMatter['date'] ?? date('Y-m-d');
    $category = $frontMatter['category'] ?? 'Stream';
    $status = $frontMatter['status'] ?? 'draft';
    $contentType = mp_normalize_content_type((string)($frontMatter['content_type'] ?? $frontMatter['post_type'] ?? 'stream'));
    $tags = mp_normalize_terms($frontMatter['tags'] ?? []);
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
        'slug' => mp_slugify((string)$slug),
        'description' => trim((string)$description),
        'date' => trim((string)$date),
        'category' => $category,
        'category_slug' => mp_term_slug($category),
        'tags' => $tags,
        'tag_slugs' => array_map('mp_term_slug', $tags),
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

function mp_parse_front_matter(string $raw): array
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

function mp_normalize_terms(mixed $value): array
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


function mp_front_matter_quote(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));
    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    return '"' . $value . '"';
}

function mp_build_markdown_document(array $fields, string $body): string
{
    $title = trim((string)($fields['title'] ?? 'Untitled'));
    if ($title === '') {
        $title = 'Untitled';
    }

    $slug = mp_slugify((string)($fields['slug'] ?? $title));
    $status = trim((string)($fields['status'] ?? 'draft'));
    if (!in_array($status, ['draft', 'published'], true)) {
        $status = 'draft';
    }

    $date = trim((string)($fields['date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $description = trim((string)($fields['description'] ?? ''));
    $contentType = mp_normalize_content_type((string)($fields['content_type'] ?? $fields['post_type'] ?? 'stream'));
    $category = $contentType === 'page' ? 'Page' : 'Stream';

    $tags = mp_normalize_terms($fields['tags'] ?? []);

    $lines = [
        '---',
        'title: ' . mp_front_matter_quote($title),
        'slug: ' . mp_front_matter_quote($slug),
        'status: ' . mp_front_matter_quote($status),
        'content_type: ' . mp_front_matter_quote($contentType),
        'date: ' . $date,
        'description: ' . mp_front_matter_quote($description),
        'category: ' . mp_front_matter_quote($category),
    ];

    if ($tags) {
        $lines[] = 'tags:';
        foreach ($tags as $tag) {
            $lines[] = '  - ' . mp_front_matter_quote($tag);
        }
    } else {
        $lines[] = 'tags: ""';
    }

    foreach (['featured_media', 'stream_created_at', 'seo_title', 'robots', 'link_preview_url', 'link_preview_title', 'link_preview_description', 'link_preview_image', 'link_preview_site_name'] as $streamKey) {
        $streamValue = trim((string)($fields[$streamKey] ?? ''));
        if ($streamValue !== '') {
            $lines[] = $streamKey . ': ' . mp_front_matter_quote($streamValue);
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

function mp_existing_stream_front_matter_for_slug(string $slug): array
{
    $slug = mp_slugify($slug);
    if ($slug === '') {
        return [];
    }
    foreach (['published', 'drafts'] as $section) {
        $path = mp_content_path($section . '/' . $slug . '.md');
        if (!is_file($path)) {
            continue;
        }
        try {
            $page = mp_parse_markdown_file($path);
            return is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
        } catch (Throwable $e) {
            return [];
        }
    }
    return [];
}

function mp_stream_link_preview_request_fields(string $currentSlug = ''): array
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

    $existing = mp_existing_stream_front_matter_for_slug($currentSlug);
    $fields = [];
    foreach ($keys as $key) {
        $fields[$key] = trim((string)($existing[$key] ?? ''));
    }
    return trim((string)$fields['link_preview_url']) !== '' ? $fields : [];
}

function mp_build_markdown_from_request(string $forcedStatus = 'draft', string $currentSlug = ''): string
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
    $fields = array_merge($fields, mp_stream_link_preview_request_fields($currentSlug));
    $fields = mp_stream_prepare_metadata_fields($fields, $body, $currentSlug);

    return mp_build_markdown_document($fields, $body);
}

function mp_first_heading(string $body): ?string
{
    if (preg_match('/^#\s+(.+)$/m', $body, $m)) {
        return trim($m[1]);
    }
    return null;
}

function mp_list_legacy_markdown_files(string $section): array
{
    $dir = mp_content_path($section);
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.md') ?: [];
    $items = [];
    foreach ($files as $file) {
        try {
            $parsed = mp_parse_markdown_file($file);
            $parsed['filename'] = basename($file);
            $parsed['path'] = $file;
            $parsed['section'] = $section;
            $parsed['content_storage'] = 'legacy-markdown';
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

function mp_list_content_records(string $section): array
{
    if (function_exists('mp_database_content_enabled') && function_exists('mp_database_content_columns_ready') && mp_database_content_enabled() && mp_database_content_columns_ready()) {
        try {
            return mp_list_database_content_for_section($section);
        } catch (Throwable $e) {
            // Database content is authoritative. Legacy Markdown files are retained for controlled import and recovery tooling.
        }
    }

    return mp_list_legacy_markdown_files($section);
}


function mp_write_file(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create directory: ' . $dir);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not write file: ' . $path);
    }
}

function mp_delete_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? mp_delete_directory($path) : unlink($path);
    }
    rmdir($dir);
}


function mp_normalize_content_type(string $type): string
{
    $type = strtolower(trim($type));
    if ($type === 'page') {
        return 'page';
    }
    // Legacy content-type values are tolerated and treated as stream posts.
    return 'stream';
}


function mp_homepage_mode(): string
{
    return 'stream';
}

function mp_stream_composer_enabled(): bool
{
    return (string)mp_setting_or_config('stream_composer_enabled', '1') === '1';
}

function mp_stream_show_dates(): bool
{
    return (string)mp_setting_or_config('stream_show_dates', '1') === '1';
}

function mp_stream_show_edit_links(): bool
{
    return (string)mp_setting_or_config('stream_show_edit_links', '0') === '1';
}


function mp_stream_index_policy(): string
{
    $policy = (string)mp_setting_or_config('stream_index_policy', 'smart');
    return in_array($policy, ['all', 'smart', 'noindex'], true) ? $policy : 'smart';
}



function mp_stream_posts_per_page(): int
{
    $count = (int)mp_setting_or_config('stream_posts_per_page', '20');
    if ($count < 1) {
        return 1;
    }
    if ($count > 100) {
        return 100;
    }
    return $count;
}

function mp_is_stream_post(array $page): bool
{
    return mp_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'stream')) === 'stream';
}

function mp_filter_stream_posts(array $pages): array
{
    return array_values(array_filter($pages, 'mp_is_stream_post'));
}

function mp_sort_stream_posts(array $pages): array
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

function mp_apply_stream_reading_settings(array $pages): array
{
    return array_slice(mp_sort_stream_posts($pages), 0, mp_stream_posts_per_page());
}

function mp_stream_preview_text(array $page, int $limit = 90): string
{
    $body = mp_stream_clean_text_for_seo((string)($page['body'] ?? ''));
    if ($body === '') {
        return trim((string)($page['description'] ?? '')) ?: 'Media post';
    }
    return mp_stream_limit_text($body, $limit, '…');
}

function mp_stream_admin_title_from_body(string $body, string $createdAt = '', string $featuredMedia = '', array $media = []): string
{
    return mp_stream_generated_post_title($body, $createdAt, $featuredMedia, $media, 70);
}


function mp_autosave_enabled(): bool
{
    return (string)mp_setting_or_config('autosave_enabled', '1') === '1';
}


function mp_default_editor_mode(): string
{
    $mode = (string)mp_setting_or_config('default_editor_mode', 'visual');
    return in_array($mode, ['visual', 'markdown'], true) ? $mode : 'visual';
}


function mp_default_content_status(): string
{
    $status = (string)mp_setting_or_config('default_content_status', 'draft');
    return in_array($status, ['draft', 'published'], true) ? $status : 'draft';
}


function mp_query_string(array $params): string
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

function mp_version(): string
{
    $versionFile = mp_root_path('VERSION');
    if (is_file($versionFile)) {
        $version = trim((string)file_get_contents($versionFile));
        if ($version !== '') {
            return $version;
        }
    }

    $configured = trim((string)(mp_config()['version'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    return 'unknown';
}


function mp_abort_request(string $message, int $status = 400): void
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $message;
    exit;
}

function mp_flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function mp_get_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}


function mp_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return false;
}

function mp_start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $secure = mp_is_https();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function mp_send_security_headers(): void
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

function mp_password_policy_error(string $password, string $username = '', string $email = ''): ?string
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

function mp_validate_password_policy(string $password, string $username = '', string $email = ''): void
{
    $error = mp_password_policy_error($password, $username, $email);
    if ($error !== null) {
        throw new RuntimeException($error);
    }
}

function mp_request_origin(): string
{
    $scheme = mp_is_https() ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return $host !== '' ? $scheme . '://' . $host : '';
}

function mp_install_base_url_from_request(): string
{
    $origin = mp_request_origin();
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

function mp_probe_private_folder_exposure(?string $baseUrl = null): array
{
    $baseUrl = $baseUrl !== null && trim($baseUrl) !== '' ? rtrim(trim($baseUrl), '/') : mp_install_base_url_from_request();
    $secret = 'bonumark-private-probe-' . bin2hex(random_bytes(16));
    $probeFile = mp_root_path('security-probe-' . bin2hex(random_bytes(6)) . '.txt');

    try {
        mp_write_file($probeFile, $secret);
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

function mp_security_status(): array
{
    $items = [];
    $items[] = [
        'label' => 'PHP version',
        'status' => version_compare(PHP_VERSION, '8.2.0', '>=') ? 'pass' : 'fail',
        'message' => PHP_VERSION . (version_compare(PHP_VERSION, '8.2.0', '>=') ? ' is supported.' : ' is below the PHP 8.2 minimum target.'),
    ];
    $items[] = [
        'label' => 'HTTPS',
        'status' => mp_is_https() ? 'pass' : 'warn',
        'message' => mp_is_https() ? 'Admin requests appear to be using HTTPS.' : 'HTTPS was not detected. Use HTTPS for real sites.',
    ];
    $items[] = [
        'label' => 'PDO MySQL',
        'status' => function_exists('mp_db_supports_mysql') && mp_db_supports_mysql() ? 'pass' : 'fail',
        'message' => function_exists('mp_db_supports_mysql') && mp_db_supports_mysql() ? 'PDO MySQL is available.' : 'PDO MySQL is not available.',
    ];

    $dbStatus = 'warn';
    $dbMessage = 'Database configuration has not been verified.';
    if (function_exists('mp_has_database_config') && mp_has_database_config()) {
        try {
            if (function_exists('mp_db')) {
                mp_db()->query('SELECT 1');
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
        'status' => is_file(mp_config_path()) ? 'pass' : 'warn',
        'message' => is_file(mp_config_path()) ? '_bonumark_stream/config.php exists.' : 'Config file has not been created yet.',
    ];
    $probe = mp_probe_private_folder_exposure();
    $items[] = [
        'label' => 'Private folder exposure',
        'status' => $probe['status'] === 'protected' ? 'pass' : ($probe['status'] === 'exposed' ? 'fail' : 'warn'),
        'message' => $probe['message'],
    ];

    $writableChecks = [
        'Private data writable' => mp_root_path('data'),
        'Temporary export storage writable' => mp_root_path('tmp/exports'),
        'Static site export temp writable' => mp_static_site_export_root(),
        'Public media writable' => mp_public_path('media'),
        'Upgrade temp writable' => mp_root_path('tmp/upgrades'),
        'Upgrade backups writable' => mp_root_path('backups/upgrades'),
        'Legacy Markdown import folder writable' => mp_content_path('legacy-markdown'),
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

function mp_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
