<?php

if (!defined('BMS_STATELESS_PROTOCOL_REQUEST')) {
    define('BMS_STATELESS_PROTOCOL_REQUEST', true);
}

require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/activitypub-serialization.php';
require_once __DIR__ . '/activitypub-inbox.php';

function bms_activitypub_route_names(): array
{
    return [
        'activitypub_webfinger',
        'activitypub_actor',
        'activitypub_inbox',
        'activitypub_outbox',
        'activitypub_followers',
        'activitypub_following',
        'activitypub_object',
        'activitypub_create_activity',
        'activitypub_event_activity',
        'activitypub_owner_activity',
    ];
}

function bms_activitypub_parse_accept(string $accept): array
{
    $accept = trim($accept);
    if ($accept === '') {
        return [['range' => '*/*', 'params' => [], 'q' => 1.0, 'order' => 0]];
    }

    $entries = [];
    foreach (explode(',', $accept) as $order => $rawEntry) {
        $parts = array_map('trim', explode(';', trim($rawEntry)));
        $range = strtolower((string)array_shift($parts));
        if (preg_match('~^[a-z0-9!#$&^_.+*-]+/[a-z0-9!#$&^_.+*-]+$~', $range) !== 1
            && $range !== '*/*') {
            continue;
        }
        $params = [];
        $quality = 1.0;
        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $part, 2));
            $name = strtolower($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($name === 'q') {
                $quality = is_numeric($value) ? max(0.0, min(1.0, (float)$value)) : 0.0;
            } else {
                $params[$name] = $value;
            }
        }
        $entries[] = ['range' => $range, 'params' => $params, 'q' => $quality, 'order' => (int)$order];
    }
    return $entries;
}

function bms_activitypub_accept_quality(array $entries, string $mediaType, string $profile = ''): float
{
    [$type] = explode('/', strtolower($mediaType), 2);
    $bestSpecificity = -1;
    $bestQuality = 0.0;
    foreach ($entries as $entry) {
        $range = strtolower((string)($entry['range'] ?? ''));
        $specificity = -1;
        if ($range === strtolower($mediaType)) {
            $specificity = 2;
        } elseif ($range === $type . '/*') {
            $specificity = 1;
        } elseif ($range === '*/*') {
            $specificity = 0;
        }
        if ($specificity < 0) {
            continue;
        }
        $requestedProfile = (string)($entry['params']['profile'] ?? '');
        if ($specificity === 2 && $requestedProfile !== '' && !hash_equals($requestedProfile, $profile)) {
            continue;
        }
        $quality = (float)($entry['q'] ?? 0.0);
        if ($specificity > $bestSpecificity || ($specificity === $bestSpecificity && $quality > $bestQuality)) {
            $bestSpecificity = $specificity;
            $bestQuality = $quality;
        }
    }
    return $bestQuality;
}

function bms_activitypub_negotiate_content_type(string $accept, bool $webfinger = false): ?string
{
    $entries = bms_activitypub_parse_accept($accept);
    $representations = $webfinger
        ? [
            ['media_type' => 'application/jrd+json', 'profile' => '', 'content_type' => 'application/jrd+json; charset=UTF-8'],
            ['media_type' => 'application/json', 'profile' => '', 'content_type' => 'application/json; charset=UTF-8'],
        ]
        : [
            ['media_type' => 'application/activity+json', 'profile' => '', 'content_type' => 'application/activity+json; charset=UTF-8'],
            ['media_type' => 'application/ld+json', 'profile' => 'https://www.w3.org/ns/activitystreams', 'content_type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"; charset=UTF-8'],
            ['media_type' => 'application/json', 'profile' => '', 'content_type' => 'application/json; charset=UTF-8'],
        ];

    $selected = null;
    $selectedQuality = 0.0;
    foreach ($representations as $representation) {
        $quality = bms_activitypub_accept_quality(
            $entries,
            (string)$representation['media_type'],
            (string)$representation['profile']
        );
        if ($quality > $selectedQuality) {
            $selected = (string)$representation['content_type'];
            $selectedQuality = $quality;
        }
    }
    return $selectedQuality > 0.0 ? $selected : null;
}

function bms_activitypub_webfinger_resource_matches(string $resource, array $owner, ?string $baseUrl = null): bool
{
    $resource = rawurldecode(trim($resource));
    if ($resource === bms_activitypub_actor_url($baseUrl)) {
        return true;
    }
    if (stripos($resource, 'acct:') !== 0) {
        return false;
    }
    $account = substr($resource, 5);
    $separator = strrpos($account, '@');
    if ($separator === false) {
        return false;
    }
    $username = substr($account, 0, $separator);
    $host = strtolower(rtrim(substr($account, $separator + 1), '.'));
    return hash_equals(
        bms_text_lower(bms_normalize_username((string)($owner['username'] ?? ''))),
        bms_text_lower($username)
    ) && hash_equals(bms_activitypub_account_host($baseUrl), $host);
}

function bms_activitypub_emit_json(array $payload, int $status, string $contentType, array $extraHeaders = []): void
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        $status = 500;
        $json = '{"error":"The protocol response could not be encoded."}';
        $contentType = 'application/json; charset=UTF-8';
    }
    if (!headers_sent()) {
        header_remove('Set-Cookie');
        header_remove('Expires');
        header_remove('Pragma');
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: ' . ($status >= 400 ? 'no-store, private' : 'public, max-age=60, must-revalidate'));
        header('Vary: Accept');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        foreach ($extraHeaders as $header) {
            header((string)$header);
        }
    }
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo $json;
    }
    exit;
}

function bms_activitypub_emit_error(int $status, string $message, array $headers = []): void
{
    bms_activitypub_emit_json(['error' => $message], $status, 'application/json; charset=UTF-8', $headers);
}

function bms_dispatch_activitypub_route(string $route): bool
{
    $route = strtolower(trim($route));
    if (!in_array($route, bms_activitypub_route_names(), true)) {
        return false;
    }

    $retirement = bms_activitypub_actor_retirement();
    if (is_array($retirement)) {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            bms_activitypub_emit_error(410, 'The ActivityPub actor is permanently retired.');
        }
        $isWebfinger = $route === 'activitypub_webfinger';
        $contentType = bms_activitypub_negotiate_content_type((string)($_SERVER['HTTP_ACCEPT'] ?? ''), $isWebfinger);
        if ($contentType === null) {
            bms_activitypub_emit_error(406, 'No acceptable protocol representation is available.');
        }
        $baseUrl = trim((string)bms_setting_or_config('base_url', ''));
        if ($route === 'activitypub_actor') {
            bms_activitypub_emit_json(bms_activitypub_actor_tombstone_document($retirement, $baseUrl), 410, $contentType);
        }
        if ($route === 'activitypub_owner_activity') {
            $kind = is_scalar($_GET['owner_kind'] ?? null) ? (string)$_GET['owner_kind'] : '';
            $token = is_scalar($_GET['owner_token'] ?? null) ? (string)$_GET['owner_token'] : '';
            $activityUri = bms_activitypub_absolute_url('/activitypub/activities/owner/' . $kind . '/' . $token, $baseUrl);
            if (hash_equals((string)$retirement['delete_activity_uri'], $activityUri)) {
                $document = json_decode((string)$retirement['delete_payload_json'], true, bms_activitypub_json_max_depth());
                if (is_array($document) && !array_is_list($document)) {
                    bms_activitypub_emit_json($document, 200, $contentType);
                }
            }
        }
        if (in_array($route, ['activitypub_webfinger', 'activitypub_inbox', 'activitypub_outbox', 'activitypub_followers', 'activitypub_following'], true)) {
            bms_activitypub_emit_error(410, $isWebfinger ? 'The requested ActivityPub identity is permanently retired.' : 'The ActivityPub actor is permanently retired.', $isWebfinger ? ['Access-Control-Allow-Origin: *'] : []);
        }
    }

    if (!bms_activitypub_enabled() && !is_array($retirement)) {
        bms_activitypub_emit_error(404, 'Not found.');
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($route === 'activitypub_inbox') {
        if ($method !== 'POST') {
            bms_activitypub_emit_error(405, 'Method not allowed.', ['Allow: POST', 'Cache-Control: no-store, private']);
        }
        try {
            $baseUrl = trim((string)bms_setting_or_config('base_url', ''));
            if (empty(bms_activitypub_configured_base_url($baseUrl)['ok']) || !bms_is_installed()) {
                bms_activitypub_emit_error(503, 'ActivityPub identity is not ready.', ['Cache-Control: no-store, private']);
            }
            $headers = bms_activitypub_inbox_request_headers();
            $contentLength = isset($headers['content-length']) && preg_match('/^[0-9]+$/', (string)$headers['content-length']) === 1
                ? (int)$headers['content-length'] : null;
            $body = bms_activitypub_inbox_read_body(null, $contentLength);
            $result = bms_activitypub_receive_inbox([
                'method' => 'POST',
                'request_target' => (string)($_SERVER['REQUEST_URI'] ?? '/activitypub/inbox'),
                'headers' => $headers,
                'body' => $body,
            ]);
            bms_activitypub_emit_json(
                ['accepted' => true, 'status' => (string)($result['status'] ?? 'processed')],
                202,
                'application/activity+json; charset=UTF-8',
                ['Cache-Control: no-store, private']
            );
        } catch (BmsActivityPubSecurityException $e) {
            error_log('Bonumark Stream ActivityPub inbox rejected a request: ' . $e->getMessage());
            bms_activitypub_emit_error($e->httpStatus(), 'The inbox request was rejected.', ['Cache-Control: no-store, private']);
        } catch (Throwable $e) {
            error_log('Bonumark Stream ActivityPub inbox failed: ' . $e->getMessage());
            bms_activitypub_emit_error(503, 'The inbox is temporarily unavailable.', ['Cache-Control: no-store, private']);
        }
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        bms_activitypub_emit_error(405, 'Method not allowed.', ['Allow: GET, HEAD']);
    }

    $isWebfinger = $route === 'activitypub_webfinger';
    $contentType = bms_activitypub_negotiate_content_type((string)($_SERVER['HTTP_ACCEPT'] ?? ''), $isWebfinger);
    if ($contentType === null) {
        bms_activitypub_emit_error(406, 'No acceptable protocol representation is available.');
    }

    $baseUrl = trim((string)bms_setting_or_config('base_url', ''));
    $baseUrlCheck = bms_activitypub_configured_base_url($baseUrl);
    $routingCheck = bms_activitypub_webfinger_routing_capability($baseUrl, (string)bms_setting_or_config('base_path', ''));
    if (empty($baseUrlCheck['ok']) || empty($routingCheck['ok']) || !bms_is_installed()) {
        bms_activitypub_emit_error(503, 'ActivityPub identity is not ready.');
    }

    try {
        $owner = bms_activitypub_public_owner_user();
        if (!is_array($owner)) {
            bms_activitypub_emit_error(404, 'No public owner identity is available.');
        }

        if ($isWebfinger) {
            $resource = $_GET['resource'] ?? '';
            if (!is_string($resource) || trim($resource) === '') {
                bms_activitypub_emit_error(400, 'A WebFinger resource is required.', ['Access-Control-Allow-Origin: *']);
            }
            if (!bms_activitypub_webfinger_resource_matches($resource, $owner, $baseUrl)) {
                bms_activitypub_emit_error(404, 'The requested WebFinger resource was not found.', ['Access-Control-Allow-Origin: *']);
            }
            bms_activitypub_emit_json(
                bms_activitypub_webfinger_document($owner, $baseUrl),
                200,
                $contentType,
                ['Access-Control-Allow-Origin: *']
            );
        }

        if ($route === 'activitypub_actor') {
            $identity = bms_profile_identity_for_user((int)($owner['id'] ?? 0), $owner);
            $key = bms_activitypub_active_signing_key(false);
            bms_activitypub_emit_json(bms_activitypub_actor_document($owner, $identity, $key, $baseUrl), 200, $contentType);
        }

        if ($route === 'activitypub_followers') {
            bms_activitypub_emit_json(bms_activitypub_relationship_collection_document(bms_activitypub_followers_url($baseUrl), bms_activitypub_collection_actor_uris('followers')), 200, $contentType);
        }

        if ($route === 'activitypub_following') {
            bms_activitypub_emit_json(bms_activitypub_relationship_collection_document(bms_activitypub_following_url($baseUrl), bms_activitypub_collection_actor_uris('following')), 200, $contentType);
        }

        if ($route === 'activitypub_outbox') {
            $total = bms_activitypub_published_stream_count();
            $pageRaw = $_GET['page'] ?? null;
            if ($pageRaw === null || $pageRaw === '') {
                bms_activitypub_emit_json(bms_activitypub_outbox_document([], $total, null, 20, $baseUrl), 200, $contentType);
            }
            if (!is_scalar($pageRaw) || preg_match('/^[1-9][0-9]*$/', (string)$pageRaw) !== 1) {
                bms_activitypub_emit_error(400, 'The outbox page must be a positive integer.');
            }
            $page = min(1000000, (int)$pageRaw);
            $posts = bms_activitypub_published_stream_posts($page, 20);
            bms_activitypub_emit_json(bms_activitypub_outbox_document($posts, $total, $page, 20, $baseUrl), 200, $contentType);
        }

        if ($route === 'activitypub_event_activity') {
            $eventId = $_GET['event_id'] ?? '';
            if (!is_scalar($eventId) || preg_match('/^[1-9][0-9]*$/', (string)$eventId) !== 1) {
                bms_activitypub_emit_error(404, 'The requested activity was not found.');
            }
            $event = bms_activitypub_publication_event((int)$eventId);
            $document = is_array($event) ? json_decode((string)$event['payload_json'], true, bms_activitypub_json_max_depth()) : null;
            if (!is_array($document) || array_is_list($document)) {
                bms_activitypub_emit_error(404, 'The requested activity was not found.');
            }
            bms_activitypub_emit_json($document, 200, $contentType);
        }

        if ($route === 'activitypub_owner_activity') {
            $kind = is_scalar($_GET['owner_kind'] ?? null) ? (string)$_GET['owner_kind'] : '';
            $token = is_scalar($_GET['owner_token'] ?? null) ? (string)$_GET['owner_token'] : '';
            if (preg_match('/^[a-z0-9-]+$/', $kind) !== 1 || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
                bms_activitypub_emit_error(404, 'The requested activity was not found.');
            }
            $activityUri = bms_activitypub_absolute_url('/activitypub/activities/owner/' . $kind . '/' . $token, $baseUrl);
            $document = bms_activitypub_owner_activity_document($activityUri);
            if (!is_array($document)) {
                bms_activitypub_emit_error(404, 'The requested activity was not found.');
            }
            bms_activitypub_emit_json($document, 200, $contentType);
        }

        if ($route === 'activitypub_object' || $route === 'activitypub_create_activity') {
            $postId = $_GET['post_id'] ?? '';
            if (!is_scalar($postId) || preg_match('/^[1-9][0-9]*$/', (string)$postId) !== 1) {
                bms_activitypub_emit_error(404, 'The requested object was not found.');
            }
            $postId = (int)$postId;
            if ($route === 'activitypub_create_activity') {
                $durableEvent = bms_activitypub_publication_event_by_activity_uri(bms_activitypub_create_activity_url($postId, $baseUrl));
                $durableDocument = is_array($durableEvent) ? json_decode((string)$durableEvent['payload_json'], true, bms_activitypub_json_max_depth()) : null;
                if (is_array($durableDocument) && !array_is_list($durableDocument)) {
                    bms_activitypub_emit_json($durableDocument, 200, $contentType);
                }
            }
            $generation = 1;
            if ($route === 'activitypub_object' && array_key_exists('generation', $_GET)) {
                $generationRaw = $_GET['generation'];
                if (!is_scalar($generationRaw) || preg_match('/^[1-9][0-9]*$/', (string)$generationRaw) !== 1 || (int)$generationRaw < 2) {
                    bms_activitypub_emit_error(404, 'The requested object was not found.');
                }
                $generation = (int)$generationRaw;
            }
            if ($route === 'activitypub_object') {
                $localObject = bms_activitypub_local_object_generation($postId, $generation);
                if (is_array($localObject)) {
                    if (trim((string)($localObject['deleted_at'] ?? '')) !== '') {
                        bms_activitypub_emit_json(bms_activitypub_local_tombstone_document($localObject), 410, $contentType);
                    }
                    $durableObject = json_decode((string)($localObject['last_object_json'] ?? ''), true, bms_activitypub_json_max_depth());
                    if (is_array($durableObject) && !array_is_list($durableObject)) {
                        bms_activitypub_emit_json(['@context' => bms_activitypub_context()] + $durableObject, 200, $contentType);
                    }
                }
                if ($generation > 1) {
                    bms_activitypub_emit_error(404, 'The requested object was not found.');
                }
            }
            $post = bms_activitypub_find_published_stream_post($postId);
            if (!is_array($post)) {
                bms_activitypub_emit_error(404, 'The requested object was not found.');
            }
            $document = $route === 'activitypub_object'
                ? bms_activitypub_post_object($post, $baseUrl, true)
                : bms_activitypub_create_activity($post, $baseUrl, true);
            bms_activitypub_emit_json($document, 200, $contentType);
        }
    } catch (Throwable $e) {
        error_log('Bonumark Stream ActivityPub read-only route failed: ' . $e->getMessage());
        bms_activitypub_emit_error(503, 'The ActivityPub response is temporarily unavailable.');
    }

    return true;
}
