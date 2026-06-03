<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/markdown.php';

function bms_link_preview_first_url(string $text): string
{
    if (preg_match('~\bhttps?://[^\s<>()\[\]{}"\']+~iu', $text, $m) !== 1) {
        return '';
    }

    $url = (string)$m[0];
    while ($url !== '' && preg_match('/[\.,;:!?]+$/u', $url) === 1) {
        $url = substr($url, 0, -1);
    }

    return bms_link_preview_clean_url($url);
}

function bms_link_preview_clean_url(string $url): string
{
    $url = trim(htmlspecialchars_decode($url, ENT_QUOTES));
    $clean = bms_clean_url($url);
    if ($clean === '#' || preg_match('#^https?://#i', $clean) !== 1) {
        return '';
    }

    return $clean;
}

function bms_link_preview_trim_text(string $value, int $limit = 180): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value);
    if ($limit > 0) {
        if (function_exists('mb_substr') && function_exists('mb_strlen') && mb_strlen($value) > $limit) {
            $value = rtrim(mb_substr($value, 0, $limit - 1)) . '…';
        } elseif (strlen($value) > $limit) {
            $value = rtrim(substr($value, 0, $limit - 1)) . '…';
        }
    }
    return $value;
}

function bms_link_preview_ip_is_public(string $ip): bool
{
    $ip = trim($ip, " \t\n\r\0\x0B[]");
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** @return list<string> */
function bms_link_preview_public_host_ips(string $host): array
{
    $host = strtolower(trim($host, " \t\n\r\0\x0B."));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return [];
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    foreach (['ip', 'ipv6'] as $key) {
                        if (!empty($record[$key])) {
                            $ips[] = (string)$record[$key];
                        }
                    }
                }
            }
        }
        if (!$ips && function_exists('gethostbynamel')) {
            $aRecords = @gethostbynamel($host);
            if (is_array($aRecords)) {
                $ips = array_merge($ips, $aRecords);
            }
        }
    }

    $public = [];
    foreach (array_unique($ips) as $ip) {
        if (!bms_link_preview_ip_is_public((string)$ip)) {
            return [];
        }
        $public[] = (string)$ip;
    }
    return array_values(array_unique($public));
}

function bms_link_preview_host_is_public(string $host): bool
{
    return bms_link_preview_public_host_ips($host) !== [];
}

function bms_link_preview_url_is_fetchable(string $url): bool
{
    return bms_link_preview_fetch_target($url) !== null;
}

/** @return array{scheme:string,host:string,port:int,ips:list<string>}|null */
function bms_link_preview_fetch_target(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(trim((string)($parts['host'] ?? ''), " \t\n\r\0\x0B[]"));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return null;
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, [80, 443], true)) {
        return null;
    }
    $ips = bms_link_preview_public_host_ips($host);
    if ($ips === []) {
        return null;
    }
    return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips];
}

function bms_link_preview_fetch_html(string $url, int $limit = 262144): string
{
    $target = bms_link_preview_fetch_target($url);
    if ($target === null) {
        throw new RuntimeException('That URL cannot be previewed safely.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Safe link previews require the PHP cURL extension. Ask the host to enable cURL.');
    }

    $buffer = '';
    $host = $target['host'];
    $port = (int)$target['port'];
    $resolved = array_values(array_unique($target['ips']));
    $ch = curl_init($url);
    if (!$ch) {
        throw new RuntimeException('Could not start the preview request.');
    }

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'Bonumark Stream Link Preview',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'],
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, $limit): int {
            $remaining = $limit - strlen($buffer);
            if ($remaining <= 0) {
                return 0;
            }
            $buffer .= substr($chunk, 0, $remaining);
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS')) {
        $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS')) {
        $curlOptions[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if ($resolved !== [] && defined('CURLOPT_RESOLVE')) {
        $curlOptions[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $resolved[0]];
    }

    curl_setopt_array($ch, $curlOptions);
    curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $primaryIp = defined('CURLINFO_PRIMARY_IP') ? (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP) : '';
    curl_close($ch);

    if ($primaryIp !== '' && (!bms_link_preview_ip_is_public($primaryIp) || !in_array($primaryIp, $resolved, true))) {
        throw new RuntimeException('Preview request connected to an unsafe address.');
    }
    if ($error !== '' && $buffer === '') {
        throw new RuntimeException('Preview request failed.');
    }
    if ($status >= 400 && $buffer === '') {
        throw new RuntimeException('Preview request returned an error.');
    }
    if ($type !== '' && stripos($type, 'text/html') === false && stripos($type, 'application/xhtml+xml') === false && $buffer === '') {
        throw new RuntimeException('That URL does not look like a web page.');
    }
    return $buffer;
}

function bms_link_preview_meta_value(string $html, array $names): string
{
    foreach ($names as $name) {
        $quoted = preg_quote($name, '~');
        $patterns = [
            '~<meta\s+[^>]*(?:property|name)=["\']' . $quoted . '["\'][^>]*content=["\']([^"\']*)["\'][^>]*>~iu',
            '~<meta\s+[^>]*content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . $quoted . '["\'][^>]*>~iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                return bms_link_preview_trim_text((string)$m[1], 260);
            }
        }
    }
    return '';
}

function bms_link_preview_resolve_url(string $base, string $url): string
{
    $url = trim(htmlspecialchars_decode($url, ENT_QUOTES));
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url) === 1) {
        return bms_link_preview_clean_url($url);
    }
    if (str_starts_with($url, '//')) {
        $scheme = strtolower((string)(parse_url($base, PHP_URL_SCHEME) ?: 'https'));
        return bms_link_preview_clean_url($scheme . ':' . $url);
    }

    $baseParts = parse_url($base);
    if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return '';
    }
    $origin = strtolower((string)$baseParts['scheme']) . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . (int)$baseParts['port'] : '');
    if (str_starts_with($url, '/')) {
        return bms_link_preview_clean_url($origin . $url);
    }
    $path = (string)($baseParts['path'] ?? '/');
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return bms_link_preview_clean_url($origin . ($dir !== '' ? $dir : '') . '/' . $url);
}

function bms_link_preview_from_url(string $url): array
{
    $url = bms_link_preview_clean_url($url);
    if ($url === '') {
        throw new RuntimeException('Enter a valid http or https URL.');
    }

    $html = bms_link_preview_fetch_html($url);
    if ($html === '') {
        throw new RuntimeException('No preview metadata was found.');
    }

    $title = bms_link_preview_meta_value($html, ['og:title', 'twitter:title']);
    if ($title === '' && preg_match('~<title[^>]*>(.*?)</title>~isu', $html, $m) === 1) {
        $title = bms_link_preview_trim_text((string)$m[1], 120);
    }
    $description = bms_link_preview_meta_value($html, ['og:description', 'twitter:description', 'description']);
    $siteName = bms_link_preview_meta_value($html, ['og:site_name', 'application-name']);
    $image = bms_link_preview_meta_value($html, ['og:image:secure_url', 'og:image', 'twitter:image']);
    $image = $image !== '' ? bms_link_preview_resolve_url($url, $image) : '';

    if ($title === '') {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
        $title = $host;
    }
    if ($siteName === '') {
        $siteName = (string)(parse_url($url, PHP_URL_HOST) ?: '');
    }

    return bms_link_preview_sanitize_payload([
        'url' => $url,
        'title' => $title,
        'description' => $description,
        'image' => $image,
        'site_name' => $siteName,
    ]);
}

function bms_link_preview_sanitize_payload(array $payload): array
{
    $url = bms_link_preview_clean_url((string)($payload['url'] ?? ''));
    $image = bms_link_preview_clean_url((string)($payload['image'] ?? ''));

    return [
        'url' => $url,
        'title' => bms_link_preview_trim_text((string)($payload['title'] ?? ''), 120),
        'description' => bms_link_preview_trim_text((string)($payload['description'] ?? ''), 220),
        'image' => $image,
        'site_name' => bms_link_preview_trim_text((string)($payload['site_name'] ?? ''), 80),
    ];
}

function bms_link_preview_payload_from_request(): array
{
    $enabled = (string)($_POST['link_preview_enabled'] ?? '0') === '1';
    if (!$enabled) {
        return [];
    }
    $payload = bms_link_preview_sanitize_payload([
        'url' => (string)($_POST['link_preview_url'] ?? ''),
        'title' => (string)($_POST['link_preview_title'] ?? ''),
        'description' => (string)($_POST['link_preview_description'] ?? ''),
        'image' => (string)($_POST['link_preview_image'] ?? ''),
        'site_name' => (string)($_POST['link_preview_site_name'] ?? ''),
    ]);
    return $payload['url'] !== '' ? $payload : [];
}

function bms_link_preview_front_matter_fields(array $payload): array
{
    $payload = bms_link_preview_sanitize_payload($payload);
    if ($payload['url'] === '') {
        return [];
    }
    return [
        'link_preview_url' => $payload['url'],
        'link_preview_title' => $payload['title'],
        'link_preview_description' => $payload['description'],
        'link_preview_image' => $payload['image'],
        'link_preview_site_name' => $payload['site_name'],
    ];
}

function bms_link_preview_from_page(array $page): array
{
    $frontMatter = is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
    return bms_link_preview_sanitize_payload([
        'url' => (string)($page['link_preview_url'] ?? $frontMatter['link_preview_url'] ?? ''),
        'title' => (string)($page['link_preview_title'] ?? $frontMatter['link_preview_title'] ?? ''),
        'description' => (string)($page['link_preview_description'] ?? $frontMatter['link_preview_description'] ?? ''),
        'image' => (string)($page['link_preview_image'] ?? $frontMatter['link_preview_image'] ?? ''),
        'site_name' => (string)($page['link_preview_site_name'] ?? $frontMatter['link_preview_site_name'] ?? ''),
    ]);
}
