<?php
require_once __DIR__ . '/media.php';

function mp_import_remote_image_max_bytes(): int
{
    return 8 * 1024 * 1024;
}

/** @return list<string> */
function mp_import_extract_remote_image_urls(string $body): array
{
    $urls = [];
    $matched = preg_match_all('/!\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $body, $matches);
    if ($matched === false || $matched === 0) {
        return [];
    }
    foreach ($matches[1] as $url) {
        $url = trim((string)$url, " \t\n\r\0\x0B\"'");
        if (mp_import_is_remote_http_url($url)) {
            $urls[] = $url;
        }
    }
    return array_values(array_unique($urls));
}

function mp_import_is_remote_http_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = trim((string)($parts['host'] ?? ''));
    return in_array($scheme, ['http', 'https'], true) && $host !== '';
}

function mp_import_remote_image_ip_is_public(string $ip): bool
{
    $ip = trim($ip, " \t\n\r\0\x0B[]");
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** @return list<string> */
function mp_import_remote_image_public_host_ips(string $host): array
{
    $host = strtolower(trim($host, " \t\n\r\0\x0B.[]"));
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
        if (!mp_import_remote_image_ip_is_public((string)$ip)) {
            return [];
        }
        $public[] = (string)$ip;
    }
    return array_values(array_unique($public));
}

function mp_import_remote_image_host_is_public(string $host): bool
{
    return mp_import_remote_image_public_host_ips($host) !== [];
}

/** @return array{scheme:string,host:string,port:int,ips:list<string>}|null */
function mp_import_remote_image_fetch_target(string $url): ?array
{
    if (!mp_import_is_remote_http_url($url)) {
        return null;
    }

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

    $ips = mp_import_remote_image_public_host_ips($host);
    if ($ips === []) {
        return null;
    }

    return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips];
}

function mp_import_remote_image_url_is_safe(string $url): bool
{
    return mp_import_remote_image_fetch_target($url) !== null;
}

/** Resolve a remote Location header against the current URL. */
function mp_import_absolute_redirect_url(string $location, string $baseUrl): string
{
    $location = trim($location);
    if ($location === '') {
        return '';
    }

    if (mp_import_is_remote_http_url($location)) {
        return $location;
    }

    if (str_starts_with($location, '//')) {
        $scheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https'));
        return $scheme . ':' . $location;
    }

    $base = parse_url($baseUrl);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
        return '';
    }

    $scheme = strtolower((string)$base['scheme']);
    $host = (string)$base['host'];
    $port = isset($base['port']) ? ':' . (int)$base['port'] : '';

    if (str_starts_with($location, '/')) {
        return $scheme . '://' . $host . $port . $location;
    }

    $path = (string)($base['path'] ?? '/');
    $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
    if ($directory === '' || $directory === '.') {
        $directory = '';
    }

    return $scheme . '://' . $host . $port . $directory . '/' . $location;
}

/** @return array{status:int,mime:string,location:string,data:string} */
function mp_import_fetch_remote_image_once(string $url, int $maxBytes): array
{
    $target = mp_import_remote_image_fetch_target($url);
    if ($target === null) {
        throw new RuntimeException('Remote image URL was rejected for safety.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Safe remote media imports require the PHP cURL extension. Ask the host to enable cURL.');
    }

    $buffer = '';
    $headers = [];
    $tooLarge = false;
    $host = $target['host'];
    $port = (int)$target['port'];
    $resolved = array_values(array_unique($target['ips']));
    $ch = curl_init($url);
    if (!$ch) {
        throw new RuntimeException('Could not initialize remote image download.');
    }

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Bonumark Stream Importer',
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$headers): int {
            $headers[] = trim($header);
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$tooLarge, $maxBytes): int {
            $buffer .= $chunk;
            if (strlen($buffer) > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
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
    $mime = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
    $primaryIp = defined('CURLINFO_PRIMARY_IP') ? (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP) : '';
    curl_close($ch);

    if ($primaryIp !== '' && (!mp_import_remote_image_ip_is_public($primaryIp) || !in_array($primaryIp, $resolved, true))) {
        throw new RuntimeException('Remote image request connected to an unsafe address.');
    }
    if ($tooLarge) {
        throw new RuntimeException('Remote image exceeds the import size limit.');
    }
    if ($error !== '') {
        $safeError = trim(preg_replace('/\s+/', ' ', $error) ?? $error);
        if (function_exists('mb_substr')) {
            $safeError = mb_substr($safeError, 0, 160);
        } else {
            $safeError = substr($safeError, 0, 160);
        }
        throw new RuntimeException($safeError !== '' ? 'Remote image download failed: ' . $safeError . '.' : 'Remote image download failed.');
    }

    $location = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        } elseif ($mime === '' && stripos($header, 'Content-Type:') === 0) {
            $mime = strtolower(trim(substr($header, 13)));
        }
    }

    return ['status' => $status, 'mime' => $mime, 'location' => $location, 'data' => $buffer];
}

function mp_import_download_remote_image(string $url): array
{
    if (!mp_import_remote_image_url_is_safe($url)) {
        throw new RuntimeException('Remote image URL was rejected for safety.');
    }

    $maxBytes = mp_import_remote_image_max_bytes();
    $mime = '';
    $finalUrl = $url;
    $data = '';
    $redirects = 0;

    while (true) {
        if (!mp_import_remote_image_url_is_safe($finalUrl)) {
            throw new RuntimeException('Remote image URL was rejected for safety.');
        }

        $response = mp_import_fetch_remote_image_once($finalUrl, $maxBytes);
        $status = (int)$response['status'];
        $mime = strtolower(trim((string)$response['mime']));

        if ($status >= 300 && $status < 400) {
            $redirects++;
            if ($redirects > 3) {
                throw new RuntimeException('Remote image redirected too many times.');
            }
            $nextUrl = mp_import_absolute_redirect_url((string)$response['location'], $finalUrl);
            if ($nextUrl === '' || !mp_import_remote_image_url_is_safe($nextUrl)) {
                throw new RuntimeException('Remote image redirect target was rejected for safety.');
            }
            $finalUrl = $nextUrl;
            continue;
        }

        if ($status > 0 && ($status < 200 || $status >= 300)) {
            throw new RuntimeException('Remote image returned HTTP ' . $status . '.');
        }

        $data = (string)$response['data'];
        break;
    }

    if ($data === '') {
        throw new RuntimeException('Remote image was empty.');
    }

    if (str_contains($mime, ';')) {
        $mime = trim(strstr($mime, ';', true) ?: $mime);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'bms-import-media-');
    if ($tmp === false) {
        throw new RuntimeException('Could not create a temporary file for imported media.');
    }
    file_put_contents($tmp, $data);

    if ($mime === '' && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = strtolower((string)finfo_file($finfo, $tmp));
            finfo_close($finfo);
        }
    }

    $extension = mp_import_image_extension_from_url_or_mime($finalUrl, $mime);
    if ($extension === '') {
        @unlink($tmp);
        throw new RuntimeException('Remote file is not a supported image type.');
    }

    return [
        'path' => $tmp,
        'mime' => $mime !== '' ? $mime : mp_media_expected_mime_for_extension($extension),
        'size' => filesize($tmp) ?: strlen($data),
        'filename' => mp_import_remote_image_filename($finalUrl, $extension),
    ];
}

function mp_import_image_extension_from_url_or_mime(string $url, string $mime): string
{
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (in_array($extension, $allowed, true)) {
        return $extension;
    }
    return match (strtolower(trim($mime))) {
        'image/jpeg', 'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => '',
    };
}

function mp_import_remote_image_filename(string $url, string $extension): string
{
    $query = (string)(parse_url($url, PHP_URL_QUERY) ?: '');
    if ($query !== '') {
        parse_str($query, $params);
        $cid = isset($params['cid']) ? preg_replace('/[^a-z0-9._-]/i', '-', (string)$params['cid']) : '';
        if (is_string($cid) && trim($cid, '-.') !== '') {
            return 'bluesky-' . trim($cid, '-.') . '.' . $extension;
        }
    }

    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
    $name = basename($path);
    $name = $name !== '' ? urldecode($name) : 'imported-image.' . $extension;
    $currentExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($currentExtension === '') {
        $name .= '.' . $extension;
    }
    return $name;
}


function mp_import_staging_root(string $token = ''): string
{
    $root = mp_root_path('import-staging');
    if ($token !== '') {
        $token = preg_replace('/[^a-f0-9]/i', '', $token) ?? '';
        return $root . ($token !== '' ? '/' . $token : '');
    }
    return $root;
}

function mp_import_staging_token(): string
{
    return bin2hex(random_bytes(12));
}

function mp_import_staged_media_url(string $token, string $relativePath): string
{
    $token = preg_replace('/[^a-f0-9]/i', '', $token) ?? '';
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    $relativePath = preg_replace('#/+#', '/', $relativePath) ?? $relativePath;
    $relativePath = str_replace(['../', '..\\'], '', $relativePath);
    return 'bms-import-media://' . $token . '/' . rawurlencode($relativePath);
}

function mp_import_staging_path(string $token, string $relativePath): string
{
    $token = preg_replace('/[^a-f0-9]/i', '', $token) ?? '';
    $relativePath = rawurldecode(trim(str_replace('\\', '/', $relativePath), '/'));
    $relativePath = preg_replace('#/+#', '/', $relativePath) ?? $relativePath;
    $relativePath = str_replace(['../', '..\\'], '', $relativePath);
    return mp_import_staging_root($token) . '/' . basename($relativePath);
}

function mp_import_is_staged_media_url(string $url): bool
{
    return preg_match('#^bms-import-media://[a-f0-9]{24}/[^\s)]+$#i', trim($url)) === 1;
}

/** @return array{token:string,relative:string,path:string} */
function mp_import_parse_staged_media_url(string $url): array
{
    $url = trim($url);
    if (preg_match('#^bms-import-media://([a-f0-9]{24})/([^\s)]+)$#i', $url, $match) !== 1) {
        return ['token' => '', 'relative' => '', 'path' => ''];
    }
    $token = strtolower((string)$match[1]);
    $relative = rawurldecode((string)$match[2]);
    return ['token' => $token, 'relative' => basename($relative), 'path' => mp_import_staging_path($token, $relative)];
}

/** @return list<string> */
function mp_import_extract_staged_media_urls(string $body): array
{
    $urls = [];
    $matched = preg_match_all('/!?\[[^\]]*\]\((bms-import-media:\/\/[^)\r\n]+)(?:\s+"[^"]*")?\)/', $body, $matches);
    if ($matched === false || $matched === 0) {
        return [];
    }
    foreach ($matches[1] as $url) {
        $url = trim((string)$url);
        if (mp_import_is_staged_media_url($url)) {
            $urls[] = $url;
        }
    }
    return array_values(array_unique($urls));
}

/** @return list<string> */
function mp_import_extract_staging_tokens_from_preview(?array $preview): array
{
    if (!is_array($preview)) {
        return [];
    }
    $tokens = [];
    $items = is_array($preview['items'] ?? null) ? $preview['items'] : [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $candidateUrls = mp_import_extract_staged_media_urls((string)($item['body'] ?? ''));
        $featuredMedia = trim((string)($item['featured_media'] ?? ''));
        if ($featuredMedia !== '' && mp_import_is_staged_media_url($featuredMedia)) {
            $candidateUrls[] = $featuredMedia;
        }
        foreach ($candidateUrls as $url) {
            $parsed = mp_import_parse_staged_media_url($url);
            if ($parsed['token'] !== '') {
                $tokens[] = $parsed['token'];
            }
        }
    }
    return array_values(array_unique($tokens));
}

function mp_import_cleanup_staging_token(string $token): void
{
    $dir = mp_import_staging_root($token);
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function mp_import_staged_file_to_media(string $url, string $alt): string
{
    $parsed = mp_import_parse_staged_media_url($url);
    $path = $parsed['path'];
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Staged archive media file was not found. Recreate the import preview and try again.');
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowed = mp_allowed_media_extensions();
    if (!isset($allowed[$extension])) {
        throw new RuntimeException('Staged archive media was not a supported media type.');
    }

    $mime = mp_media_expected_mime_for_extension($extension);
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = strtolower((string)finfo_file($finfo, $path));
            finfo_close($finfo);
            if ($detected !== '') {
                $mime = $detected;
            }
        }
    }

    $media = mp_media_upload([
        'name' => basename($path),
        'type' => $mime,
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path) ?: 0,
    ], $alt !== '' ? $alt : 'Imported media', 'Imported from archive', ['generate_derivatives' => false]);

    $localUrl = mp_media_public_url_for_item($media);
    if ($localUrl === '') {
        throw new RuntimeException('Imported archive media did not return a public URL.');
    }
    return $localUrl;
}

/** @param array<string,string> $cache @return array{body:string,imported:int,removed:int,failed:int,warnings:list<string>} */
function mp_import_local_url_to_media_path(string $url): string
{
    $url = trim(str_replace('\\', '/', $url));
    if ($url === '') {
        return '';
    }
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
    $path = rawurldecode(str_replace('\\', '/', $path));
    $basePath = function_exists('mp_base_path') ? mp_base_path() : '';
    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath));
    }
    $path = ltrim($path, '/');
    if (str_starts_with($path, 'media/')) {
        return $path;
    }
    $position = strpos($path, '/media/');
    if ($position !== false) {
        return substr($path, $position + 1);
    }
    return '';
}

/** @param array<string,string> $cache @return array{body:string,imported:int,removed:int,failed:int,warnings:list<string>} */
function mp_import_apply_media_policy_to_body(string $body, string $mediaPolicy, array &$cache): array
{
    $mediaPolicy = in_array($mediaPolicy, ['remote', 'import', 'skip'], true) ? $mediaPolicy : 'remote';
    $summary = ['body' => $body, 'imported' => 0, 'removed' => 0, 'failed' => 0, 'warnings' => []];

    $summary['body'] = preg_replace_callback('/(!?)\[([^\]]*)\]\(([^)\r\n]+?)(?:\s+"[^"]*")?\)/', function (array $matches) use ($mediaPolicy, &$cache, &$summary): string {
        $prefix = (string)($matches[1] ?? '');
        $alt = trim((string)($matches[2] ?? ''));
        $url = trim((string)($matches[3] ?? ''));
        $isImageMarkup = $prefix === '!';
        $isRemote = $isImageMarkup && mp_import_is_remote_http_url($url);
        $isStaged = mp_import_is_staged_media_url($url);

        if (!$isRemote && !$isStaged) {
            return (string)$matches[0];
        }

        if ($mediaPolicy === 'skip') {
            $summary['removed']++;
            return '';
        }

        if ($isRemote && $mediaPolicy === 'remote') {
            return (string)$matches[0];
        }

        try {
            if (!isset($cache[$url])) {
                if ($isStaged) {
                    $localUrl = mp_import_staged_file_to_media($url, $alt);
                } else {
                    $download = mp_import_download_remote_image($url);
                    try {
                        $media = mp_media_upload([
                            'name' => $download['filename'],
                            'type' => $download['mime'],
                            'tmp_name' => $download['path'],
                            'error' => UPLOAD_ERR_OK,
                            'size' => $download['size'],
                        ], $alt !== '' ? $alt : 'Imported image', 'Imported from remote import', ['generate_derivatives' => false]);
                    } finally {
                        if (is_file($download['path'])) {
                            @unlink($download['path']);
                        }
                    }
                    $localUrl = mp_media_public_url_for_item($media);
                    if ($localUrl === '') {
                        throw new RuntimeException('Imported media did not return a public URL.');
                    }
                }
                $cache[$url] = $localUrl;
                $summary['imported']++;
            }
            $safeLabel = str_replace(["\r", "\n", '[', ']'], [' ', ' ', '', ''], $alt !== '' ? $alt : 'Imported media');
            if ($isImageMarkup) {
                return '![' . $safeLabel . '](' . $cache[$url] . ')';
            }
            return '[' . $safeLabel . '](' . $cache[$url] . ')';
        } catch (Throwable $e) {
            $summary['failed']++;
            $summary['warnings'][] = 'Could not import media ' . $url . ': ' . $e->getMessage();
            return $isStaged ? '' : (string)$matches[0];
        }
    }, $body) ?? $body;

    $summary['body'] = trim(preg_replace('/\n{3,}/', "\n\n", $summary['body']) ?? $summary['body']);
    return $summary;
}


