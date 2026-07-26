<?php
require_once __DIR__ . '/database.php';

/**
 * Privacy-First Analytics
 *
 * This module records only aggregate public page-view counters. It deliberately
 * avoids cookies, browser storage, visitor IDs, sessions, IP hashes, user-agent
 * storage, raw referrers, query strings, and event-level browsing logs.
 */

function bms_analytics_enabled(): bool
{
    return (string)bms_setting_or_config('analytics_enabled', '0') === '1';
}

function bms_analytics_retention_days(): int
{
    $days = (int)bms_setting_or_config('analytics_retention_days', '90');
    $allowed = [30, 90, 180, 365, 730];
    return in_array($days, $allowed, true) ? $days : 90;
}

function bms_analytics_allowed_retention_days(): array
{
    return [30, 90, 180, 365, 730];
}

function bms_analytics_site_day(): string
{
    try {
        return (new DateTimeImmutable('now', bms_site_timezone()))->format('Y-m-d');
    } catch (Throwable $e) {
        return gmdate('Y-m-d');
    }
}

function bms_analytics_clean_text(string $value, int $limit = 100): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    if ($limit < 1) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
    }
    return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
}

function bms_analytics_normalize_public_path(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    // The collector accepts a relative path only. Strip accidental absolute
    // URL components, query strings, and fragments before any persistence.
    $parsed = @parse_url($value);
    if (is_array($parsed)) {
        if (isset($parsed['host']) || isset($parsed['scheme'])) {
            $value = (string)($parsed['path'] ?? '');
        } else {
            $value = (string)($parsed['path'] ?? $value);
        }
    }
    $value = preg_split('/[?#]/', $value, 2)[0] ?? '';
    $value = '/' . ltrim(str_replace('\\', '/', $value), '/');
    $value = preg_replace('#/+#', '/', $value) ?? '/';

    $base = bms_base_path();
    if ($base !== '' && ($value === $base || str_starts_with($value, $base . '/'))) {
        $value = substr($value, strlen($base));
        $value = $value === '' ? '/' : $value;
    }

    $segments = array_values(array_filter(explode('/', trim($value, '/')), static function (string $segment): bool {
        return $segment !== '' && $segment !== '.' && $segment !== '..';
    }));
    foreach ($segments as $segment) {
        if (!preg_match('/^[A-Za-z0-9._~-]+$/', $segment)) {
            return '';
        }
    }

    $normalized = '/' . implode('/', $segments);
    if ($normalized !== '/' && str_ends_with($value, '/')) {
        $normalized .= '/';
    }
    return bms_analytics_clean_text($normalized, 255);
}

function bms_analytics_path_is_eligible(string $path): bool
{
    if ($path === '') {
        return false;
    }

    $blockedPrefixes = [
        '/admin', '/account', '/api', '/install.php', '/manifest.php', '/pwa-icon.php',
        '/analytics.php', '/feed.xml', '/sitemap.xml', '/sitemap.xsl', '/robots.txt',
        '/search', '/comments.php', '/profile.php', '/page.php', '/stream-like.php',
        '/sw.js', '/assets', '/media', '/_bonumark_stream', '/scripts',
    ];
    foreach ($blockedPrefixes as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return false;
        }
    }

    // Public reading routes only: home, stream archive/singles, and public pages.
    return $path === '/'
        || $path === '/stream/'
        || preg_match('#^/stream/(?:page/[1-9][0-9]*/?|[A-Za-z0-9._~-]+/?)$#', $path) === 1
        || preg_match('#^/pages/[A-Za-z0-9._~-]+/?$#', $path) === 1;
}

function bms_analytics_content_from_path(string $path): array
{
    if ($path === '/') {
        return ['content_type' => 'home', 'content_slug' => ''];
    }
    if ($path === '/stream/' || preg_match('#^/stream/page/[1-9][0-9]*/$#', $path) === 1) {
        return ['content_type' => 'stream_index', 'content_slug' => ''];
    }
    if (preg_match('#^/stream/([A-Za-z0-9._~-]+)/?$#', $path, $matches) === 1) {
        return ['content_type' => 'stream', 'content_slug' => bms_slugify((string)$matches[1])];
    }
    if (preg_match('#^/pages/([A-Za-z0-9._~-]+)/?$#', $path, $matches) === 1) {
        return ['content_type' => 'page', 'content_slug' => bms_slugify((string)$matches[1])];
    }
    return ['content_type' => 'other', 'content_slug' => ''];
}

function bms_analytics_referrer_domain(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Direct';
    }
    $parts = @parse_url($value);
    $host = is_array($parts) ? strtolower(trim((string)($parts['host'] ?? ''))) : '';
    if ($host === '' || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) {
        return 'Direct';
    }
    return bms_analytics_clean_text($host, 253) ?: 'Direct';
}

function bms_analytics_sanitize_utm(string $value): string
{
    $value = strtolower(bms_analytics_clean_text($value, 100));
    $value = preg_replace('/[^a-z0-9._~-]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function bms_analytics_classify_user_agent(string $userAgent): array
{
    $userAgent = strtolower(trim($userAgent));
    $bot = $userAgent === '' || preg_match('/(?:bot|crawler|spider|slurp|bingpreview|facebookexternalhit|discordbot|telegrambot|whatsapp|preview|curl|wget|python-requests|headless|lighthouse|uptimerobot)/i', $userAgent) === 1;

    $device = 'other';
    if (preg_match('/(?:ipad|tablet|kindle|silk\/)/i', $userAgent) === 1) {
        $device = 'tablet';
    } elseif (preg_match('/(?:mobi|android|iphone|ipod|windows phone)/i', $userAgent) === 1) {
        $device = 'mobile';
    } elseif ($userAgent !== '') {
        $device = 'desktop';
    }

    $browser = 'Other';
    if (preg_match('/edg\//i', $userAgent) === 1) {
        $browser = 'Edge';
    } elseif (preg_match('/(?:chrome|crios)\//i', $userAgent) === 1 && preg_match('/(?:edg|opr|opera)\//i', $userAgent) !== 1) {
        $browser = 'Chrome';
    } elseif (preg_match('/firefox|fxios/i', $userAgent) === 1) {
        $browser = 'Firefox';
    } elseif (preg_match('/safari/i', $userAgent) === 1 && preg_match('/(?:chrome|crios|android)/i', $userAgent) !== 1) {
        $browser = 'Safari';
    }

    return ['bot' => $bot, 'device_category' => $device, 'browser_family' => $browser];
}

function bms_analytics_same_origin_request(): bool
{
    $siteUrl = bms_site_url();
    $siteParts = @parse_url($siteUrl);
    $siteScheme = strtolower((string)(is_array($siteParts) ? ($siteParts['scheme'] ?? '') : ''));
    $siteHost = strtolower((string)(is_array($siteParts) ? ($siteParts['host'] ?? '') : ''));
    $sitePort = (int)(is_array($siteParts) ? ($siteParts['port'] ?? 0) : 0);

    // A relative base_url is common on shared hosting. In that case, compare
    // against the current configured host while still rejecting cross-origin
    // browser requests when Origin or Fetch Metadata is available.
    if ($siteHost === '') {
        $siteHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $siteHost = preg_replace('/:\d+$/', '', $siteHost) ?? '';
    }
    if ($siteHost === '') {
        return false;
    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        $originParts = @parse_url($origin);
        $originScheme = strtolower((string)(is_array($originParts) ? ($originParts['scheme'] ?? '') : ''));
        $originHost = strtolower((string)(is_array($originParts) ? ($originParts['host'] ?? '') : ''));
        $originPort = (int)(is_array($originParts) ? ($originParts['port'] ?? 0) : 0);
        if ($originHost === '' || !hash_equals($siteHost, $originHost)) {
            return false;
        }
        if ($siteScheme !== '' && $originScheme !== '' && !hash_equals($siteScheme, $originScheme)) {
            return false;
        }
        if ($sitePort > 0 && $originPort > 0 && $sitePort !== $originPort) {
            return false;
        }
    }

    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
        return false;
    }

    return true;
}
function bms_analytics_parse_collector_payload(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '' || strlen($raw) > 4096) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bms_analytics_record_page_view(array $payload, string $userAgent = ''): bool
{
    if (!bms_analytics_enabled()) {
        return false;
    }

    $path = bms_analytics_normalize_public_path((string)($payload['path'] ?? ''));
    if (!bms_analytics_path_is_eligible($path)) {
        return false;
    }

    $classification = bms_analytics_classify_user_agent($userAgent);
    if (!empty($classification['bot'])) {
        return false;
    }

    $content = bms_analytics_content_from_path($path);
    $referrer = bms_analytics_referrer_domain((string)($payload['referrer'] ?? ''));
    $source = bms_analytics_sanitize_utm((string)($payload['utm_source'] ?? ''));
    $medium = bms_analytics_sanitize_utm((string)($payload['utm_medium'] ?? ''));
    $campaign = bms_analytics_sanitize_utm((string)($payload['utm_campaign'] ?? ''));

    $sql = 'INSERT INTO ' . bms_table('analytics_daily')
        . ' (report_date, page_path, content_type, content_slug, referrer_domain, device_category, browser_family, utm_source, utm_medium, utm_campaign, page_views, updated_at)'
        . ' VALUES (:report_date, :page_path, :content_type, :content_slug, :referrer_domain, :device_category, :browser_family, :utm_source, :utm_medium, :utm_campaign, 1, UTC_TIMESTAMP())'
        . ' ON DUPLICATE KEY UPDATE page_views = page_views + 1, updated_at = UTC_TIMESTAMP()';
    $stmt = bms_db()->prepare($sql);
    $stmt->execute([
        'report_date' => bms_analytics_site_day(),
        'page_path' => $path,
        'content_type' => (string)$content['content_type'],
        'content_slug' => bms_analytics_clean_text((string)$content['content_slug'], 190),
        'referrer_domain' => $referrer,
        'device_category' => (string)$classification['device_category'],
        'browser_family' => (string)$classification['browser_family'],
        'utm_source' => $source,
        'utm_medium' => $medium,
        'utm_campaign' => $campaign,
    ]);

    bms_analytics_cleanup_expired(false);
    return true;
}

function bms_analytics_cleanup_expired(bool $force = false): int
{
    if (!bms_is_installed()) {
        return 0;
    }

    $today = bms_analytics_site_day();
    $lastCleanup = (string)bms_setting('analytics_last_cleanup_date', '');
    if (!$force && $lastCleanup === $today) {
        return 0;
    }

    $cutoff = (new DateTimeImmutable($today, bms_site_timezone()))
        ->modify('-' . bms_analytics_retention_days() . ' days')
        ->format('Y-m-d');
    $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('analytics_daily') . ' WHERE report_date < :cutoff');
    $stmt->execute(['cutoff' => $cutoff]);
    bms_set_setting('analytics_last_cleanup_date', $today);
    return $stmt->rowCount();
}

function bms_analytics_clear_all_data(): int
{
    $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('analytics_daily'));
    $stmt->execute();
    bms_set_setting('analytics_last_cleanup_date', bms_analytics_site_day());
    return $stmt->rowCount();
}

function bms_analytics_range_dates(string $range): array
{
    $range = strtolower(trim($range));
    $allowed = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90];
    $days = $allowed[$range] ?? 30;
    $today = new DateTimeImmutable(bms_analytics_site_day(), bms_site_timezone());
    $start = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    return ['key' => array_key_exists($range, $allowed) ? $range : '30d', 'days' => $days, 'start' => $start, 'end' => $today->format('Y-m-d')];
}

function bms_analytics_query_rows(string $sql, array $params = []): array
{
    try {
        $stmt = bms_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function bms_analytics_summary(string $range = '30d'): array
{
    $dates = bms_analytics_range_dates($range);
    $table = bms_table('analytics_daily');
    $params = ['start' => $dates['start'], 'end' => $dates['end']];
    $where = ' WHERE report_date BETWEEN :start AND :end';

    $totalRows = bms_analytics_query_rows('SELECT COALESCE(SUM(page_views), 0) AS total FROM ' . $table . $where, $params);
    $total = (int)($totalRows[0]['total'] ?? 0);

    $daily = bms_analytics_query_rows('SELECT report_date, SUM(page_views) AS page_views FROM ' . $table . $where . ' GROUP BY report_date ORDER BY report_date ASC', $params);
    $posts = bms_analytics_query_rows("SELECT page_path, content_type, content_slug, SUM(page_views) AS page_views FROM {$table}{$where} AND content_type = 'stream' GROUP BY page_path, content_type, content_slug ORDER BY page_views DESC, page_path ASC LIMIT 10", $params);
    $pages = bms_analytics_query_rows("SELECT page_path, content_type, content_slug, SUM(page_views) AS page_views FROM {$table}{$where} AND content_type = 'page' GROUP BY page_path, content_type, content_slug ORDER BY page_views DESC, page_path ASC LIMIT 10", $params);
    $entries = bms_analytics_query_rows('SELECT page_path, content_type, content_slug, SUM(page_views) AS page_views FROM ' . $table . $where . ' GROUP BY page_path, content_type, content_slug ORDER BY page_views DESC, page_path ASC LIMIT 10', $params);
    $referrers = bms_analytics_query_rows('SELECT referrer_domain, SUM(page_views) AS page_views FROM ' . $table . $where . ' GROUP BY referrer_domain ORDER BY page_views DESC, referrer_domain ASC LIMIT 10', $params);
    $devices = bms_analytics_query_rows('SELECT device_category, SUM(page_views) AS page_views FROM ' . $table . $where . ' GROUP BY device_category ORDER BY page_views DESC, device_category ASC', $params);
    $browsers = bms_analytics_query_rows('SELECT browser_family, SUM(page_views) AS page_views FROM ' . $table . $where . ' GROUP BY browser_family ORDER BY page_views DESC, browser_family ASC', $params);
    $campaigns = bms_analytics_query_rows("SELECT utm_source, utm_medium, utm_campaign, SUM(page_views) AS page_views FROM {$table}{$where} AND (utm_source <> '' OR utm_medium <> '' OR utm_campaign <> '') GROUP BY utm_source, utm_medium, utm_campaign ORDER BY page_views DESC, utm_campaign ASC LIMIT 10", $params);

    return compact('dates', 'total', 'daily', 'posts', 'pages', 'entries', 'referrers', 'devices', 'browsers', 'campaigns');
}

function bms_analytics_content_label(array $row): string
{
    $slug = bms_slugify((string)($row['content_slug'] ?? ''));
    $type = (string)($row['content_type'] ?? '');
    try {
        if ($slug !== '' && $type === 'stream' && function_exists('bms_find_database_content_by_slug_status')) {
            $post = bms_find_database_content_by_slug_status($slug, 'published', 'stream');
            if (is_array($post) && trim((string)($post['title'] ?? '')) !== '') {
                return trim((string)$post['title']);
            }
        }
        if ($slug !== '' && $type === 'page' && function_exists('bms_find_database_content_by_slug_status')) {
            $page = bms_find_database_content_by_slug_status($slug, 'published', 'page');
            if (is_array($page) && trim((string)($page['title'] ?? '')) !== '') {
                return trim((string)$page['title']);
            }
        }
    } catch (Throwable $e) {
        // Report paths remain usable when a content item has been removed.
    }
    return (string)($row['page_path'] ?? '/');
}

function bms_analytics_dashboard_summary(): array
{
    if (!bms_analytics_enabled()) {
        return [
            'enabled' => false,
            'today_views' => 0,
            'seven_day_views' => 0,
            'top_path' => '',
            'top_label' => 'No data yet',
            'top_referrer' => 'No data yet',
        ];
    }
    $today = bms_analytics_summary('today');
    $seven = bms_analytics_summary('7d');
    $topEntry = $seven['entries'][0] ?? [];
    $topReferrer = $seven['referrers'][0] ?? [];
    return [
        'enabled' => bms_analytics_enabled(),
        'today_views' => (int)($today['total'] ?? 0),
        'seven_day_views' => (int)($seven['total'] ?? 0),
        'top_path' => (string)($topEntry['page_path'] ?? ''),
        'top_label' => $topEntry ? bms_analytics_content_label($topEntry) : 'No data yet',
        'top_referrer' => (string)($topReferrer['referrer_domain'] ?? 'No data yet'),
    ];
}

function bms_analytics_export_csv(string $range = '30d'): string
{
    $summary = bms_analytics_summary($range);
    $dates = $summary['dates'];
    $table = bms_table('analytics_daily');
    $rows = bms_analytics_query_rows(
        'SELECT report_date, page_path, content_type, content_slug, referrer_domain, device_category, browser_family, utm_source, utm_medium, utm_campaign, page_views FROM ' . $table . ' WHERE report_date BETWEEN :start AND :end ORDER BY report_date ASC, page_path ASC, referrer_domain ASC',
        ['start' => $dates['start'], 'end' => $dates['end']]
    );

    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return '';
    }
    fputcsv($handle, ['report_date', 'page_path', 'content_type', 'content_slug', 'referrer_domain', 'device_category', 'browser_family', 'utm_source', 'utm_medium', 'utm_campaign', 'page_views']);
    foreach ($rows as $row) {
        fputcsv($handle, [
            (string)($row['report_date'] ?? ''),
            (string)($row['page_path'] ?? ''),
            (string)($row['content_type'] ?? ''),
            (string)($row['content_slug'] ?? ''),
            (string)($row['referrer_domain'] ?? ''),
            (string)($row['device_category'] ?? ''),
            (string)($row['browser_family'] ?? ''),
            (string)($row['utm_source'] ?? ''),
            (string)($row['utm_medium'] ?? ''),
            (string)($row['utm_campaign'] ?? ''),
            (int)($row['page_views'] ?? 0),
        ]);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return is_string($csv) ? $csv : '';
}

function bms_analytics_collector_markup(): string
{
    if (!bms_analytics_enabled()) {
        return '';
    }
    $endpoint = htmlspecialchars(bms_url_path('analytics.php'), ENT_QUOTES, 'UTF-8');
    return '<script src="' . htmlspecialchars(bms_asset_url('assets/analytics.js'), ENT_QUOTES, 'UTF-8') . '" data-bonumark-analytics-endpoint="' . $endpoint . '" defer></script>';
}

function bms_analytics_current_request_path(): string
{
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = @parse_url($requestUri, PHP_URL_PATH);
    return bms_analytics_normalize_public_path(is_string($path) ? $path : '');
}

function bms_inject_public_analytics_script(string $html): string
{
    // Core injection keeps themes free of analytics code. It is still limited
    // to eligible public reading routes, so account, profile, admin, API,
    // feeds, search, and other excluded pages never even load the collector.
    $path = bms_analytics_current_request_path();
    if (!bms_analytics_path_is_eligible($path)) {
        return $html;
    }
    if (function_exists('bms_is_logged_in') && bms_is_logged_in()) {
        return $html;
    }
    $markup = bms_analytics_collector_markup();
    if ($markup === '' || $html === '' || stripos($html, '</body>') === false) {
        return $html;
    }
    return preg_replace('/<\/body>/i', $markup . "\n</body>", $html, 1) ?? $html;
}

function bms_handle_analytics_collector_request(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: application/json; charset=UTF-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !bms_is_installed() || !bms_analytics_enabled() || !bms_analytics_same_origin_request()) {
        http_response_code(204);
        exit;
    }

    try {
        $payload = bms_analytics_parse_collector_payload();
        bms_analytics_record_page_view($payload, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    } catch (Throwable $e) {
        // Analytics must never expose details or disturb a public page view.
        error_log('Bonumark Stream analytics collector error: ' . $e->getMessage());
    }

    http_response_code(204);
    exit;
}
