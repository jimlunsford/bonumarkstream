<?php

/**
 * Network and signature boundary for ActivityPub.
 *
 * No caller may fetch an untrusted federation URL without passing through the
 * validation and pinned-address transport in this file.
 */

final class BmsActivityPubSecurityException extends RuntimeException
{
    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        $status = $this->getCode();
        return $status >= 400 && $status <= 599 ? $status : 400;
    }
}

function bms_activitypub_inbox_max_bytes(): int
{
    return 262144;
}

function bms_activitypub_remote_document_max_bytes(): int
{
    return 1048576;
}

function bms_activitypub_json_max_depth(): int
{
    return 32;
}

function bms_activitypub_signature_window_seconds(): int
{
    return 300;
}

function bms_activitypub_ip_is_public(string $ip): bool
{
    $ip = trim($ip, "[] \t\n\r\0\x0B");
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
        $flags |= FILTER_FLAG_GLOBAL_RANGE;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
        return false;
    }

    // Explicitly cover ranges whose treatment has differed between PHP builds.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $number = ip2long($ip);
        if ($number === false) {
            return false;
        }
        $number = (int)sprintf('%u', $number);
        foreach ([
            ['100.64.0.0', 10],
            ['169.254.0.0', 16],
            ['192.0.0.0', 24],
            ['192.0.2.0', 24],
            ['198.18.0.0', 15],
            ['198.51.100.0', 24],
            ['203.0.113.0', 24],
            ['224.0.0.0', 4],
        ] as [$network, $bits]) {
            $networkNumber = (int)sprintf('%u', ip2long($network));
            $mask = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
            if (($number & $mask) === ($networkNumber & $mask)) {
                return false;
            }
        }
    }
    return true;
}

function bms_activitypub_resolve_public_host(string $host, ?callable $resolver = null): array
{
    $host = strtolower(rtrim(trim($host), '.'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        throw new BmsActivityPubSecurityException('The remote host is not public.', 400);
    }

    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
        $addresses = [trim($host, '[]')];
    } elseif ($resolver !== null) {
        $addresses = $resolver($host);
    } else {
        $addresses = [];
        $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A | DNS_AAAA) : false;
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = trim((string)($record['ip'] ?? $record['ipv6'] ?? ''));
                if ($address !== '') {
                    $addresses[] = $address;
                }
            }
        }
        if ($addresses === [] && function_exists('gethostbynamel')) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                $addresses = $fallback;
            }
        }
    }
    if (!is_array($addresses) || $addresses === []) {
        throw new BmsActivityPubSecurityException('The remote host could not be resolved.', 502);
    }
    $addresses = array_values(array_unique(array_map(static fn(mixed $ip): string => trim((string)$ip), $addresses)));
    foreach ($addresses as $address) {
        if (!bms_activitypub_ip_is_public($address)) {
            throw new BmsActivityPubSecurityException('The remote host resolved to a non-public address.', 400);
        }
    }
    sort($addresses, SORT_STRING);
    return $addresses;
}

function bms_activitypub_validate_remote_url(string $url, ?callable $resolver = null, bool $allowFragment = false): array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        throw new BmsActivityPubSecurityException('The remote URL is invalid.', 400);
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])
        || (!$allowFragment && isset($parts['fragment']))) {
        throw new BmsActivityPubSecurityException('Federation URLs must use public HTTPS without credentials.', 400);
    }
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    $port = isset($parts['port']) ? (int)$parts['port'] : 443;
    if ($port < 1 || $port > 65535) {
        throw new BmsActivityPubSecurityException('The remote URL port is invalid.', 400);
    }
    $addresses = bms_activitypub_resolve_public_host($host, $resolver);
    return [
        'url' => $url,
        'host' => $host,
        'port' => $port,
        'addresses' => $addresses,
        'path' => (string)($parts['path'] ?? '/'),
    ];
}

function bms_activitypub_resolve_redirect_url(string $fromUrl, string $location): string
{
    $location = trim($location);
    if ($location === '' || preg_match('/[\r\n]/', $location) === 1) {
        throw new BmsActivityPubSecurityException('The remote redirect is invalid.', 502);
    }
    if (preg_match('#^https://#i', $location) === 1) {
        return $location;
    }
    if (!str_starts_with($location, '/')) {
        throw new BmsActivityPubSecurityException('Only absolute HTTPS or root-relative redirects are accepted.', 502);
    }
    $parts = parse_url($fromUrl);
    if (!is_array($parts)) {
        throw new BmsActivityPubSecurityException('The redirect origin is invalid.', 502);
    }
    $authority = (string)$parts['host'];
    if (isset($parts['port']) && (int)$parts['port'] !== 443) {
        $authority .= ':' . (int)$parts['port'];
    }
    return 'https://' . $authority . $location;
}

function bms_activitypub_curl_transport(array $target, array $request): array
{
    if (!function_exists('curl_init')) {
        throw new BmsActivityPubSecurityException('Secure federation HTTP requires PHP cURL.', 503);
    }
    $method = strtoupper((string)($request['method'] ?? 'GET'));
    $body = (string)($request['body'] ?? '');
    $maxBytes = max(1024, (int)($request['max_bytes'] ?? bms_activitypub_remote_document_max_bytes()));
    $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
    $responseHeaders = [];
    $responseBody = '';
    $headerBytes = 0;
    $address = (string)$target['addresses'][0];
    $resolveAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;

    $curl = curl_init((string)$target['url']);
    if ($curl === false) {
        throw new BmsActivityPubSecurityException('The remote request could not be initialized.', 502);
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_values(array_map('strval', $headers)),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Bonumark-Stream-ActivityPub/0.7',
        CURLOPT_RESOLVE => [(string)$target['host'] . ':' . (int)$target['port'] . ':' . $resolveAddress],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders, &$headerBytes): int {
            $headerBytes += strlen($line);
            if ($headerBytes > 65536) {
                return 0;
            }
            $trimmed = trim($line);
            if ($trimmed !== '' && str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))][] = trim($value);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, $maxBytes): int {
            if (strlen($responseBody) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($method !== 'GET' && $body !== '') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $primaryIp = (string)curl_getinfo($curl, CURLINFO_PRIMARY_IP);
    $error = curl_error($curl);
    curl_close($curl);
    if ($ok === false) {
        throw new BmsActivityPubSecurityException('The remote request failed within the transport limits.' . ($error !== '' ? ' ' . $error : ''), 502);
    }
    if (!bms_activitypub_ip_is_public($primaryIp) || !in_array(trim($primaryIp, '[]'), array_map(static fn(string $ip): string => trim($ip, '[]'), $target['addresses']), true)) {
        throw new BmsActivityPubSecurityException('The connected address did not match the validated public destination.', 502);
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody, 'primary_ip' => $primaryIp];
}

function bms_activitypub_http_request(string $url, array $options = [], ?callable $transport = null, ?callable $resolver = null): array
{
    $transport ??= 'bms_activitypub_curl_transport';
    $method = strtoupper((string)($options['method'] ?? 'GET'));
    $maxRedirects = max(0, min(3, (int)($options['max_redirects'] ?? ($method === 'GET' ? 2 : 0))));
    $current = $url;
    for ($redirects = 0; ; $redirects++) {
        $target = bms_activitypub_validate_remote_url($current, $resolver, false);
        $response = $transport($target, $options + ['method' => $method]);
        if (!is_array($response)) {
            throw new BmsActivityPubSecurityException('The remote transport returned an invalid response.', 502);
        }
        $status = (int)($response['status'] ?? 0);
        if ($status < 300 || $status > 399) {
            $response['url'] = $current;
            return $response;
        }
        if ($redirects >= $maxRedirects) {
            throw new BmsActivityPubSecurityException('The remote server exceeded the redirect limit.', 502);
        }
        $locations = $response['headers']['location'] ?? [];
        $location = is_array($locations) ? (string)end($locations) : (string)$locations;
        $current = bms_activitypub_resolve_redirect_url($current, $location);
        // Validation occurs again before the next transport call.
    }
}

function bms_activitypub_decode_json_document(string $json, int $maxBytes): array
{
    if ($json === '' || strlen($json) > $maxBytes) {
        throw new BmsActivityPubSecurityException('The JSON document is empty or too large.', 413);
    }
    try {
        $decoded = json_decode($json, true, bms_activitypub_json_max_depth(), JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (JsonException $e) {
        throw new BmsActivityPubSecurityException('The JSON document is malformed or too deeply nested.', 400);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new BmsActivityPubSecurityException('The JSON document must be an object.', 400);
    }
    return $decoded;
}

function bms_activitypub_fetch_json(string $url, ?callable $transport = null, ?callable $resolver = null): array
{
    $response = bms_activitypub_http_request($url, [
        'method' => 'GET',
        'max_bytes' => bms_activitypub_remote_document_max_bytes(),
        'max_redirects' => 2,
        'headers' => [
            'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams", application/json;q=0.8',
        ],
    ], $transport, $resolver);
    if ((int)($response['status'] ?? 0) !== 200) {
        throw new BmsActivityPubSecurityException('The remote actor document was not available.', 502);
    }
    $contentTypes = $response['headers']['content-type'] ?? [];
    $contentType = strtolower(is_array($contentTypes) ? (string)end($contentTypes) : (string)$contentTypes);
    if ($contentType !== '' && !str_starts_with($contentType, 'application/activity+json')
        && !str_starts_with($contentType, 'application/ld+json') && !str_starts_with($contentType, 'application/json')) {
        throw new BmsActivityPubSecurityException('The remote actor response was not JSON.', 502);
    }
    return ['document' => bms_activitypub_decode_json_document((string)($response['body'] ?? ''), bms_activitypub_remote_document_max_bytes()), 'url' => (string)$response['url']];
}

function bms_activitypub_normalize_request_headers(array $headers): array
{
    $normalized = [];
    foreach ($headers as $name => $value) {
        $name = strtolower(trim((string)$name));
        if ($name === '' || preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            continue;
        }
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        $normalized[$name] = trim((string)$value);
    }
    return $normalized;
}

function bms_activitypub_parse_signature_header(string $header): array
{
    $header = trim($header);
    if (str_starts_with(strtolower($header), 'signature ')) {
        $header = trim(substr($header, 10));
    }
    if ($header === '' || strlen($header) > 8192) {
        throw new BmsActivityPubSecurityException('The HTTP signature is missing or too large.', 401);
    }
    $params = [];
    $parts = str_getcsv($header, ',', '"', '\\');
    foreach ($parts as $part) {
        if (preg_match('/^\s*([A-Za-z][A-Za-z0-9_-]*)\s*=\s*(?:"([^"]*)"|([^,\s]+))\s*$/', (string)$part, $match) !== 1) {
            throw new BmsActivityPubSecurityException('The HTTP signature parameters are malformed.', 401);
        }
        $name = strtolower((string)$match[1]);
        if (array_key_exists($name, $params)) {
            throw new BmsActivityPubSecurityException('The HTTP signature repeats a parameter.', 401);
        }
        $value = $match[2] !== '' ? (string)$match[2] : (string)($match[3] ?? '');
        $params[$name] = is_string($value) ? $value : '';
    }
    foreach (['keyid', 'signature'] as $required) {
        if (trim((string)($params[$required] ?? '')) === '') {
            throw new BmsActivityPubSecurityException('The HTTP signature is missing a required parameter.', 401);
        }
    }
    $params['algorithm'] = strtolower(trim((string)($params['algorithm'] ?? 'rsa-sha256')));
    if (!in_array($params['algorithm'], ['rsa-sha256', 'hs2019'], true)) {
        throw new BmsActivityPubSecurityException('The HTTP signature algorithm is unsupported.', 401);
    }
    $params['headers'] = strtolower(trim((string)($params['headers'] ?? 'date')));
    return $params;
}

function bms_activitypub_verify_body_digest(string $body, array $headers): string
{
    $headers = bms_activitypub_normalize_request_headers($headers);
    $expected = hash('sha256', $body, true);
    $digest = trim((string)($headers['digest'] ?? ''));
    if ($digest !== '') {
        if (preg_match('/(?:^|,)\s*SHA-256=([^,\s]+)\s*(?:,|$)/i', $digest, $matches) !== 1) {
            throw new BmsActivityPubSecurityException('The request Digest header is invalid.', 400);
        }
        $actual = base64_decode((string)$matches[1], true);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new BmsActivityPubSecurityException('The request body digest does not match.', 400);
        }
        return 'digest';
    }
    $contentDigest = trim((string)($headers['content-digest'] ?? ''));
    if (preg_match('/sha-256\s*=\s*:([A-Za-z0-9+\/=]+):/i', $contentDigest, $matches) === 1) {
        $actual = base64_decode((string)$matches[1], true);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new BmsActivityPubSecurityException('The request body digest does not match.', 400);
        }
        return 'content-digest';
    }
    throw new BmsActivityPubSecurityException('A SHA-256 request-body digest is required.', 400);
}

function bms_activitypub_http_date_timestamp(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date instanceof DateTimeImmutable || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
        || $date->format('D, d M Y H:i:s \G\M\T') !== $value) {
        return 0;
    }
    return $date->getTimestamp();
}

function bms_activitypub_signature_signing_string(array $params, array $headers, string $method, string $requestTarget): array
{
    $headers = bms_activitypub_normalize_request_headers($headers);
    $names = preg_split('/\s+/', trim((string)$params['headers'])) ?: [];
    $names = array_values(array_filter(array_map('strtolower', $names), static fn(string $name): bool => $name !== ''));
    if ($names === [] || count($names) > 20 || count($names) !== count(array_unique($names))) {
        throw new BmsActivityPubSecurityException('The signed-header list is invalid.', 401);
    }
    foreach (['(request-target)', 'host'] as $required) {
        if (!in_array($required, $names, true)) {
            throw new BmsActivityPubSecurityException('The HTTP signature does not cover the request target and host.', 401);
        }
    }
    if (!in_array('date', $names, true) && !in_array('(created)', $names, true)) {
        throw new BmsActivityPubSecurityException('The HTTP signature does not cover a creation time.', 401);
    }
    if (!in_array('digest', $names, true) && !in_array('content-digest', $names, true)) {
        throw new BmsActivityPubSecurityException('The HTTP signature does not cover the body digest.', 401);
    }
    $lines = [];
    foreach ($names as $name) {
        if ($name === '(request-target)') {
            $value = strtolower($method) . ' ' . $requestTarget;
        } elseif ($name === '(created)') {
            $value = trim((string)($params['created'] ?? ''));
            if ($value === '' || preg_match('/^[0-9]{1,12}$/', $value) !== 1) {
                throw new BmsActivityPubSecurityException('The signature creation time is invalid.', 401);
            }
        } elseif (str_starts_with($name, '(')) {
            throw new BmsActivityPubSecurityException('The HTTP signature uses an unsupported derived component.', 401);
        } else {
            $value = trim((string)($headers[$name] ?? ''));
            if ($value === '') {
                throw new BmsActivityPubSecurityException('A signed request header is missing.', 401);
            }
        }
        $lines[] = $name . ': ' . $value;
    }
    return ['string' => implode("\n", $lines), 'headers' => $names];
}

function bms_activitypub_verify_http_signature(array $request, string $publicKeyPem, int $now = 0): array
{
    $headers = bms_activitypub_normalize_request_headers(is_array($request['headers'] ?? null) ? $request['headers'] : []);
    $signatureHeader = (string)($headers['signature'] ?? '');
    if ($signatureHeader === '') {
        $authorization = (string)($headers['authorization'] ?? '');
        if (stripos($authorization, 'Signature ') === 0) {
            $signatureHeader = $authorization;
        }
    }
    $params = bms_activitypub_parse_signature_header($signatureHeader);
    $keyId = trim((string)$params['keyid']);
    bms_activitypub_validate_remote_url($keyId, $request['resolver'] ?? null, true);
    $body = (string)($request['body'] ?? '');
    $digestHeader = bms_activitypub_verify_body_digest($body, $headers);
    $built = bms_activitypub_signature_signing_string(
        $params,
        $headers,
        strtoupper((string)($request['method'] ?? 'POST')),
        (string)($request['request_target'] ?? '/activitypub/inbox')
    );
    if (!in_array($digestHeader, $built['headers'], true)) {
        throw new BmsActivityPubSecurityException('The verified digest header was not signed.', 401);
    }
    $now = $now > 0 ? $now : time();
    $dateTimestamp = bms_activitypub_http_date_timestamp((string)($headers['date'] ?? ''));
    $createdTimestamp = isset($params['created']) && preg_match('/^[0-9]{1,12}$/', (string)$params['created']) === 1
        ? (int)$params['created'] : 0;
    $signedTimestamp = in_array('(created)', $built['headers'], true) ? $createdTimestamp : $dateTimestamp;
    if (isset($headers['date']) && trim((string)$headers['date']) !== '' && $dateTimestamp < 1) {
        throw new BmsActivityPubSecurityException('The request Date is invalid.', 401);
    }
    if ($signedTimestamp < 1 || abs($now - $signedTimestamp) > bms_activitypub_signature_window_seconds()) {
        throw new BmsActivityPubSecurityException('The HTTP signature is outside the accepted replay window.', 401);
    }
    if ($dateTimestamp > 0 && abs($now - $dateTimestamp) > bms_activitypub_signature_window_seconds()) {
        throw new BmsActivityPubSecurityException('The request Date is outside the accepted replay window.', 401);
    }
    $signature = base64_decode((string)$params['signature'], true);
    if (!is_string($signature) || $signature === '') {
        throw new BmsActivityPubSecurityException('The HTTP signature value is invalid.', 401);
    }
    $publicKey = openssl_pkey_get_public($publicKeyPem);
    $details = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
    if ($publicKey === false || !is_array($details) || (int)($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA || (int)($details['bits'] ?? 0) < 2048) {
        throw new BmsActivityPubSecurityException('The remote signing key is not an acceptable RSA key.', 401);
    }
    $verified = openssl_verify((string)$built['string'], $signature, $publicKey, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        throw new BmsActivityPubSecurityException('The HTTP signature could not be verified.', 401);
    }
    return [
        'key_id' => $keyId,
        'signature_date' => gmdate('Y-m-d H:i:s', $signedTimestamp),
        'fingerprint' => hash('sha256', $keyId . "\n" . (string)$params['signature'] . "\n" . $signedTimestamp . "\n" . hash('sha256', $body)),
        'signed_headers' => $built['headers'],
    ];
}

function bms_activitypub_sign_outbound_request(string $method, string $url, string $body, array $key): array
{
    $parts = parse_url($url);
    if (!is_array($parts) || trim((string)($parts['host'] ?? '')) === '') {
        throw new RuntimeException('The delivery URL is invalid.');
    }
    $host = strtolower((string)$parts['host']);
    if (isset($parts['port']) && (int)$parts['port'] !== 443) {
        $host .= ':' . (int)$parts['port'];
    }
    $target = (string)($parts['path'] ?? '/');
    if (isset($parts['query'])) {
        $target .= '?' . (string)$parts['query'];
    }
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $headersList = '(request-target) host date digest';
    $signing = '(request-target): ' . strtolower($method) . ' ' . $target
        . "\nhost: " . $host . "\ndate: " . $date . "\ndigest: " . $digest;
    $signature = '';
    if (!openssl_sign($signing, $signature, (string)($key['private_key_pem'] ?? ''), OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The federation response could not be signed.');
    }
    return [
        'Host: ' . $host,
        'Date: ' . $date,
        'Digest: ' . $digest,
        'Content-Type: application/activity+json',
        'Accept: application/activity+json, application/ld+json',
        'Signature: keyId="' . bms_activitypub_actor_url() . '#main-key",algorithm="rsa-sha256",headers="' . $headersList . '",signature="' . base64_encode($signature) . '"',
    ];
}
