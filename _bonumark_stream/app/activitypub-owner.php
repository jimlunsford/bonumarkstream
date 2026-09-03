<?php

require_once __DIR__ . '/activitypub-security.php';
require_once __DIR__ . '/activitypub-serialization.php';
require_once __DIR__ . '/activitypub-interactions.php';
require_once __DIR__ . '/activitypub-delivery.php';

function bms_activitypub_owner_activity_url(string $kind, ?string $baseUrl = null): string
{
    $kind = preg_replace('/[^a-z0-9-]/', '', strtolower($kind)) ?: 'activity';
    return bms_activitypub_absolute_url('/activitypub/activities/owner/' . $kind . '/' . bin2hex(random_bytes(16)), $baseUrl);
}

function bms_activitypub_owner_action_document(string $type, string $activityUri, string $actorUri, string $targetUri, array|string|null $objectActivity = null, bool $public = false): array
{
    $type = trim($type);
    if (!in_array($type, ['Follow', 'Undo', 'Like', 'Announce'], true)) {
        throw new InvalidArgumentException('Unsupported owner ActivityPub action.');
    }
    $object = $targetUri;
    if ($type === 'Undo') {
        if (!is_array($objectActivity) || array_is_list($objectActivity)) {
            throw new InvalidArgumentException('Owner Undo activities must embed the original activity.');
        }
        $object = $objectActivity;
    }
    $document = [
        '@context' => bms_activitypub_context(),
        'id' => $activityUri,
        'type' => $type,
        'actor' => bms_activitypub_actor_url(),
        'object' => $object,
    ];
    if ($public) {
        $document['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
        $document['cc'] = [bms_activitypub_followers_url(), $actorUri];
    } else {
        $document['to'] = [$actorUri];
    }
    return $document;
}

function bms_activitypub_actor_delete_document(string $activityUri, ?string $baseUrl = null): array
{
    $actorUri = bms_activitypub_actor_url($baseUrl);
    return [
        '@context' => bms_activitypub_context(),
        'id' => $activityUri,
        'type' => 'Delete',
        'actor' => $actorUri,
        'object' => $actorUri,
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => [bms_activitypub_followers_url($baseUrl)],
    ];
}

function bms_activitypub_queue_actor_delete(array $document, array $targets): int
{
    $activityUri = bms_activitypub_identifier_uri((string)($document['id'] ?? ''), true);
    $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($payload) || strlen($payload) > bms_activitypub_publication_payload_max_bytes()) {
        throw new RuntimeException('The Actor Delete activity could not be encoded safely.');
    }
    $count = 0;
    foreach ($targets as $target) {
        $inboxUrl = trim((string)($target['inbox_url'] ?? ''));
        if (!bms_activitypub_delivery_url_is_structurally_safe($inboxUrl)) {
            continue;
        }
        $actorIds = array_values(array_unique(array_filter(array_map('intval', (array)($target['actor_ids'] ?? [])))));
        sort($actorIds, SORT_NUMERIC);
        $actorJson = json_encode($actorIds, JSON_UNESCAPED_SLASHES);
        $dedupeKey = hash('sha256', "actor_delete\n" . $activityUri . "\n" . $inboxUrl);
        $stmt = bms_db()->prepare("INSERT IGNORE INTO " . bms_table('activitypub_deliveries') . " (delivery_type, event_id, publication_generation, object_uri, activity_uri, payload_json, dedupe_key, inbox_url, recipient_actor_ids_json, signature_mode, status, attempt_count, available_at, last_attempt_at, delivered_at, http_status, last_error, created_at, updated_at) VALUES ('actor_delete', NULL, NULL, :object_uri, :activity_uri, :payload_json, :dedupe_key, :inbox_url, :actor_ids, :signature_mode, 'pending', 0, UTC_TIMESTAMP(), NULL, NULL, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $stmt->execute([
            'object_uri' => bms_activitypub_actor_url(), 'activity_uri' => $activityUri,
            'payload_json' => $payload, 'dedupe_key' => $dedupeKey, 'inbox_url' => $inboxUrl,
            'actor_ids' => is_string($actorJson) ? $actorJson : '[]',
            'signature_mode' => !empty($target['rfc9421']) ? 'rfc9421' : 'legacy',
        ]);
        $count += $stmt->rowCount() > 0 ? 1 : 0;
    }
    return $count;
}

function bms_activitypub_permanently_deactivate(): array
{
    $existing = bms_activitypub_actor_retirement();
    if (is_array($existing)) {
        return ['status' => 'retired', 'activity_uri' => (string)$existing['delete_activity_uri'], 'idempotent' => true];
    }
    if (!bms_activitypub_enabled()) {
        throw new RuntimeException('Only an active, paused, or delivery-suspended federation identity can be permanently deactivated.');
    }
    $health = bms_activitypub_signing_key_health();
    if (empty($health['ok'])) {
        throw new RuntimeException('A usable signing key is required to commit and deliver permanent Actor Delete.');
    }
    $actorUri = bms_activitypub_actor_url();
    $activityUri = bms_activitypub_owner_activity_url('delete-actor');
    $document = bms_activitypub_actor_delete_document($activityUri);
    $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($payload)) {
        throw new RuntimeException('The Actor Delete activity could not be encoded.');
    }
    $targets = bms_activitypub_publication_targets();
    $pdo = bms_db();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT INTO " . bms_table('activitypub_local_actor_lifecycle') . " (actor_uri, lifecycle_state, delete_activity_uri, delete_payload_json, retired_at, delivery_completed_at, created_at, updated_at) VALUES (:actor_uri, 'retired', :activity_uri, :payload_json, UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $insert->execute(['actor_uri' => $actorUri, 'activity_uri' => $activityUri, 'payload_json' => $payload]);
        bms_activitypub_queue_actor_delete($document, $targets);
        $cancel = $pdo->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'cancelled', last_error = 'Cancelled by permanent local actor retirement.', updated_at = UTC_TIMESTAMP() WHERE status IN ('pending', 'retry', 'processing') AND activity_uri <> :activity_uri");
        $cancel->execute(['activity_uri' => $activityUri]);
        $pdo->exec("UPDATE " . bms_table('activitypub_followers') . " SET state = 'removed', moderated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE state <> 'removed'");
        $pdo->exec("UPDATE " . bms_table('activitypub_following') . " SET state = 'removed', state_changed_at = UTC_TIMESTAMP(), removed_at = COALESCE(removed_at, UTC_TIMESTAMP()), last_error = 'Local actor permanently retired.', updated_at = UTC_TIMESTAMP() WHERE state <> 'removed'");
        $pdo->exec("UPDATE " . bms_table('activitypub_owner_interactions') . " SET state = 'retired', last_error = 'Local actor permanently retired.', updated_at = UTC_TIMESTAMP() WHERE state IN ('active', 'queued')");
        bms_set_setting('activitypub_enabled', '0');
        bms_set_setting('activitypub_paused', '0');
        bms_set_setting('activitypub_delivery_suspended', '0');
        bms_set_setting('activitypub_deactivated', '1');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $raced = bms_activitypub_actor_retirement();
        if (is_array($raced)) {
            return ['status' => 'retired', 'activity_uri' => (string)$raced['delete_activity_uri'], 'idempotent' => true];
        }
        throw $e;
    }
    return ['status' => 'retired', 'activity_uri' => $activityUri, 'idempotent' => false];
}

function bms_activitypub_queue_owner_action(array $remoteActor, array $document, bool $fanoutFollowers = false): int
{
    $actorId = (int)($remoteActor['id'] ?? 0);
    $activityUri = bms_activitypub_identifier_uri((string)($document['id'] ?? ''), true);
    $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($actorId < 1 || !is_string($payload) || strlen($payload) > bms_activitypub_publication_payload_max_bytes()) {
        throw new RuntimeException('The owner activity could not be queued safely.');
    }
    $inboxUrl = trim((string)($remoteActor['shared_inbox_url'] ?? '')) ?: trim((string)($remoteActor['inbox_url'] ?? ''));
    if (!bms_activitypub_delivery_url_is_structurally_safe($inboxUrl)) {
        throw new BmsActivityPubSecurityException('The owner activity target inbox is invalid.', 400);
    }
    $groups = [
        $inboxUrl => [
            'inbox_url' => $inboxUrl,
            'actor_ids' => [$actorId],
            'rfc9421' => bms_activitypub_actor_advertises_rfc9421($remoteActor),
        ],
    ];
    if ($fanoutFollowers) {
        foreach (bms_activitypub_publication_targets() as $target) {
            $targetUrl = (string)($target['inbox_url'] ?? '');
            if (!bms_activitypub_delivery_url_is_structurally_safe($targetUrl)) {
                continue;
            }
            if (!isset($groups[$targetUrl])) {
                $groups[$targetUrl] = ['inbox_url' => $targetUrl, 'actor_ids' => [], 'rfc9421' => true];
            }
            $groups[$targetUrl]['actor_ids'] = array_merge($groups[$targetUrl]['actor_ids'], (array)($target['actor_ids'] ?? []));
            $groups[$targetUrl]['rfc9421'] = !empty($groups[$targetUrl]['rfc9421']) && !empty($target['rfc9421']);
        }
    }
    $firstDeliveryId = 0;
    foreach ($groups as $target) {
        $targetActorIds = array_values(array_unique(array_filter(array_map('intval', (array)$target['actor_ids']))));
        sort($targetActorIds, SORT_NUMERIC);
        $actorJson = json_encode($targetActorIds, JSON_UNESCAPED_SLASHES);
        $targetUrl = (string)$target['inbox_url'];
        $dedupeKey = hash('sha256', "owner_activity\n" . $activityUri . "\n" . $targetUrl);
        $stmt = bms_db()->prepare('INSERT IGNORE INTO ' . bms_table('activitypub_deliveries') . ' (delivery_type, event_id, publication_generation, object_uri, activity_uri, payload_json, dedupe_key, inbox_url, recipient_actor_ids_json, signature_mode, status, attempt_count, available_at, last_attempt_at, delivered_at, http_status, last_error, created_at, updated_at) VALUES (\'owner_activity\', NULL, NULL, NULL, :activity_uri, :payload_json, :dedupe_key, :inbox_url, :actor_ids, :signature_mode, \'pending\', 0, UTC_TIMESTAMP(), NULL, NULL, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $stmt->execute([
            'activity_uri' => $activityUri,
            'payload_json' => $payload,
            'dedupe_key' => $dedupeKey,
            'inbox_url' => $targetUrl,
            'actor_ids' => is_string($actorJson) ? $actorJson : '[]',
            'signature_mode' => !empty($target['rfc9421']) ? 'rfc9421' : 'legacy',
        ]);
        $deliveryId = 0;
        if ($stmt->rowCount() > 0) {
            $deliveryId = (int)bms_db()->lastInsertId();
        } else {
            $select = bms_db()->prepare('SELECT id FROM ' . bms_table('activitypub_deliveries') . ' WHERE dedupe_key = :dedupe_key LIMIT 1');
            $select->execute(['dedupe_key' => $dedupeKey]);
            $deliveryId = (int)$select->fetchColumn();
        }
        if ($firstDeliveryId < 1) {
            $firstDeliveryId = $deliveryId;
        }
    }
    return $firstDeliveryId;
}

function bms_activitypub_owner_action_log_row(string $activityUri): ?array
{
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_owner_action_log') . ' WHERE activity_uri = :activity_uri LIMIT 1');
    $stmt->execute(['activity_uri' => $activityUri]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_owner_activity_document(string $activityUri): ?array
{
    $row = bms_activitypub_owner_action_log_row($activityUri);
    if (!is_array($row)) {
        $stmt = bms_db()->prepare('SELECT payload_json FROM ' . bms_table('activitypub_follow_log') . ' WHERE activity_uri = :activity_uri LIMIT 1');
        $stmt->execute(['activity_uri' => $activityUri]);
        $payload = $stmt->fetchColumn();
    } else {
        $payload = (string)$row['payload_json'];
    }
    if (!is_string($payload) || $payload === '') {
        return null;
    }
    $document = json_decode($payload, true, bms_activitypub_json_max_depth());
    return is_array($document) && !array_is_list($document) ? $document : null;
}

function bms_activitypub_owner_original_activity_document(string $activityUri, string $expectedType): array
{
    $activityUri = bms_activitypub_identifier_uri($activityUri, true);
    $expectedType = trim($expectedType);
    if (!in_array($expectedType, ['Follow', 'Like', 'Announce'], true)) {
        throw new InvalidArgumentException('Unsupported original owner activity type.');
    }
    $document = bms_activitypub_owner_activity_document($activityUri);
    if (!is_array($document)
        || !hash_equals($activityUri, (string)($document['id'] ?? ''))
        || !hash_equals($expectedType, (string)($document['type'] ?? ''))
        || !hash_equals(bms_activitypub_actor_url(), (string)($document['actor'] ?? ''))) {
        throw new RuntimeException('The original owner activity is unavailable or invalid.');
    }
    return $document;
}

function bms_activitypub_owner_reference_handle(string $reference): ?array
{
    $reference = trim($reference);
    if ($reference === '' || strlen($reference) > 2048 || preg_match('/[\x00-\x20\x7f]/', $reference) === 1) {
        throw new BmsActivityPubSecurityException('The remote actor reference is invalid.', 400);
    }

    if (str_starts_with(strtolower($reference), 'acct:')) {
        $reference = substr($reference, 5);
    }
    if (preg_match('/^@?([A-Za-z0-9._-]{1,190})@([^@]+)$/', $reference, $matches) === 1) {
        $username = (string)$matches[1];
        $domain = strtolower(rtrim((string)$matches[2], '.'));
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/', $domain) !== 1) {
            throw new BmsActivityPubSecurityException('The fediverse handle domain is invalid.', 400);
        }
        return ['username' => $username, 'domain' => $domain];
    }

    $parts = parse_url($reference);
    if (is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && trim((string)($parts['host'] ?? '')) !== '' && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['fragment'])) {
        $path = rawurldecode((string)($parts['path'] ?? ''));
        if (preg_match('~^/@([A-Za-z0-9._-]{1,190})/?$~', $path, $matches) === 1) {
            return ['username' => (string)$matches[1], 'domain' => strtolower(rtrim((string)$parts['host'], '.'))];
        }
        return null;
    }

    if (str_contains($reference, '@')) {
        throw new BmsActivityPubSecurityException('Use a fediverse handle such as @name@example.com.', 400);
    }
    return null;
}

function bms_activitypub_owner_webfinger_actor_uri(
    string $username,
    string $domain,
    ?callable $fetcher = null,
    ?callable $resolver = null
): string {
    $resource = 'acct:' . $username . '@' . $domain;
    $url = 'https://' . $domain . '/.well-known/webfinger?resource=' . rawurlencode($resource);
    bms_activitypub_validate_remote_url($url, $resolver, false);

    if ($fetcher !== null) {
        $document = $fetcher($url, $resource);
    } else {
        $response = bms_activitypub_http_request($url, [
            'method' => 'GET',
            'max_bytes' => min(65536, bms_activitypub_remote_document_max_bytes()),
            'max_redirects' => 2,
            'headers' => ['Accept: application/jrd+json, application/json;q=0.9'],
        ], null, $resolver);
        if ((int)($response['status'] ?? 0) !== 200) {
            throw new BmsActivityPubSecurityException('The fediverse handle was not available through WebFinger.', 502);
        }
        $contentTypes = $response['headers']['content-type'] ?? [];
        $contentType = strtolower(is_array($contentTypes) ? (string)end($contentTypes) : (string)$contentTypes);
        if ($contentType !== '' && !str_starts_with($contentType, 'application/jrd+json')
            && !str_starts_with($contentType, 'application/json')) {
            throw new BmsActivityPubSecurityException('The WebFinger response was not JSON.', 502);
        }
        $document = bms_activitypub_decode_json_document(
            (string)($response['body'] ?? ''),
            min(65536, bms_activitypub_remote_document_max_bytes())
        );
    }

    if (!is_array($document) || array_is_list($document)) {
        throw new BmsActivityPubSecurityException('The WebFinger document is invalid.', 502);
    }
    $subject = trim((string)($document['subject'] ?? ''));
    if ($subject !== '' && strcasecmp($subject, $resource) !== 0) {
        throw new BmsActivityPubSecurityException('The WebFinger subject does not match the requested handle.', 502);
    }
    $links = $document['links'] ?? [];
    if (!is_array($links)) {
        throw new BmsActivityPubSecurityException('The WebFinger document has no valid links.', 502);
    }
    foreach (array_slice($links, 0, 100) as $link) {
        if (!is_array($link) || array_is_list($link) || strcasecmp(trim((string)($link['rel'] ?? '')), 'self') !== 0) {
            continue;
        }
        $type = strtolower(trim((string)($link['type'] ?? '')));
        if ($type !== '' && !str_starts_with($type, 'application/activity+json')
            && !str_starts_with($type, 'application/ld+json')) {
            continue;
        }
        $actorUri = bms_activitypub_identifier_uri((string)($link['href'] ?? ''), false);
        bms_activitypub_validate_remote_url($actorUri, $resolver, false);
        return $actorUri;
    }
    throw new BmsActivityPubSecurityException('The WebFinger document did not provide an ActivityPub actor.', 502);
}

function bms_activitypub_resolve_owner_actor_reference(
    string $reference,
    ?callable $webfingerFetcher = null,
    ?callable $resolver = null
): string {
    $handle = bms_activitypub_owner_reference_handle($reference);
    if (is_array($handle)) {
        return bms_activitypub_owner_webfinger_actor_uri(
            (string)$handle['username'],
            (string)$handle['domain'],
            $webfingerFetcher,
            $resolver
        );
    }
    return bms_activitypub_identifier_uri($reference, false);
}

function bms_activitypub_following_row_by_actor(string $actorUri, bool $forUpdate = false): ?array
{
    $sql = 'SELECT f.*, a.preferred_username, a.display_name, a.inbox_url, a.shared_inbox_url, a.document_json FROM ' . bms_table('activitypub_following') . ' f INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = f.remote_actor_id WHERE f.actor_uri = :actor_uri LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = bms_db()->prepare($sql);
    $stmt->execute(['actor_uri' => $actorUri]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_follow_remote_actor(
    string $actorReference,
    ?callable $fetcher = null,
    ?callable $resolver = null,
    ?callable $webfingerFetcher = null
): array
{
    if (!bms_activitypub_enabled()) {
        throw new RuntimeException('ActivityPub is disabled.');
    }
    $referenceHandle = bms_activitypub_owner_reference_handle($actorReference);
    if (is_array($referenceHandle)
        && bms_activitypub_actor_is_blocked('https://' . (string)$referenceHandle['domain'] . '/')) {
        throw new BmsActivityPubSecurityException('The remote actor or domain is blocked.', 403);
    }
    $actorUri = bms_activitypub_resolve_owner_actor_reference($actorReference, $webfingerFetcher, $resolver);
    if (bms_activitypub_actor_is_blocked($actorUri)) {
        throw new BmsActivityPubSecurityException('The remote actor or domain is blocked.', 403);
    }
    $remoteActor = bms_activitypub_discover_remote_actor($actorUri, false, $fetcher, $resolver, true);
    $pdo = bms_db();
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        $existing = bms_activitypub_following_row_by_actor($actorUri, true);
        if (is_array($existing) && in_array((string)$existing['state'], ['pending', 'accepted'], true)) {
            if ($owns) {
                $pdo->commit();
            }
            return ['following' => $existing, 'duplicate' => true, 'delivery_id' => null];
        }
        $activityUri = bms_activitypub_owner_activity_url('follow');
        $document = bms_activitypub_owner_action_document('Follow', $activityUri, $actorUri, $actorUri);
        $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new RuntimeException('The Follow activity could not be encoded.');
        }
        if (is_array($existing)) {
            $stmt = $pdo->prepare("UPDATE " . bms_table('activitypub_following') . " SET remote_actor_id = :remote_actor_id, follow_activity_uri = :activity_uri, response_activity_uri = NULL, state = 'pending', state_changed_at = UTC_TIMESTAMP(), removed_at = NULL, last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id");
            $stmt->execute(['remote_actor_id' => (int)$remoteActor['id'], 'activity_uri' => $activityUri, 'id' => (int)$existing['id']]);
            $followingId = (int)$existing['id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO " . bms_table('activitypub_following') . " (remote_actor_id, actor_uri, follow_activity_uri, response_activity_uri, state, state_changed_at, removed_at, last_error, created_at, updated_at) VALUES (:remote_actor_id, :actor_uri, :activity_uri, NULL, 'pending', UTC_TIMESTAMP(), NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $stmt->execute(['remote_actor_id' => (int)$remoteActor['id'], 'actor_uri' => $actorUri, 'activity_uri' => $activityUri]);
            $followingId = (int)$pdo->lastInsertId();
        }
        $log = $pdo->prepare("INSERT INTO " . bms_table('activitypub_follow_log') . " (following_id, remote_actor_id, actor_uri, activity_uri, activity_type, object_activity_uri, response_activity_uri, state, payload_json, created_at, updated_at) VALUES (:following_id, :remote_actor_id, :actor_uri, :activity_uri, 'Follow', NULL, NULL, 'pending', :payload_json, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $log->execute(['following_id' => $followingId, 'remote_actor_id' => (int)$remoteActor['id'], 'actor_uri' => $actorUri, 'activity_uri' => $activityUri, 'payload_json' => $payload]);
        $deliveryId = bms_activitypub_queue_owner_action($remoteActor, $document);
        $following = bms_activitypub_following_row_by_actor($actorUri, false);
        if ($owns) {
            $pdo->commit();
        }
        return ['following' => $following, 'duplicate' => false, 'delivery_id' => $deliveryId];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_activitypub_unfollow_remote_actor(int $followingId, ?callable $fetcher = null, ?callable $resolver = null): array
{
    if (!bms_activitypub_enabled()) {
        throw new RuntimeException('ActivityPub is disabled.');
    }
    $lookup = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_following') . ' WHERE id = :id LIMIT 1');
    $lookup->execute(['id' => $followingId]);
    $lookupRow = $lookup->fetch();
    if (!is_array($lookupRow)) {
        throw new RuntimeException('The Following relationship was not found.');
    }
    if ((string)$lookupRow['state'] === 'removed') {
        return ['following' => $lookupRow, 'duplicate' => true, 'delivery_id' => null];
    }
    $actorUri = (string)$lookupRow['actor_uri'];
    $freshActor = bms_activitypub_discover_remote_actor($actorUri, false, $fetcher, $resolver, true);
    $pdo = bms_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT f.*, a.inbox_url, a.shared_inbox_url, a.document_json FROM ' . bms_table('activitypub_following') . ' f INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = f.remote_actor_id WHERE f.id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $followingId]);
        $following = $stmt->fetch();
        if (!is_array($following)) {
            throw new RuntimeException('The Following relationship was not found.');
        }
        if ((string)$following['state'] === 'removed') {
            $pdo->commit();
            return ['following' => $following, 'duplicate' => true, 'delivery_id' => null];
        }
        $actorUri = (string)$following['actor_uri'];
        if (!hash_equals($actorUri, (string)$freshActor['actor_uri'])) {
            throw new BmsActivityPubSecurityException('The refreshed actor no longer matches the Following relationship.', 403);
        }
        $activityUri = bms_activitypub_owner_activity_url('undo-follow');
        $originalActivityUri = (string)$following['follow_activity_uri'];
        $originalDocument = bms_activitypub_owner_original_activity_document($originalActivityUri, 'Follow');
        $document = bms_activitypub_owner_action_document('Undo', $activityUri, $actorUri, $actorUri, $originalDocument);
        $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new RuntimeException('The Undo Follow activity could not be encoded.');
        }
        $log = $pdo->prepare("INSERT INTO " . bms_table('activitypub_follow_log') . " (following_id, remote_actor_id, actor_uri, activity_uri, activity_type, object_activity_uri, response_activity_uri, state, payload_json, created_at, updated_at) VALUES (:following_id, :remote_actor_id, :actor_uri, :activity_uri, 'Undo', :object_activity_uri, NULL, 'queued', :payload_json, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $log->execute(['following_id' => $followingId, 'remote_actor_id' => (int)$following['remote_actor_id'], 'actor_uri' => $actorUri, 'activity_uri' => $activityUri, 'object_activity_uri' => (string)$following['follow_activity_uri'], 'payload_json' => $payload]);
        $deliveryId = bms_activitypub_queue_owner_action($freshActor, $document);
        $update = $pdo->prepare("UPDATE " . bms_table('activitypub_following') . " SET state = 'removed', state_changed_at = UTC_TIMESTAMP(), removed_at = UTC_TIMESTAMP(), last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id");
        $update->execute(['id' => $followingId]);
        $pdo->commit();
        $following['state'] = 'removed';
        return ['following' => $following, 'duplicate' => false, 'delivery_id' => $deliveryId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_activitypub_following_rows(int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $stmt = bms_db()->query('SELECT f.*, a.preferred_username, a.display_name, a.inbox_url, a.shared_inbox_url FROM ' . bms_table('activitypub_following') . ' f INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = f.remote_actor_id ORDER BY f.updated_at DESC, f.id DESC LIMIT ' . $limit);
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

function bms_activitypub_remote_media_items(mixed $value): array
{
    $items = is_array($value) && array_is_list($value) ? $value : (is_array($value) ? [$value] : []);
    $media = [];
    foreach (array_slice($items, 0, 8) as $item) {
        if (!is_array($item) || array_is_list($item)) {
            continue;
        }
        $urlValue = $item['url'] ?? '';
        if (is_array($urlValue)) {
            $urlValue = is_string($urlValue['href'] ?? null) ? $urlValue['href'] : ($urlValue[0] ?? '');
            if (is_array($urlValue)) {
                $urlValue = $urlValue['href'] ?? '';
            }
        }
        $url = is_string($urlValue) ? bms_activitypub_remote_link_url($urlValue) : '';
        if ($url === '') {
            continue;
        }
        $mediaType = strtolower(trim((string)($item['mediaType'] ?? '')));
        $allowedTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
            'video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg', 'audio/wav',
        ];
        if (!in_array($mediaType, $allowedTypes, true)) {
            continue;
        }
        $kind = str_starts_with($mediaType, 'image/') ? 'image'
            : (str_starts_with($mediaType, 'video/') ? 'video' : 'audio');
        $media[] = [
            'kind' => $kind,
            'media_type' => $mediaType,
            'url' => $url,
            'alt_text' => bms_activitypub_remote_plain_text((string)($item['name'] ?? ''), 1000),
            'width' => max(0, min(10000, (int)($item['width'] ?? 0))),
            'height' => max(0, min(10000, (int)($item['height'] ?? 0))),
        ];
    }
    return $media;
}

function bms_activitypub_remote_note_data(array $note, string $actorUri): array
{
    if (strcasecmp(trim((string)($note['type'] ?? '')), 'Note') !== 0) {
        throw new BmsActivityPubSecurityException('Only remote Notes are eligible for the owner inbox.', 400);
    }
    $objectUri = bms_activitypub_identifier_uri((string)($note['id'] ?? ''), false);
    if (!hash_equals($actorUri, bms_activitypub_note_actor($note))) {
        throw new BmsActivityPubSecurityException('The remote actor does not own the Note.', 403);
    }
    $content = bms_activitypub_sanitize_remote_html(is_string($note['content'] ?? null) ? (string)$note['content'] : '');
    $humanUrl = bms_activitypub_remote_link_url(is_string($note['url'] ?? null) ? (string)$note['url'] : $objectUri);
    $metadata = [
        'inReplyTo' => is_string($note['inReplyTo'] ?? null) ? bms_activitypub_remote_link_url((string)$note['inReplyTo']) : '',
        'sensitive' => !empty($note['sensitive']),
        'summary' => bms_activitypub_remote_plain_text(is_string($note['summary'] ?? null) ? (string)$note['summary'] : '', 1000),
        'media' => bms_activitypub_remote_media_items($note['attachment'] ?? []),
    ];
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return [
        'object_uri' => $objectUri,
        'content_html' => (string)$content['html'],
        'content_text' => (string)$content['text'],
        'content_hash' => hash('sha256', (string)$content['html']),
        'human_url' => $humanUrl !== '' ? $humanUrl : null,
        'metadata_json' => is_string($metadataJson) ? $metadataJson : '{}',
        'remote_published_at' => bms_activitypub_remote_datetime($note['published'] ?? null),
        'remote_updated_at' => bms_activitypub_remote_datetime($note['updated'] ?? null),
    ];
}

function bms_activitypub_store_remote_object(array $note, array $remoteActor, string $activityUri = ''): array
{
    $actorUri = (string)$remoteActor['actor_uri'];
    if (bms_activitypub_actor_is_blocked($actorUri)) {
        throw new BmsActivityPubSecurityException('The remote actor or domain is blocked.', 403);
    }
    $data = bms_activitypub_remote_note_data($note, $actorUri);
    $activityUri = $activityUri !== '' ? bms_activitypub_identifier_uri($activityUri, true) : null;
    $pdo = bms_db();
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        $existing = $pdo->prepare('SELECT * FROM ' . bms_table('activitypub_remote_objects') . ' WHERE object_uri = :object_uri LIMIT 1 FOR UPDATE');
        $existing->execute(['object_uri' => $data['object_uri']]);
        $existingRow = $existing->fetch();
        if (is_array($existingRow)) {
            if (!hash_equals((string)$existingRow['actor_uri'], $actorUri)) {
                throw new BmsActivityPubSecurityException('A remote object cannot change owning actors.', 403);
            }
            if ((string)$existingRow['lifecycle_state'] === 'deleted') {
                throw new BmsActivityPubSecurityException('A deleted remote object cannot be resurrected.', 410);
            }
            if ((string)$existingRow['lifecycle_state'] === 'blocked') {
                throw new BmsActivityPubSecurityException('A blocked remote object cannot be restored.', 403);
            }
        }
    $sql = 'INSERT INTO ' . bms_table('activitypub_remote_objects') . ' (remote_actor_id, actor_uri, object_uri, object_type, source_activity_uri, last_activity_uri, content_html, content_text, content_hash, human_url, metadata_json, lifecycle_state, remote_published_at, remote_updated_at, fetched_at, expires_at, deleted_at, created_at, updated_at) VALUES (:remote_actor_id, :actor_uri, :object_uri, \'Note\', :source_activity_uri, :last_activity_uri, :content_html, :content_text, :content_hash, :human_url, :metadata_json, \'active\', :remote_published_at, :remote_updated_at, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE remote_actor_id = VALUES(remote_actor_id), actor_uri = VALUES(actor_uri), last_activity_uri = VALUES(last_activity_uri), content_html = VALUES(content_html), content_text = VALUES(content_text), content_hash = VALUES(content_hash), human_url = VALUES(human_url), metadata_json = VALUES(metadata_json), lifecycle_state = \'active\', remote_updated_at = VALUES(remote_updated_at), fetched_at = UTC_TIMESTAMP(), expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), deleted_at = NULL, updated_at = UTC_TIMESTAMP()';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'remote_actor_id' => (int)$remoteActor['id'], 'actor_uri' => $actorUri, 'object_uri' => $data['object_uri'],
        'source_activity_uri' => $activityUri, 'last_activity_uri' => $activityUri,
        'content_html' => $data['content_html'], 'content_text' => $data['content_text'], 'content_hash' => $data['content_hash'],
        'human_url' => $data['human_url'], 'metadata_json' => $data['metadata_json'],
        'remote_published_at' => $data['remote_published_at'], 'remote_updated_at' => $data['remote_updated_at'],
    ]);
    $select = $pdo->prepare('SELECT * FROM ' . bms_table('activitypub_remote_objects') . ' WHERE object_uri = :object_uri LIMIT 1');
    $select->execute(['object_uri' => $data['object_uri']]);
    $row = $select->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('The remote object cache write did not persist.');
    }
    if ($owns) {
        $pdo->commit();
    }
    return $row;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_activitypub_fetch_remote_object(string $objectUri, bool $force = false, ?callable $fetcher = null, ?callable $resolver = null): array
{
    $objectUri = bms_activitypub_identifier_uri($objectUri, false);
    bms_activitypub_validate_remote_url($objectUri, $resolver, false);
    $known = bms_db()->prepare('SELECT lifecycle_state FROM ' . bms_table('activitypub_remote_objects') . ' WHERE object_uri = :object_uri LIMIT 1');
    $known->execute(['object_uri' => $objectUri]);
    $knownState = $known->fetchColumn();
    if ($knownState === 'deleted') {
        throw new BmsActivityPubSecurityException('The remote object is permanently deleted in the local cache.', 410);
    }
    if ($knownState === 'blocked') {
        throw new BmsActivityPubSecurityException('The remote object is blocked.', 403);
    }
    if (!$force) {
        $stmt = bms_db()->prepare("SELECT * FROM " . bms_table('activitypub_remote_objects') . " WHERE object_uri = :object_uri AND lifecycle_state = 'active' AND expires_at > UTC_TIMESTAMP() LIMIT 1");
        $stmt->execute(['object_uri' => $objectUri]);
        $cached = $stmt->fetch();
        if (is_array($cached) && !bms_activitypub_actor_is_blocked((string)$cached['actor_uri'])) {
            return $cached;
        }
    }
    $fetched = $fetcher !== null ? $fetcher($objectUri) : bms_activitypub_fetch_json($objectUri, null, $resolver);
    $note = is_array($fetched) && is_array($fetched['document'] ?? null) ? $fetched['document'] : $fetched;
    if (!is_array($note) || array_is_list($note) || !hash_equals($objectUri, bms_activitypub_identifier_uri((string)($note['id'] ?? ''), false))) {
        throw new BmsActivityPubSecurityException('The remote object document does not match the requested object.', 502);
    }
    $actorUri = bms_activitypub_note_actor($note);
    $actor = bms_activitypub_discover_remote_actor($actorUri, false, $fetcher, $resolver, true);
    return bms_activitypub_store_remote_object($note, $actor);
}

function bms_activitypub_process_followed_note_create(array $activity, array $remoteActor): string
{
    $actorUri = (string)$remoteActor['actor_uri'];
    $following = bms_activitypub_following_row_by_actor($actorUri, false);
    if (!is_array($following) || (string)$following['state'] !== 'accepted') {
        return 'remote_note_not_followed';
    }
    $note = $activity['object'] ?? null;
    if (!is_array($note) || array_is_list($note)) {
        throw new BmsActivityPubSecurityException('Create must contain a remote Note.', 400);
    }
    $objectUri = bms_activitypub_identifier_uri((string)($note['id'] ?? ''), false);
    $existing = bms_db()->prepare('SELECT actor_uri, lifecycle_state FROM ' . bms_table('activitypub_remote_objects') . ' WHERE object_uri = :object_uri LIMIT 1');
    $existing->execute(['object_uri' => $objectUri]);
    $existingRow = $existing->fetch();
    if (is_array($existingRow)) {
        if (!hash_equals((string)$existingRow['actor_uri'], $actorUri)) {
            throw new BmsActivityPubSecurityException('A remote object cannot change owning actors.', 403);
        }
        return (string)$existingRow['lifecycle_state'] === 'deleted' ? 'remote_note_create_after_delete' : 'remote_note_create_duplicate';
    }
    bms_activitypub_store_remote_object($note, $remoteActor, (string)$activity['id']);
    return 'remote_note_cached';
}

function bms_activitypub_process_followed_note_update(array $activity, array $remoteActor): string
{
    $note = $activity['object'] ?? null;
    if (!is_array($note) || array_is_list($note)) {
        throw new BmsActivityPubSecurityException('Update must contain a remote Note.', 400);
    }
    $objectUri = bms_activitypub_identifier_uri((string)($note['id'] ?? ''), false);
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_remote_objects') . ' WHERE object_uri = :object_uri LIMIT 1 FOR UPDATE');
    $stmt->execute(['object_uri' => $objectUri]);
    $existing = $stmt->fetch();
    if (!is_array($existing)) {
        return 'remote_note_update_unmatched';
    }
    if (!hash_equals((string)$existing['actor_uri'], (string)$remoteActor['actor_uri'])) {
        throw new BmsActivityPubSecurityException('A remote actor cannot update another actor\'s cached Note.', 403);
    }
    if ((string)$existing['lifecycle_state'] === 'deleted') {
        return 'remote_note_update_after_delete';
    }
    if ((string)$existing['lifecycle_state'] !== 'active') {
        throw new BmsActivityPubSecurityException('The cached remote Note is not eligible for Update.', 403);
    }
    bms_activitypub_store_remote_object($note, $remoteActor, (string)$activity['id']);
    return 'remote_note_updated';
}

function bms_activitypub_process_followed_note_delete(array $activity, array $remoteActor): string
{
    $objectUri = bms_activitypub_identifier_uri(bms_activitypub_target_object_id($activity['object'] ?? null), false);
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_remote_objects') . ' WHERE object_uri = :object_uri LIMIT 1 FOR UPDATE');
    $stmt->execute(['object_uri' => $objectUri]);
    $existing = $stmt->fetch();
    if (!is_array($existing)) {
        return 'remote_note_delete_unmatched';
    }
    if (!hash_equals((string)$existing['actor_uri'], (string)$remoteActor['actor_uri'])) {
        throw new BmsActivityPubSecurityException('A remote actor cannot delete another actor\'s cached Note.', 403);
    }
    if ((string)$existing['lifecycle_state'] === 'deleted') {
        return 'remote_note_delete_duplicate';
    }
    $update = bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_objects') . " SET lifecycle_state = 'deleted', last_activity_uri = :activity_uri, content_html = '', content_text = '', deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id");
    $update->execute(['activity_uri' => (string)$activity['id'], 'id' => (int)$existing['id']]);
    return 'remote_note_deleted';
}

function bms_activitypub_remote_inbox_rows(int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $sql = 'SELECT o.*, a.preferred_username, a.display_name, a.document_json, i.id AS like_interaction_id, i.state AS like_state, i.last_error AS like_last_error, n.id AS announce_interaction_id, n.state AS announce_state, n.last_error AS announce_last_error FROM ' . bms_table('activitypub_remote_objects') . ' o INNER JOIN ' . bms_table('activitypub_following') . " f ON f.remote_actor_id = o.remote_actor_id AND f.state = 'accepted'" . ' INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = o.remote_actor_id LEFT JOIN ' . bms_table('activitypub_owner_interactions') . " i ON i.remote_actor_id = o.remote_actor_id AND i.target_object_uri = o.object_uri AND i.interaction_type = 'Like'" . ' LEFT JOIN ' . bms_table('activitypub_owner_interactions') . " n ON n.remote_actor_id = o.remote_actor_id AND n.target_object_uri = o.object_uri AND n.interaction_type = 'Announce'" . " WHERE o.lifecycle_state = 'active' ORDER BY COALESCE(o.remote_published_at, o.created_at) DESC, o.id DESC LIMIT " . $limit;
    $stmt = bms_db()->query($sql);
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    return array_values(array_filter($rows, static fn(array $row): bool => !bms_activitypub_actor_is_blocked((string)$row['actor_uri'])));
}

function bms_activitypub_owner_interaction_key(string $type, string $targetUri): string
{
    return hash('sha256', strtolower($type) . "\n" . $targetUri);
}

function bms_activitypub_owner_interact(string $type, string $targetUri, ?callable $fetcher = null, ?callable $resolver = null): array
{
    if (!bms_activitypub_enabled()) {
        throw new RuntimeException('ActivityPub is disabled.');
    }
    if (!in_array($type, ['Like', 'Announce'], true)) {
        throw new InvalidArgumentException('Unsupported owner interaction.');
    }
    $object = bms_activitypub_fetch_remote_object($targetUri, false, $fetcher, $resolver);
    $actor = bms_activitypub_discover_remote_actor((string)$object['actor_uri'], false, $fetcher, $resolver, true);
    if (bms_activitypub_actor_is_blocked((string)$actor['actor_uri'])) {
        throw new BmsActivityPubSecurityException('The target actor is unavailable or blocked.', 403);
    }
    $semanticKey = bms_activitypub_owner_interaction_key($type, (string)$object['object_uri']);
    $pdo = bms_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM ' . bms_table('activitypub_owner_interactions') . ' WHERE semantic_key = :semantic_key LIMIT 1 FOR UPDATE');
        $stmt->execute(['semantic_key' => $semanticKey]);
        $existing = $stmt->fetch();
        if (is_array($existing) && (string)$existing['state'] === 'active') {
            $pdo->commit();
            return ['interaction' => $existing, 'duplicate' => true, 'delivery_id' => null];
        }
        $activityUri = bms_activitypub_owner_activity_url(strtolower($type));
        $public = $type === 'Announce';
        $document = bms_activitypub_owner_action_document($type, $activityUri, (string)$actor['actor_uri'], (string)$object['object_uri'], '', $public);
        $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new RuntimeException('The owner interaction could not be encoded.');
        }
        if (is_array($existing)) {
            $update = $pdo->prepare("UPDATE " . bms_table('activitypub_owner_interactions') . " SET remote_actor_id = :remote_actor_id, actor_uri = :actor_uri, current_activity_uri = :activity_uri, state = 'active', activated_at = UTC_TIMESTAMP(), undone_at = NULL, last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id");
            $update->execute(['remote_actor_id' => (int)$actor['id'], 'actor_uri' => (string)$actor['actor_uri'], 'activity_uri' => $activityUri, 'id' => (int)$existing['id']]);
            $interactionId = (int)$existing['id'];
        } else {
            $insert = $pdo->prepare("INSERT INTO " . bms_table('activitypub_owner_interactions') . " (semantic_key, interaction_type, remote_actor_id, actor_uri, target_object_uri, current_activity_uri, state, activated_at, undone_at, last_error, created_at, updated_at) VALUES (:semantic_key, :interaction_type, :remote_actor_id, :actor_uri, :target_uri, :activity_uri, 'active', UTC_TIMESTAMP(), NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $insert->execute(['semantic_key' => $semanticKey, 'interaction_type' => $type, 'remote_actor_id' => (int)$actor['id'], 'actor_uri' => (string)$actor['actor_uri'], 'target_uri' => (string)$object['object_uri'], 'activity_uri' => $activityUri]);
            $interactionId = (int)$pdo->lastInsertId();
        }
        $log = $pdo->prepare("INSERT INTO " . bms_table('activitypub_owner_action_log') . " (owner_interaction_id, remote_actor_id, actor_uri, target_object_uri, activity_uri, activity_type, object_activity_uri, payload_json, state, created_at, updated_at) VALUES (:interaction_id, :remote_actor_id, :actor_uri, :target_uri, :activity_uri, :activity_type, NULL, :payload_json, 'queued', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $log->execute(['interaction_id' => $interactionId, 'remote_actor_id' => (int)$actor['id'], 'actor_uri' => (string)$actor['actor_uri'], 'target_uri' => (string)$object['object_uri'], 'activity_uri' => $activityUri, 'activity_type' => $type, 'payload_json' => $payload]);
        $deliveryId = bms_activitypub_queue_owner_action($actor, $document, $public);
        $pdo->commit();
        $existing = $pdo->prepare('SELECT * FROM ' . bms_table('activitypub_owner_interactions') . ' WHERE id = :id LIMIT 1');
        $existing->execute(['id' => $interactionId]);
        return ['interaction' => $existing->fetch(), 'duplicate' => false, 'delivery_id' => $deliveryId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_activitypub_owner_undo_interaction(int $interactionId, ?callable $fetcher = null, ?callable $resolver = null): array
{
    if (!bms_activitypub_enabled()) {
        throw new RuntimeException('ActivityPub is disabled.');
    }
    $lookup = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_owner_interactions') . ' WHERE id = :id LIMIT 1');
    $lookup->execute(['id' => $interactionId]);
    $lookupRow = $lookup->fetch();
    if (!is_array($lookupRow)) {
        throw new RuntimeException('The owner interaction was not found.');
    }
    if ((string)$lookupRow['state'] === 'undone') {
        return ['interaction' => $lookupRow, 'duplicate' => true, 'delivery_id' => null];
    }
    $actorUri = (string)$lookupRow['actor_uri'];
    $freshActor = bms_activitypub_discover_remote_actor($actorUri, false, $fetcher, $resolver, true);
    $pdo = bms_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT i.*, a.inbox_url, a.shared_inbox_url, a.document_json FROM ' . bms_table('activitypub_owner_interactions') . ' i INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = i.remote_actor_id WHERE i.id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $interactionId]);
        $interaction = $stmt->fetch();
        if (!is_array($interaction)) {
            throw new RuntimeException('The owner interaction was not found.');
        }
        if ((string)$interaction['state'] === 'undone') {
            $pdo->commit();
            return ['interaction' => $interaction, 'duplicate' => true, 'delivery_id' => null];
        }
        if ((string)$interaction['state'] !== 'active') {
            throw new RuntimeException('The owner interaction is not active.');
        }
        $activityUri = bms_activitypub_owner_activity_url('undo-' . strtolower((string)$interaction['interaction_type']));
        $public = (string)$interaction['interaction_type'] === 'Announce';
        $originalActivityUri = (string)$interaction['current_activity_uri'];
        $originalDocument = bms_activitypub_owner_original_activity_document(
            $originalActivityUri,
            (string)$interaction['interaction_type']
        );
        $document = bms_activitypub_owner_action_document('Undo', $activityUri, (string)$interaction['actor_uri'], (string)$interaction['target_object_uri'], $originalDocument, $public);
        $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new RuntimeException('The owner Undo could not be encoded.');
        }
        $log = $pdo->prepare("INSERT INTO " . bms_table('activitypub_owner_action_log') . " (owner_interaction_id, remote_actor_id, actor_uri, target_object_uri, activity_uri, activity_type, object_activity_uri, payload_json, state, created_at, updated_at) VALUES (:interaction_id, :remote_actor_id, :actor_uri, :target_uri, :activity_uri, 'Undo', :object_activity_uri, :payload_json, 'queued', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $log->execute(['interaction_id' => $interactionId, 'remote_actor_id' => (int)$interaction['remote_actor_id'], 'actor_uri' => (string)$interaction['actor_uri'], 'target_uri' => (string)$interaction['target_object_uri'], 'activity_uri' => $activityUri, 'object_activity_uri' => (string)$interaction['current_activity_uri'], 'payload_json' => $payload]);
        if (!hash_equals((string)$interaction['actor_uri'], (string)$freshActor['actor_uri'])) {
            throw new BmsActivityPubSecurityException('The refreshed actor no longer owns the target interaction.', 403);
        }
        $deliveryId = bms_activitypub_queue_owner_action($freshActor, $document, $public);
        $update = $pdo->prepare("UPDATE " . bms_table('activitypub_owner_interactions') . " SET state = 'undone', undone_at = UTC_TIMESTAMP(), last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id");
        $update->execute(['id' => $interactionId]);
        $pdo->commit();
        $interaction['state'] = 'undone';
        return ['interaction' => $interaction, 'duplicate' => false, 'delivery_id' => $deliveryId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_activitypub_create_owner_reply_draft(string $targetUri, string $body, string $title = ''): array
{
    if (!bms_activitypub_enabled()) {
        throw new RuntimeException('ActivityPub is disabled.');
    }
    $object = bms_activitypub_fetch_remote_object($targetUri, false);
    if (bms_activitypub_actor_is_blocked((string)$object['actor_uri'])) {
        throw new BmsActivityPubSecurityException('The target actor is blocked.', 403);
    }
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if ($body === '' || strlen($body) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('The reply body is empty or too large.');
    }
    $title = bms_activitypub_remote_plain_text($title, 255);
    if ($title === '') {
        $title = 'Reply to ' . (trim((string)$object['content_text']) !== '' ? bms_text_substr((string)$object['content_text'], 0, 80) : 'remote note');
    }
    $baseSlug = bms_slugify($title) ?: 'remote-reply';
    $slug = $baseSlug;
    for ($suffix = 2; bms_find_post_by_slug_status($slug, 'draft', 'stream') || bms_find_post_by_slug_status($slug, 'published', 'stream') || bms_find_post_by_slug_status($slug, 'scheduled', 'stream'); $suffix++) {
        $slug = bms_text_substr($baseSlug, 0, 175) . '-' . $suffix;
    }
    $raw = bms_build_markdown_document([
        'title' => $title, 'slug' => $slug, 'status' => 'draft', 'content_type' => 'stream',
        'date' => gmdate('Y-m-d'), 'description' => '', 'category' => 'Stream', 'tags' => [],
        'stream_created_at' => gmdate('Y-m-d H:i:s'),
    ], $body);
    $page = bms_parse_markdown_string($raw);
    $postId = bms_upsert_database_content($page, 'drafts', $slug . '.md', bms_current_user_id());
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_reply_targets') . ' (post_id, remote_object_id, remote_actor_id, actor_uri, in_reply_to_uri, created_at, updated_at) VALUES (:post_id, :remote_object_id, :remote_actor_id, :actor_uri, :in_reply_to_uri, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $stmt->execute(['post_id' => $postId, 'remote_object_id' => (int)$object['id'], 'remote_actor_id' => (int)$object['remote_actor_id'], 'actor_uri' => (string)$object['actor_uri'], 'in_reply_to_uri' => (string)$object['object_uri']]);
    return ['post_id' => $postId, 'slug' => $slug, 'filename' => $slug . '.md', 'in_reply_to_uri' => (string)$object['object_uri']];
}

function bms_activitypub_reply_target_for_post(int $postId): ?array
{
    if ($postId < 1 || !function_exists('bms_is_installed') || !bms_is_installed()) {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT t.*, a.inbox_url, a.shared_inbox_url, a.document_json FROM ' . bms_table('activitypub_reply_targets') . ' t INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = t.remote_actor_id WHERE t.post_id = :post_id LIMIT 1');
    $stmt->execute(['post_id' => $postId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_deliver_owner_row(array $delivery, array $key, ?callable $transport = null, ?callable $resolver = null): array
{
    $deliveryType = (string)($delivery['delivery_type'] ?? '');
    if (!in_array($deliveryType, ['owner_activity', 'actor_delete'], true) || $delivery['event_id'] !== null) {
        throw new RuntimeException('The delivery is not an owner activity.');
    }
    $payload = (string)($delivery['payload_json'] ?? '');
    $document = bms_activitypub_decode_json_document($payload, bms_activitypub_publication_payload_max_bytes());
    $allowedTypes = $deliveryType === 'actor_delete' ? ['Delete'] : ['Follow', 'Undo', 'Like', 'Announce'];
    if (!in_array((string)($document['type'] ?? ''), $allowedTypes, true)) {
        throw new RuntimeException('The owner activity type is not deliverable.');
    }
    if (!hash_equals((string)($document['id'] ?? ''), (string)$delivery['activity_uri'])) {
        throw new RuntimeException('The owner delivery payload identity changed.');
    }
    if ($deliveryType === 'actor_delete') {
        $retirement = bms_activitypub_actor_retirement();
        $objectUri = is_array($document['object'] ?? null)
            ? trim((string)($document['object']['id'] ?? ''))
            : trim((string)($document['object'] ?? ''));
        if (!is_array($retirement)
            || !hash_equals((string)$retirement['delete_activity_uri'], (string)$delivery['activity_uri'])
            || !hash_equals((string)$retirement['actor_uri'], $objectUri)) {
            throw new RuntimeException('The Actor Delete delivery does not match the durable retired identity.');
        }
    }
    $url = (string)$delivery['inbox_url'];
    $mode = (string)($delivery['signature_mode'] ?? 'legacy') === 'rfc9421' ? 'rfc9421' : 'legacy';
    $send = static function (string $signatureMode) use ($url, $payload, $key, $transport, $resolver): array {
        return bms_activitypub_http_request($url, [
            'method' => 'POST', 'body' => $payload,
            'headers' => bms_activitypub_sign_outbound_request('POST', $url, $payload, $key, $signatureMode),
            'max_bytes' => 262144, 'max_redirects' => 0,
        ], $transport, $resolver);
    };
    $response = $send($mode);
    if ($mode === 'rfc9421' && in_array((int)($response['status'] ?? 0), [400, 401, 403], true)) {
        $response = $send('legacy');
        $response['signature_fallback'] = 'legacy';
    }
    return $response;
}

function bms_activitypub_owner_delivery_has_blocked_actor(array $delivery): bool
{
    $actorIds = json_decode((string)($delivery['recipient_actor_ids_json'] ?? '[]'), true);
    $actorIds = is_array($actorIds) ? array_values(array_unique(array_filter(array_map('intval', $actorIds)))) : [];
    if ($actorIds === []) {
        return true;
    }
    $placeholders = implode(',', array_fill(0, count($actorIds), '?'));
    $stmt = bms_db()->prepare('SELECT actor_uri FROM ' . bms_table('activitypub_remote_actors') . ' WHERE id IN (' . $placeholders . ')');
    $stmt->execute($actorIds);
    $actorUris = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (count($actorUris) !== count($actorIds)) {
        return true;
    }
    foreach ($actorUris as $actorUri) {
        if (bms_activitypub_actor_is_blocked((string)$actorUri)) {
            return true;
        }
    }
    return false;
}

function bms_activitypub_mark_owner_delivery_result(array $delivery, string $state, string $error = ''): void
{
    $activityUri = (string)$delivery['activity_uri'];
    $counts = bms_db()->prepare("SELECT SUM(CASE WHEN status IN ('pending', 'retry', 'processing') THEN 1 ELSE 0 END) AS unfinished_count, SUM(CASE WHEN status = 'dead' THEN 1 ELSE 0 END) AS dead_count, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count, MAX(CASE WHEN status IN ('dead', 'cancelled') THEN last_error ELSE NULL END) AS terminal_error FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'owner_activity' AND event_id IS NULL AND activity_uri = :activity_uri");
    $counts->execute(['activity_uri' => $activityUri]);
    $aggregate = $counts->fetch() ?: [];
    $unfinished = (int)($aggregate['unfinished_count'] ?? 0);
    $dead = (int)($aggregate['dead_count'] ?? 0);
    $cancelled = (int)($aggregate['cancelled_count'] ?? 0);
    $delivered = (int)($aggregate['delivered_count'] ?? 0);
    $state = $unfinished > 0
        ? 'queued'
        : ($dead > 0
            ? ($delivered > 0 ? 'partial_failed' : 'failed')
            : ($cancelled > 0 ? ($delivered > 0 ? 'partial_cancelled' : 'cancelled') : 'delivered'));
    $error = trim((string)($aggregate['terminal_error'] ?? $error));
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('activitypub_owner_action_log') . ' SET state = :state, updated_at = UTC_TIMESTAMP() WHERE activity_uri = :activity_uri');
    $stmt->execute(['state' => $state, 'activity_uri' => $activityUri]);
    $follow = bms_db()->prepare('UPDATE ' . bms_table('activitypub_follow_log') . ' SET state = :state, updated_at = UTC_TIMESTAMP() WHERE activity_uri = :activity_uri');
    $follow->execute(['state' => $state, 'activity_uri' => $activityUri]);
    $interaction = bms_db()->prepare('UPDATE ' . bms_table('activitypub_owner_interactions') . ' i INNER JOIN ' . bms_table('activitypub_owner_action_log') . ' l ON l.owner_interaction_id = i.id SET i.last_error = :last_error, i.updated_at = UTC_TIMESTAMP() WHERE l.activity_uri = :activity_uri');
    $interaction->execute(['last_error' => in_array($state, ['failed', 'partial_failed', 'cancelled', 'partial_cancelled'], true) ? bms_text_substr($error, 0, 1000) : null, 'activity_uri' => $activityUri]);
    if (in_array($state, ['failed', 'cancelled'], true)) {
        $following = bms_db()->prepare("UPDATE " . bms_table('activitypub_following') . " f INNER JOIN " . bms_table('activitypub_follow_log') . " l ON l.following_id = f.id SET f.state = CASE WHEN l.activity_type = 'Follow' AND f.follow_activity_uri = l.activity_uri THEN 'failed' ELSE f.state END, f.state_changed_at = UTC_TIMESTAMP(), f.last_error = :last_error, f.updated_at = UTC_TIMESTAMP() WHERE l.activity_uri = :activity_uri");
        $following->execute(['last_error' => bms_text_substr($error, 0, 1000), 'activity_uri' => $activityUri]);
    }
}

function bms_activitypub_run_owner_deliveries(int $limit = 20, ?callable $transport = null, ?callable $resolver = null): array
{
    $retirement = bms_activitypub_actor_retirement();
    $retiredDelivery = is_array($retirement);
    if (!bms_activitypub_runs_deliveries() && !$retiredDelivery) {
        return ['ok' => true, 'count' => 0, 'message' => 'ActivityPub owner activity delivery is paused or suspended.'];
    }
    $key = bms_activitypub_active_signing_key(true);
    if (!is_array($key)) {
        return ['ok' => false, 'count' => 0, 'message' => 'Owner activities are waiting for an active signing key.'];
    }
    $limit = max(1, min(100, $limit));
    $deliveryType = $retiredDelivery ? 'actor_delete' : 'owner_activity';
    bms_db()->exec("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', available_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE delivery_type = '" . $deliveryType . "' AND event_id IS NULL AND status = 'processing' AND last_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)");
    $stmt = bms_db()->query("SELECT * FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = '" . $deliveryType . "' AND event_id IS NULL AND status IN ('pending', 'retry') AND available_at <= UTC_TIMESTAMP() ORDER BY available_at, id LIMIT " . $limit);
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    $delivered = 0;
    foreach ($rows as $row) {
        $claim = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'processing', attempt_count = attempt_count + 1, last_attempt_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type = :delivery_type AND status IN ('pending', 'retry')");
        $claim->execute(['id' => (int)$row['id'], 'delivery_type' => $deliveryType]);
        if ($claim->rowCount() < 1) {
            continue;
        }
        $row['attempt_count'] = (int)$row['attempt_count'] + 1;
        if (!$retiredDelivery && bms_activitypub_owner_delivery_has_blocked_actor($row)) {
            $error = 'The owner activity recipient is blocked or unavailable.';
            $update = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'dead', last_error = :last_error, updated_at = UTC_TIMESTAMP() WHERE id = :id AND status = 'processing'");
            $update->execute(['last_error' => $error, 'id' => (int)$row['id']]);
            bms_activitypub_mark_owner_delivery_result($row, 'failed', $error);
            continue;
        }
        try {
            $response = bms_activitypub_deliver_owner_row($row, $key, $transport, $resolver);
            $status = (int)($response['status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                $update = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'delivered', delivered_at = UTC_TIMESTAMP(), http_status = :http_status, last_error = NULL, signature_mode = CASE WHEN :fallback = 'legacy' THEN 'legacy' ELSE signature_mode END, updated_at = UTC_TIMESTAMP() WHERE id = :id");
                $update->execute(['http_status' => $status, 'fallback' => (string)($response['signature_fallback'] ?? ''), 'id' => (int)$row['id']]);
                bms_activitypub_mark_owner_delivery_result($row, 'delivered');
                $delivered++;
                continue;
            }
            $transient = $status === 0 || in_array($status, [408, 425, 429], true) || $status >= 500;
            $permanent = !$transient || (int)$row['attempt_count'] >= 8;
            $delay = min(86400, 60 * (2 ** min(10, max(0, (int)$row['attempt_count'] - 1))));
            $error = 'The remote inbox returned HTTP ' . $status . '.';
            $update = bms_db()->prepare('UPDATE ' . bms_table('activitypub_deliveries') . " SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . $delay . " SECOND), http_status = :http_status, last_error = :last_error, updated_at = UTC_TIMESTAMP() WHERE id = :id");
            $update->execute(['status' => $permanent ? 'dead' : 'retry', 'http_status' => $status ?: null, 'last_error' => $error, 'id' => (int)$row['id']]);
            if ($permanent) {
                bms_activitypub_mark_owner_delivery_result($row, 'failed', $error);
            }
        } catch (Throwable $e) {
            $permanent = (int)$row['attempt_count'] >= 8;
            $delay = min(86400, 60 * (2 ** min(10, max(0, (int)$row['attempt_count'] - 1))));
            $error = bms_text_substr($e->getMessage(), 0, 1000);
            $update = bms_db()->prepare('UPDATE ' . bms_table('activitypub_deliveries') . " SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . $delay . " SECOND), http_status = NULL, last_error = :last_error, updated_at = UTC_TIMESTAMP() WHERE id = :id");
            $update->execute(['status' => $permanent ? 'dead' : 'retry', 'last_error' => $error, 'id' => (int)$row['id']]);
            if ($permanent) {
                bms_activitypub_mark_owner_delivery_result($row, 'failed', $error);
            }
        }
    }
    if ($retiredDelivery && is_array($retirement)) {
        $pending = bms_db()->prepare("SELECT COUNT(*) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'actor_delete' AND activity_uri = :activity_uri AND status IN ('pending', 'retry', 'processing')");
        $pending->execute(['activity_uri' => (string)$retirement['delete_activity_uri']]);
        if ((int)$pending->fetchColumn() === 0) {
            $complete = bms_db()->prepare("UPDATE " . bms_table('activitypub_local_actor_lifecycle') . " SET delivery_completed_at = COALESCE(delivery_completed_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = :id");
            $complete->execute(['id' => (int)$retirement['id']]);
        }
    }
    return ['ok' => true, 'count' => $delivered, 'message' => $delivered > 0 ? 'Delivered ' . $delivered . ' owner activit' . ($delivered === 1 ? 'y' : 'ies') . '.' : 'No owner activities were delivered.'];
}
