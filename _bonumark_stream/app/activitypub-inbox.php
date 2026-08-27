<?php

require_once __DIR__ . '/activitypub-security.php';
require_once __DIR__ . '/activitypub-serialization.php';

function bms_activitypub_follow_policy(): string
{
    $policy = strtolower(trim((string)bms_setting_or_config('activitypub_follow_policy', 'manual')));
    return in_array($policy, ['manual', 'automatic'], true) ? $policy : 'manual';
}

function bms_activitypub_identifier_uri(string $value, bool $allowFragment = true): string
{
    $value = trim($value);
    $parts = parse_url($value);
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value) === 1
        || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])
        || (!$allowFragment && isset($parts['fragment']))) {
        throw new BmsActivityPubSecurityException('The ActivityPub identifier is invalid.', 400);
    }
    return $value;
}

function bms_activitypub_actor_reference(mixed $value): string
{
    if (is_string($value)) {
        return bms_activitypub_identifier_uri($value, false);
    }
    if (is_array($value) && !array_is_list($value)) {
        return bms_activitypub_identifier_uri((string)($value['id'] ?? ''), false);
    }
    throw new BmsActivityPubSecurityException('The activity actor is invalid.', 400);
}

function bms_activitypub_public_key_from_document(array $document, string $actorUri): array
{
    $keys = $document['publicKey'] ?? [];
    if (is_array($keys) && !array_is_list($keys)) {
        $keys = [$keys];
    }
    if (!is_array($keys)) {
        $keys = [];
    }
    foreach ($keys as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        if (!is_string($candidate['id'] ?? null) || !is_string($candidate['owner'] ?? null) || !is_string($candidate['publicKeyPem'] ?? null)) {
            continue;
        }
        $keyId = bms_activitypub_identifier_uri((string)($candidate['id'] ?? ''), true);
        $owner = bms_activitypub_identifier_uri((string)($candidate['owner'] ?? ''), false);
        $pem = trim((string)($candidate['publicKeyPem'] ?? ''));
        if (!hash_equals($actorUri, $owner) || $pem === '') {
            continue;
        }
        $key = openssl_pkey_get_public($pem);
        $details = $key !== false ? openssl_pkey_get_details($key) : false;
        if ($key === false || !is_array($details) || (int)($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA || (int)($details['bits'] ?? 0) < 2048) {
            continue;
        }
        return ['id' => $keyId, 'owner' => $owner, 'public_key_pem' => $pem . "\n"];
    }
    throw new BmsActivityPubSecurityException('The remote actor has no acceptable owned RSA signing key.', 502);
}

function bms_activitypub_validate_remote_actor_document(array $document, string $requestedActorUri, ?callable $resolver = null): array
{
    if (!is_string($document['id'] ?? null) || !is_string($document['type'] ?? null) || !is_string($document['inbox'] ?? null)) {
        throw new BmsActivityPubSecurityException('The remote actor document has invalid identity fields.', 502);
    }
    $actorUri = bms_activitypub_identifier_uri((string)$document['id'], false);
    if (!hash_equals($requestedActorUri, $actorUri)) {
        throw new BmsActivityPubSecurityException('The remote actor document does not match the requested actor.', 502);
    }
    $type = trim((string)($document['type'] ?? ''));
    if (!in_array($type, ['Person', 'Service', 'Application', 'Organization', 'Group'], true)) {
        throw new BmsActivityPubSecurityException('The remote actor type is unsupported.', 502);
    }
    $inbox = bms_activitypub_identifier_uri((string)($document['inbox'] ?? ''), false);
    bms_activitypub_validate_remote_url($inbox, $resolver, false);
    $sharedInbox = '';
    $endpoints = $document['endpoints'] ?? null;
    if (is_array($endpoints) && !array_is_list($endpoints) && is_string($endpoints['sharedInbox'] ?? null) && trim((string)$endpoints['sharedInbox']) !== '') {
        $sharedInbox = bms_activitypub_identifier_uri((string)$endpoints['sharedInbox'], false);
        bms_activitypub_validate_remote_url($sharedInbox, $resolver, false);
    }
    $key = bms_activitypub_public_key_from_document($document, $actorUri);
    return [
        'actor_uri' => $actorUri,
        'actor_type' => $type,
        'preferred_username' => bms_text_substr(is_string($document['preferredUsername'] ?? null) ? trim((string)$document['preferredUsername']) : '', 0, 190),
        'display_name' => bms_text_substr(is_string($document['name'] ?? null) ? trim((string)$document['name']) : '', 0, 255),
        'inbox_url' => $inbox,
        'shared_inbox_url' => $sharedInbox,
        'public_key_id' => (string)$key['id'],
        'public_key_pem' => (string)$key['public_key_pem'],
        'key_owner_uri' => (string)$key['owner'],
        'document' => $document,
    ];
}

function bms_activitypub_cached_remote_actor(string $actorUri, bool $allowExpired = false): ?array
{
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_remote_actors') . ' WHERE actor_uri = :actor_uri LIMIT 1');
    $stmt->execute(['actor_uri' => $actorUri]);
    $actor = $stmt->fetch();
    if (!is_array($actor)) {
        return null;
    }
    if (!$allowExpired) {
        try {
            $expiresAt = (new DateTimeImmutable((string)($actor['expires_at'] ?? ''), bms_utc_timezone()))->getTimestamp();
        } catch (Throwable $e) {
            $expiresAt = 0;
        }
        if ($expiresAt <= time()) {
            return null;
        }
    }
    $document = json_decode((string)($actor['document_json'] ?? ''), true, bms_activitypub_json_max_depth());
    $actor['document'] = is_array($document) ? $document : [];
    return $actor;
}

function bms_activitypub_store_remote_actor(array $actor): array
{
    $documentJson = json_encode($actor['document'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($documentJson) || strlen($documentJson) > bms_activitypub_remote_document_max_bytes()) {
        throw new BmsActivityPubSecurityException('The remote actor document cannot be cached safely.', 502);
    }
    $sql = 'INSERT INTO ' . bms_table('activitypub_remote_actors') . ' (actor_uri, actor_type, preferred_username, display_name, inbox_url, shared_inbox_url, public_key_id, public_key_pem, key_owner_uri, document_json, fetched_at, expires_at, created_at, updated_at) '
        . 'VALUES (:actor_uri, :actor_type, :preferred_username, :display_name, :inbox_url, :shared_inbox_url, :public_key_id, :public_key_pem, :key_owner_uri, :document_json, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
        . 'ON DUPLICATE KEY UPDATE actor_type = VALUES(actor_type), preferred_username = VALUES(preferred_username), display_name = VALUES(display_name), inbox_url = VALUES(inbox_url), shared_inbox_url = VALUES(shared_inbox_url), public_key_id = VALUES(public_key_id), public_key_pem = VALUES(public_key_pem), key_owner_uri = VALUES(key_owner_uri), document_json = VALUES(document_json), fetched_at = UTC_TIMESTAMP(), expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), updated_at = UTC_TIMESTAMP()';
    $stmt = bms_db()->prepare($sql);
    $stmt->execute([
        'actor_uri' => (string)$actor['actor_uri'],
        'actor_type' => (string)$actor['actor_type'],
        'preferred_username' => (string)$actor['preferred_username'],
        'display_name' => (string)$actor['display_name'],
        'inbox_url' => (string)$actor['inbox_url'],
        'shared_inbox_url' => (string)$actor['shared_inbox_url'] !== '' ? (string)$actor['shared_inbox_url'] : null,
        'public_key_id' => (string)$actor['public_key_id'],
        'public_key_pem' => (string)$actor['public_key_pem'],
        'key_owner_uri' => (string)$actor['key_owner_uri'],
        'document_json' => $documentJson,
    ]);
    $stored = bms_activitypub_cached_remote_actor((string)$actor['actor_uri'], true);
    if (!is_array($stored)) {
        throw new RuntimeException('The remote actor cache write did not persist.');
    }
    return $stored;
}

function bms_activitypub_discover_remote_actor(string $actorUri, bool $force = false, ?callable $fetcher = null, ?callable $resolver = null, bool $persist = true): array
{
    $actorUri = bms_activitypub_identifier_uri($actorUri, false);
    bms_activitypub_validate_remote_url($actorUri, $resolver, false);
    if (!$force) {
        $cached = bms_activitypub_cached_remote_actor($actorUri, false);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $fetched = $fetcher !== null ? $fetcher($actorUri) : bms_activitypub_fetch_json($actorUri, null, $resolver);
    $document = is_array($fetched) && is_array($fetched['document'] ?? null) ? $fetched['document'] : $fetched;
    if (!is_array($document) || array_is_list($document)) {
        throw new BmsActivityPubSecurityException('The remote actor fetch returned an invalid document.', 502);
    }
    $actor = bms_activitypub_validate_remote_actor_document($document, $actorUri, $resolver);
    if (!$persist) {
        return $actor;
    }
    return bms_activitypub_store_remote_actor($actor);
}

function bms_activitypub_inbox_request_headers(): array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headers)) {
        $headers = [];
    }
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string)$key, 'HTTP_')) {
            $name = str_replace('_', '-', strtolower(substr((string)$key, 5)));
            $headers[$name] = (string)$value;
        } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $headers[str_replace('_', '-', strtolower($key))] = (string)$value;
        }
    }
    return bms_activitypub_normalize_request_headers($headers);
}

function bms_activitypub_inbox_read_body(?string $providedBody = null, ?int $contentLength = null): string
{
    $limit = bms_activitypub_inbox_max_bytes();
    if ($contentLength !== null && ($contentLength < 0 || $contentLength > $limit)) {
        throw new BmsActivityPubSecurityException('The inbox request is too large.', 413);
    }
    if ($providedBody !== null) {
        $body = $providedBody;
    } else {
        $stream = fopen('php://input', 'rb');
        $body = is_resource($stream) ? stream_get_contents($stream, $limit + 1) : false;
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (!is_string($body)) {
            throw new BmsActivityPubSecurityException('The inbox request body could not be read.', 400);
        }
    }
    if ($body === '' || strlen($body) > $limit) {
        throw new BmsActivityPubSecurityException('The inbox request is empty or too large.', strlen($body) > $limit ? 413 : 400);
    }
    return $body;
}

function bms_activitypub_inbox_content_type_is_supported(string $contentType): bool
{
    $type = strtolower(trim(explode(';', $contentType, 2)[0]));
    return in_array($type, ['application/activity+json', 'application/ld+json', 'application/json'], true);
}

function bms_activitypub_target_object_id(mixed $object): string
{
    if (is_string($object)) {
        return trim($object);
    }
    if (is_array($object) && !array_is_list($object)) {
        return trim((string)($object['id'] ?? ''));
    }
    return '';
}

function bms_activitypub_insert_replay_fingerprint(array $verification): bool
{
    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_signature_replays') . ' (fingerprint, key_id, created_at, expires_at) VALUES (:fingerprint, :key_id, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))');
        $stmt->execute(['fingerprint' => (string)$verification['fingerprint'], 'key_id' => (string)$verification['key_id']]);
        return true;
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            return false;
        }
        throw $e;
    }
}

function bms_activitypub_existing_receipt(string $activityUri): ?array
{
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_inbox_receipts') . ' WHERE activity_uri = :activity_uri LIMIT 1');
    $stmt->execute(['activity_uri' => $activityUri]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_insert_receipt(array $activity, array $verification, string $body): array
{
    $activityUri = bms_activitypub_identifier_uri((string)($activity['id'] ?? ''), true);
    $existing = bms_activitypub_existing_receipt($activityUri);
    if (is_array($existing)) {
        return ['receipt' => $existing, 'duplicate' => true];
    }
    $json = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        throw new BmsActivityPubSecurityException('The activity could not be retained safely.', 400);
    }
    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_inbox_receipts') . ' (activity_uri, activity_type, actor_uri, key_id, body_hash, signature_date, activity_json, status, result_code, received_at, processed_at) VALUES (:activity_uri, :activity_type, :actor_uri, :key_id, :body_hash, :signature_date, :activity_json, :status, :result_code, UTC_TIMESTAMP(), NULL)');
        $stmt->execute([
            'activity_uri' => $activityUri,
            'activity_type' => bms_text_substr(trim((string)($activity['type'] ?? '')), 0, 40),
            'actor_uri' => bms_activitypub_actor_reference($activity['actor'] ?? null),
            'key_id' => (string)$verification['key_id'],
            'body_hash' => hash('sha256', $body),
            'signature_date' => (string)$verification['signature_date'],
            'activity_json' => $json,
            'status' => 'received',
            'result_code' => '',
        ]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') {
            throw $e;
        }
        $existing = bms_activitypub_existing_receipt($activityUri);
        if (!is_array($existing)) {
            throw $e;
        }
        return ['receipt' => $existing, 'duplicate' => true];
    }
    $receipt = bms_activitypub_existing_receipt($activityUri);
    return ['receipt' => is_array($receipt) ? $receipt : ['id' => (int)bms_db()->lastInsertId()], 'duplicate' => false];
}

function bms_activitypub_finish_receipt(int $receiptId, string $status, string $resultCode): void
{
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('activitypub_inbox_receipts') . ' SET status = :status, result_code = :result_code, processed_at = UTC_TIMESTAMP() WHERE id = :id');
    $stmt->execute(['status' => $status, 'result_code' => bms_text_substr($resultCode, 0, 80), 'id' => $receiptId]);
}

function bms_activitypub_upsert_follower(array $activity, array $remoteActor, int $receiptId): array
{
    $actorUri = bms_activitypub_actor_reference($activity['actor'] ?? null);
    $followUri = bms_activitypub_identifier_uri((string)($activity['id'] ?? ''), true);
    $state = bms_activitypub_follow_policy() === 'automatic' ? 'accepted' : 'pending';
    $sql = 'INSERT INTO ' . bms_table('activitypub_followers') . ' (remote_actor_id, actor_uri, follow_activity_uri, follow_receipt_id, state, response_activity_uri, followed_at, moderated_at, created_at, updated_at) '
        . 'VALUES (:remote_actor_id, :actor_uri, :follow_activity_uri, :follow_receipt_id, :state, NULL, UTC_TIMESTAMP(), :moderated_at, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
        . 'ON DUPLICATE KEY UPDATE remote_actor_id = VALUES(remote_actor_id), follow_activity_uri = VALUES(follow_activity_uri), follow_receipt_id = VALUES(follow_receipt_id), state = IF(state = \'blocked\', \'blocked\', VALUES(state)), response_activity_uri = NULL, followed_at = UTC_TIMESTAMP(), moderated_at = IF(state = \'blocked\', moderated_at, VALUES(moderated_at)), updated_at = UTC_TIMESTAMP()';
    $stmt = bms_db()->prepare($sql);
    $stmt->execute([
        'remote_actor_id' => (int)$remoteActor['id'],
        'actor_uri' => $actorUri,
        'follow_activity_uri' => $followUri,
        'follow_receipt_id' => $receiptId,
        'state' => $state,
        'moderated_at' => $state === 'accepted' ? gmdate('Y-m-d H:i:s') : null,
    ]);
    $select = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_followers') . ' WHERE actor_uri = :actor_uri LIMIT 1');
    $select->execute(['actor_uri' => $actorUri]);
    $follower = $select->fetch();
    if (!is_array($follower)) {
        throw new RuntimeException('The follower relationship was not persisted.');
    }
    return $follower;
}

function bms_activitypub_follow_response_document(array $follower, string $decision, ?string $baseUrl = null): array
{
    $decision = $decision === 'accepted' ? 'Accept' : 'Reject';
    $stmt = bms_db()->prepare('SELECT activity_json FROM ' . bms_table('activitypub_inbox_receipts') . ' WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int)$follower['follow_receipt_id']]);
    $activity = json_decode((string)$stmt->fetchColumn(), true, bms_activitypub_json_max_depth());
    if (!is_array($activity)) {
        $activity = (string)$follower['follow_activity_uri'];
    }
    $token = substr(hash('sha256', (string)$follower['follow_activity_uri'] . "\n" . $decision), 0, 24);
    return [
        '@context' => bms_activitypub_context(),
        'id' => bms_activitypub_absolute_url('/activitypub/activities/follow-response/' . (int)$follower['id'] . '/' . strtolower($decision) . '/' . $token, $baseUrl),
        'type' => $decision,
        'actor' => bms_activitypub_actor_url($baseUrl),
        'object' => $activity,
        'to' => [(string)$follower['actor_uri']],
    ];
}

function bms_activitypub_queue_follow_response(array $follower, string $decision): ?int
{
    $decision = $decision === 'accepted' ? 'accepted' : 'rejected';
    $remoteActor = bms_activitypub_cached_remote_actor((string)$follower['actor_uri'], true);
    if (!is_array($remoteActor)) {
        throw new RuntimeException('The remote actor cache is unavailable for the response delivery.');
    }
    $inboxUrl = trim((string)($remoteActor['shared_inbox_url'] ?? '')) ?: trim((string)$remoteActor['inbox_url']);
    bms_activitypub_validate_remote_url($inboxUrl, null, false);
    $document = bms_activitypub_follow_response_document($follower, $decision);
    $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        throw new RuntimeException('The follower response could not be encoded.');
    }
    $activityUri = (string)$document['id'];
    $dedupeKey = hash('sha256', 'follower_response' . "\n" . $activityUri . "\n" . $inboxUrl);
    $stmt = bms_db()->prepare('INSERT IGNORE INTO ' . bms_table('activitypub_deliveries') . ' (delivery_type, event_id, activity_uri, payload_json, dedupe_key, inbox_url, status, attempt_count, available_at, last_attempt_at, delivered_at, http_status, last_error, created_at, updated_at) VALUES (:delivery_type, NULL, :activity_uri, :payload_json, :dedupe_key, :inbox_url, :status, 0, UTC_TIMESTAMP(), NULL, NULL, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $stmt->execute([
        'delivery_type' => 'follower_response',
        'activity_uri' => $activityUri,
        'payload_json' => $payload,
        'dedupe_key' => $dedupeKey,
        'inbox_url' => $inboxUrl,
        'status' => 'pending',
    ]);
    $update = bms_db()->prepare('UPDATE ' . bms_table('activitypub_followers') . ' SET response_activity_uri = :activity_uri, updated_at = UTC_TIMESTAMP() WHERE id = :id');
    $update->execute(['activity_uri' => $activityUri, 'id' => (int)$follower['id']]);
    if ($stmt->rowCount() > 0) {
        return (int)bms_db()->lastInsertId();
    }
    $select = bms_db()->prepare('SELECT id FROM ' . bms_table('activitypub_deliveries') . ' WHERE dedupe_key = :dedupe_key LIMIT 1');
    $select->execute(['dedupe_key' => $dedupeKey]);
    $id = $select->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function bms_activitypub_process_follow(array $activity, array $remoteActor, int $receiptId): string
{
    if (!hash_equals(bms_activitypub_actor_url(), bms_activitypub_target_object_id($activity['object'] ?? null))) {
        throw new BmsActivityPubSecurityException('The Follow does not target this actor.', 400);
    }
    $follower = bms_activitypub_upsert_follower($activity, $remoteActor, $receiptId);
    if ((string)$follower['state'] === 'accepted') {
        bms_activitypub_queue_follow_response($follower, 'accepted');
        return 'follow_accepted';
    }
    if ((string)$follower['state'] === 'blocked') {
        bms_activitypub_queue_follow_response($follower, 'rejected');
        return 'follow_blocked';
    }
    return 'follow_pending';
}

function bms_activitypub_process_undo(array $activity): string
{
    $actorUri = bms_activitypub_actor_reference($activity['actor'] ?? null);
    $object = $activity['object'] ?? null;
    $followUri = bms_activitypub_target_object_id($object);
    if ($followUri === '') {
        throw new BmsActivityPubSecurityException('The Undo object is invalid.', 400);
    }
    if (is_array($object) && strcasecmp((string)($object['type'] ?? ''), 'Follow') !== 0) {
        throw new BmsActivityPubSecurityException('Only Undo of Follow is supported.', 400);
    }
    if (is_array($object) && !hash_equals($actorUri, bms_activitypub_actor_reference($object['actor'] ?? null))) {
        throw new BmsActivityPubSecurityException('The Undo actor does not own the Follow.', 400);
    }
    $stmt = bms_db()->prepare("UPDATE " . bms_table('activitypub_followers') . " SET state = 'removed', moderated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND follow_activity_uri = :follow_activity_uri AND state IN ('pending', 'accepted', 'rejected')");
    $stmt->execute(['actor_uri' => $actorUri, 'follow_activity_uri' => $followUri]);
    return $stmt->rowCount() > 0 ? 'follow_undone' : 'undo_unmatched';
}

function bms_activitypub_process_follow_response(array $activity, string $type): string
{
    $actorUri = bms_activitypub_actor_reference($activity['actor'] ?? null);
    $followUri = bms_activitypub_target_object_id($activity['object'] ?? null);
    if ($followUri === '') {
        throw new BmsActivityPubSecurityException('The response object is invalid.', 400);
    }
    $state = $type === 'Accept' ? 'accepted' : 'rejected';
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('activitypub_following') . ' f INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = f.remote_actor_id SET f.state = :state, f.updated_at = UTC_TIMESTAMP() WHERE f.follow_activity_uri = :follow_activity_uri AND a.actor_uri = :actor_uri');
    $stmt->execute(['state' => $state, 'follow_activity_uri' => $followUri, 'actor_uri' => $actorUri]);
    return $stmt->rowCount() > 0 ? 'following_' . $state : 'response_unmatched';
}

function bms_activitypub_process_verified_activity(array $activity, array $remoteActor, int $receiptId): string
{
    $type = trim((string)($activity['type'] ?? ''));
    return match ($type) {
        'Follow' => bms_activitypub_process_follow($activity, $remoteActor, $receiptId),
        'Undo' => bms_activitypub_process_undo($activity),
        'Accept', 'Reject' => bms_activitypub_process_follow_response($activity, $type),
        default => 'unsupported_activity',
    };
}

function bms_activitypub_receive_inbox(array $request, ?callable $fetcher = null, ?callable $resolver = null, int $now = 0): array
{
    if (!bms_activitypub_enabled()) {
        throw new BmsActivityPubSecurityException('Not found.', 404);
    }
    $headers = bms_activitypub_normalize_request_headers(is_array($request['headers'] ?? null) ? $request['headers'] : []);
    if (!bms_activitypub_inbox_content_type_is_supported((string)($headers['content-type'] ?? ''))) {
        throw new BmsActivityPubSecurityException('The inbox requires ActivityPub JSON.', 415);
    }
    if (isset($headers['content-length']) && preg_match('/^[0-9]+$/', (string)$headers['content-length']) !== 1) {
        throw new BmsActivityPubSecurityException('The inbox Content-Length is invalid.', 400);
    }
    $contentLength = isset($headers['content-length']) ? (int)$headers['content-length'] : null;
    $body = bms_activitypub_inbox_read_body((string)($request['body'] ?? ''), $contentLength);
    if ($contentLength !== null && $contentLength !== strlen($body)) {
        throw new BmsActivityPubSecurityException('The inbox Content-Length does not match the request body.', 400);
    }
    $activity = bms_activitypub_decode_json_document($body, bms_activitypub_inbox_max_bytes());
    if (!is_string($activity['id'] ?? null) || !is_string($activity['type'] ?? null)) {
        throw new BmsActivityPubSecurityException('The activity identity or type is invalid.', 400);
    }
    bms_activitypub_identifier_uri((string)$activity['id'], true);
    $type = trim((string)$activity['type']);
    if ($type === '' || strlen($type) > 40) {
        throw new BmsActivityPubSecurityException('The activity type is invalid.', 400);
    }
    $actorUri = bms_activitypub_actor_reference($activity['actor'] ?? null);
    $hostHeader = strtolower(rtrim(trim((string)($headers['host'] ?? '')), '.'));
    if (!hash_equals(bms_activitypub_account_host(), $hostHeader)) {
        throw new BmsActivityPubSecurityException('The signed Host does not match this site.', 401);
    }

    // Discovery is bounded and SSRF-safe. New documents are not cached until
    // their key has successfully authenticated the activity.
    $remoteActor = bms_activitypub_discover_remote_actor($actorUri, false, $fetcher, $resolver, false);
    $remoteActorWasCached = isset($remoteActor['id']);
    $signatureMetadata = bms_activitypub_signature_metadata(['headers' => $headers]);
    if (!hash_equals((string)$remoteActor['public_key_id'], trim((string)$signatureMetadata['key_id']))
        || !hash_equals($actorUri, (string)$remoteActor['key_owner_uri'])) {
        throw new BmsActivityPubSecurityException('The signing key does not belong to the activity actor.', 401);
    }
    $request['headers'] = $headers;
    $request['body'] = $body;
    $request['resolver'] = $resolver;
    try {
        $verification = bms_activitypub_verify_http_signature($request, (string)$remoteActor['public_key_pem'], $now);
    } catch (BmsActivityPubSecurityException $e) {
        // One bounded refresh handles legitimate remote key rotation.
        if ($e->httpStatus() !== 401) {
            throw $e;
        }
        $remoteActor = bms_activitypub_discover_remote_actor($actorUri, true, $fetcher, $resolver, false);
        $remoteActorWasCached = false;
        if (!hash_equals((string)$remoteActor['public_key_id'], trim((string)$signatureMetadata['key_id']))
            || !hash_equals($actorUri, (string)$remoteActor['key_owner_uri'])) {
            throw new BmsActivityPubSecurityException('The refreshed signing key does not belong to the activity actor.', 401);
        }
        $verification = bms_activitypub_verify_http_signature($request, (string)$remoteActor['public_key_pem'], $now);
    }
    if (!$remoteActorWasCached) {
        $remoteActor = bms_activitypub_store_remote_actor($remoteActor);
    }

    $pdo = bms_db();
    $pdo->beginTransaction();
    try {
        if (!bms_activitypub_insert_replay_fingerprint($verification)) {
            throw new BmsActivityPubSecurityException('The signed request has already been used.', 409);
        }
        $insert = bms_activitypub_insert_receipt($activity, $verification, $body);
        if (!empty($insert['duplicate'])) {
            $pdo->commit();
            return ['status' => 'duplicate', 'result_code' => 'duplicate_activity', 'receipt_id' => (int)$insert['receipt']['id']];
        }
        $receiptId = (int)$insert['receipt']['id'];
        $resultCode = bms_activitypub_process_verified_activity($activity, $remoteActor, $receiptId);
        $receiptStatus = $resultCode === 'unsupported_activity' ? 'ignored' : 'processed';
        bms_activitypub_finish_receipt($receiptId, $receiptStatus, $resultCode);
        $pdo->commit();
        return ['status' => $receiptStatus, 'result_code' => $resultCode, 'receipt_id' => $receiptId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_activitypub_follower_rows(string $state = '', int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $where = $state !== '' ? ' WHERE f.state = :state' : '';
    $stmt = bms_db()->prepare('SELECT f.*, a.preferred_username, a.display_name, a.inbox_url, a.shared_inbox_url FROM ' . bms_table('activitypub_followers') . ' f INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = f.remote_actor_id' . $where . ' ORDER BY f.updated_at DESC LIMIT ' . $limit);
    $stmt->execute($state !== '' ? ['state' => $state] : []);
    return $stmt->fetchAll() ?: [];
}

function bms_activitypub_collection_actor_uris(string $relationship): array
{
    $table = $relationship === 'following' ? 'activitypub_following' : 'activitypub_followers';
    $stmt = bms_db()->query('SELECT actor_uri FROM ' . bms_table($table) . " WHERE state = 'accepted' ORDER BY actor_uri ASC");
    return $stmt ? array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])) : [];
}

function bms_activitypub_moderate_follower(int $followerId, string $action): array
{
    $action = strtolower(trim($action));
    $state = match ($action) {
        'approve' => 'accepted',
        'reject' => 'rejected',
        'block' => 'blocked',
        'remove' => 'removed',
        default => throw new InvalidArgumentException('Unsupported follower moderation action.'),
    };
    $pdo = bms_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM ' . bms_table('activitypub_followers') . ' WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $followerId]);
        $follower = $stmt->fetch();
        if (!is_array($follower)) {
            throw new RuntimeException('The follower relationship was not found.');
        }
        $update = $pdo->prepare('UPDATE ' . bms_table('activitypub_followers') . ' SET state = :state, moderated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute(['state' => $state, 'id' => $followerId]);
        $follower['state'] = $state;
        $deliveryId = null;
        if ($state === 'accepted') {
            $deliveryId = bms_activitypub_queue_follow_response($follower, 'accepted');
        } elseif (in_array($state, ['rejected', 'blocked'], true)) {
            $deliveryId = bms_activitypub_queue_follow_response($follower, 'rejected');
        }
        $pdo->commit();
        return ['state' => $state, 'delivery_id' => $deliveryId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_activitypub_deliver_response_row(array $delivery, ?callable $transport = null, ?callable $resolver = null): array
{
    if ((string)($delivery['delivery_type'] ?? '') !== 'follower_response' || $delivery['event_id'] !== null) {
        throw new RuntimeException('The delivery is not an isolated follower response.');
    }
    $payload = (string)($delivery['payload_json'] ?? '');
    $document = bms_activitypub_decode_json_document($payload, bms_activitypub_inbox_max_bytes());
    if (!in_array((string)($document['type'] ?? ''), ['Accept', 'Reject'], true)) {
        throw new RuntimeException('Only Accept and Reject responses are eligible for Stage 3 delivery.');
    }
    $key = bms_activitypub_active_signing_key(true);
    if (!is_array($key)) {
        throw new RuntimeException('No active ActivityPub signing key is available.');
    }
    $url = (string)$delivery['inbox_url'];
    $headers = bms_activitypub_sign_outbound_request('POST', $url, $payload, $key);
    return bms_activitypub_http_request($url, [
        'method' => 'POST',
        'body' => $payload,
        'headers' => $headers,
        'max_bytes' => 262144,
        'max_redirects' => 0,
    ], $transport, $resolver);
}

function bms_activitypub_run_response_deliveries(int $limit = 20, ?callable $transport = null, ?callable $resolver = null): array
{
    if (!bms_activitypub_enabled()) {
        return ['ok' => true, 'count' => 0, 'message' => 'ActivityPub response delivery is disabled.'];
    }
    try {
        if (!is_array(bms_activitypub_active_signing_key(false))) {
            return ['ok' => false, 'count' => 0, 'message' => 'ActivityPub follower responses are waiting for an active signing key.'];
        }
    } catch (Throwable $e) {
        error_log('Bonumark Stream ActivityPub signing identity unavailable: ' . $e->getMessage());
        return ['ok' => false, 'count' => 0, 'message' => 'ActivityPub follower responses are waiting for a usable signing key.'];
    }
    $limit = max(1, min(100, $limit));
    bms_db()->exec("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', available_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE delivery_type = 'follower_response' AND event_id IS NULL AND status = 'processing' AND last_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)");
    $stmt = bms_db()->query("SELECT * FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'follower_response' AND event_id IS NULL AND status IN ('pending', 'retry') AND available_at <= UTC_TIMESTAMP() ORDER BY available_at ASC, id ASC LIMIT " . $limit);
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    $delivered = 0;
    foreach ($rows as $row) {
        $claim = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'processing', attempt_count = attempt_count + 1, last_attempt_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type = 'follower_response' AND event_id IS NULL AND status IN ('pending', 'retry')");
        $claim->execute(['id' => (int)$row['id']]);
        if ($claim->rowCount() < 1) {
            continue;
        }
        $row['attempt_count'] = (int)$row['attempt_count'] + 1;
        try {
            $response = bms_activitypub_deliver_response_row($row, $transport, $resolver);
            $status = (int)($response['status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                $update = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'delivered', delivered_at = UTC_TIMESTAMP(), http_status = :http_status, last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id");
                $update->execute(['http_status' => $status, 'id' => (int)$row['id']]);
                $delivered++;
                continue;
            }
            throw new BmsActivityPubSecurityException('The remote inbox returned HTTP ' . $status . '.', $status >= 400 && $status <= 599 ? $status : 502);
        } catch (Throwable $e) {
            $attempts = (int)$row['attempt_count'];
            $statusCode = $e instanceof BmsActivityPubSecurityException ? $e->httpStatus() : 0;
            $permanent = $attempts >= 8 || in_array($statusCode, [400, 401, 403, 404, 405, 410, 413, 415, 422], true);
            $delay = min(86400, 60 * (2 ** min(10, max(0, $attempts - 1))));
            $update = bms_db()->prepare('UPDATE ' . bms_table('activitypub_deliveries') . " SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . (int)$delay . " SECOND), http_status = :http_status, last_error = :last_error, updated_at = UTC_TIMESTAMP() WHERE id = :id");
            $update->execute([
                'status' => $permanent ? 'dead' : 'retry',
                'http_status' => $statusCode > 0 ? $statusCode : null,
                'last_error' => bms_text_substr($e->getMessage(), 0, 1000),
                'id' => (int)$row['id'],
            ]);
        }
    }
    bms_db()->exec('DELETE FROM ' . bms_table('activitypub_signature_replays') . ' WHERE expires_at < UTC_TIMESTAMP()');
    return ['ok' => true, 'count' => $delivered, 'message' => $delivered > 0 ? 'Delivered ' . $delivered . ' follower response' . ($delivered === 1 ? '' : 's') . '.' : 'No follower responses were delivered.'];
}
