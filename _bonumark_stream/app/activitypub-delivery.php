<?php

/**
 * Durable local publication activities and outbound delivery.
 *
 * The local publication transition is recorded before any remote request is
 * attempted. Only the scheduled worker performs network I/O.
 */

function bms_activitypub_publication_payload_max_bytes(): int
{
    return 5242880;
}

function bms_activitypub_find_stream_post(int $postId): ?array
{
    if ($postId < 1 || !bms_is_installed()) {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . " WHERE id = :id AND post_type = 'stream' LIMIT 1");
    $stmt->execute(['id' => $postId]);
    $row = $stmt->fetch();
    return is_array($row) ? bms_database_row_to_content_page($row) : null;
}

function bms_activitypub_local_object(int $postId): ?array
{
    if ($postId < 1 || !bms_is_installed()) {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_local_objects') . ' WHERE post_id = :post_id LIMIT 1');
    $stmt->execute(['post_id' => $postId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_record_permalink_alias(PDO $pdo, int $postId, string $previousSlug, string $currentSlug): void
{
    $previousSlug = bms_slugify($previousSlug);
    $currentSlug = bms_slugify($currentSlug);
    if ($postId < 1 || $previousSlug === '' || $currentSlug === '' || $previousSlug === $currentSlug
        || !bms_database_table_exists($pdo, bms_table('activitypub_permalink_aliases'))) {
        return;
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO ' . bms_table('activitypub_permalink_aliases') . ' (post_id, slug, created_at) VALUES (:post_id, :slug, UTC_TIMESTAMP())');
    $stmt->execute(['post_id' => $postId, 'slug' => $previousSlug]);
}

function bms_activitypub_permalink_alias_target(string $slug): string
{
    $slug = trim($slug);
    if ($slug === '' || !bms_is_installed()) {
        return '';
    }
    $slug = bms_slugify($slug);

    try {
        $pdo = bms_db();
        if (!bms_database_table_exists($pdo, bms_table('activitypub_permalink_aliases'))) {
            return '';
        }
        $stmt = $pdo->prepare('SELECT p.slug FROM ' . bms_table('activitypub_permalink_aliases') . ' a INNER JOIN ' . bms_table('posts') . " p ON p.id = a.post_id WHERE a.slug = :slug AND p.post_type = 'stream' AND p.status = 'published' LIMIT 1");
        $stmt->execute(['slug' => $slug]);
        $currentSlug = trim((string)($stmt->fetchColumn() ?: ''));
        if ($currentSlug === '') {
            return '';
        }
        $currentSlug = bms_slugify($currentSlug);
        if ($currentSlug === $slug) {
            return '';
        }
        return bms_stream_url($currentSlug);
    } catch (Throwable $e) {
        return '';
    }
}

function bms_activitypub_local_tombstone_document(array $localObject, bool $includeContext = true): array
{
    $document = [
        'id' => (string)$localObject['object_uri'],
        'type' => 'Tombstone',
        'formerType' => (string)($localObject['object_type'] ?? 'Note'),
        'deleted' => bms_activitypub_datetime((string)($localObject['deleted_at'] ?? '')),
    ];
    if ($document['deleted'] === '') {
        unset($document['deleted']);
    }
    return $includeContext ? ['@context' => bms_activitypub_context()] + $document : $document;
}

function bms_activitypub_publication_activity_type(string $eventType): string
{
    return match (strtolower(trim($eventType))) {
        'published' => 'Create',
        'updated' => 'Update',
        'unpublished', 'deleted' => 'Delete',
        default => '',
    };
}

function bms_activitypub_delivery_url_is_structurally_safe(string $url): bool
{
    $parts = parse_url(trim($url));
    return is_array($parts)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && trim((string)($parts['host'] ?? '')) !== ''
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
        && strlen($url) <= 2048 && preg_match('/[\x00-\x20\x7f]/', $url) !== 1;
}

function bms_activitypub_actor_advertises_rfc9421(array $actor): bool
{
    $document = $actor['document'] ?? null;
    if (!is_array($document)) {
        $document = json_decode((string)($actor['document_json'] ?? ''), true, bms_activitypub_json_max_depth());
    }
    if (!is_array($document)) {
        return false;
    }
    $candidates = [
        $document['signatureAlgorithms'] ?? null,
        $document['endpoints']['signatureAlgorithms'] ?? null,
        $document['capabilities']['httpMessageSignatures'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $values = is_array($candidate) ? $candidate : [$candidate];
        foreach ($values as $value) {
            $value = strtolower(trim((string)$value));
            if (in_array($value, ['rfc9421', 'http-message-signatures', 'signature-input'], true)) {
                return true;
            }
        }
    }
    return false;
}

function bms_activitypub_publication_targets(): array
{
    $stmt = bms_db()->query("SELECT a.id, a.actor_uri, a.inbox_url, a.shared_inbox_url, a.document_json FROM " . bms_table('activitypub_followers') . ' f INNER JOIN ' . bms_table('activitypub_remote_actors') . " a ON a.id = f.remote_actor_id WHERE f.state = 'accepted' ORDER BY a.id ASC");
    $actors = $stmt ? ($stmt->fetchAll() ?: []) : [];
    $groups = [];
    foreach ($actors as $actor) {
        if (!is_array($actor)) {
            continue;
        }
        $target = trim((string)($actor['shared_inbox_url'] ?? '')) ?: trim((string)($actor['inbox_url'] ?? ''));
        if (!bms_activitypub_delivery_url_is_structurally_safe($target)) {
            continue;
        }
        $key = hash('sha256', $target);
        if (!isset($groups[$key])) {
            $groups[$key] = ['inbox_url' => $target, 'actor_ids' => [], 'rfc9421' => true];
        }
        $groups[$key]['actor_ids'][] = (int)$actor['id'];
        $groups[$key]['rfc9421'] = !empty($groups[$key]['rfc9421']) && bms_activitypub_actor_advertises_rfc9421($actor);
    }
    return array_values($groups);
}

function bms_activitypub_queue_publication_fanout(int $eventId, string $activityUri, string $payload): int
{
    $count = 0;
    foreach (bms_activitypub_publication_targets() as $target) {
        $actorIds = array_values(array_unique(array_filter(array_map('intval', (array)$target['actor_ids']))));
        sort($actorIds, SORT_NUMERIC);
        $actorJson = json_encode($actorIds, JSON_UNESCAPED_SLASHES);
        $inboxUrl = (string)$target['inbox_url'];
        $dedupeKey = hash('sha256', "publication\n" . $eventId . "\n" . $inboxUrl);
        $stmt = bms_db()->prepare('INSERT IGNORE INTO ' . bms_table('activitypub_deliveries') . ' (delivery_type, event_id, activity_uri, payload_json, dedupe_key, inbox_url, recipient_actor_ids_json, signature_mode, status, attempt_count, available_at, last_attempt_at, delivered_at, http_status, last_error, created_at, updated_at) VALUES (\'publication\', :event_id, :activity_uri, :payload_json, :dedupe_key, :inbox_url, :actor_ids, :signature_mode, \'pending\', 0, UTC_TIMESTAMP(), NULL, NULL, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $stmt->execute([
            'event_id' => $eventId,
            'activity_uri' => $activityUri,
            'payload_json' => $payload,
            'dedupe_key' => $dedupeKey,
            'inbox_url' => $inboxUrl,
            'actor_ids' => is_string($actorJson) ? $actorJson : '[]',
            'signature_mode' => !empty($target['rfc9421']) ? 'rfc9421' : 'legacy',
        ]);
        $count += $stmt->rowCount() > 0 ? 1 : 0;
    }
    return $count;
}

function bms_activitypub_record_actionable_publication_transition(array $transition): void
{
    $postId = (int)($transition['post_id'] ?? 0);
    $activityType = bms_activitypub_publication_activity_type((string)($transition['event_type'] ?? ''));
    if ($postId < 1 || $activityType === '') {
        return;
    }
    $page = bms_activitypub_find_stream_post($postId);
    $pdo = bms_db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $insertObject = $pdo->prepare('INSERT IGNORE INTO ' . bms_table('activitypub_local_objects') . ' (post_id, object_uri, object_type, content_hash, last_object_json, last_human_url, publication_generation, transition_sequence, published_at, updated_at, deleted_at, created_at) VALUES (:post_id, :object_uri, \'Note\', \'\', NULL, NULL, 0, 0, NULL, UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP())');
        $insertObject->execute(['post_id' => $postId, 'object_uri' => bms_activitypub_object_url($postId)]);
        $selectObject = $pdo->prepare('SELECT * FROM ' . bms_table('activitypub_local_objects') . ' WHERE post_id = :post_id FOR UPDATE');
        $selectObject->execute(['post_id' => $postId]);
        $localObject = $selectObject->fetch();
        if (!is_array($localObject)) {
            throw new RuntimeException('The durable ActivityPub object identity could not be established.');
        }

        $beforeState = is_array($transition['before'] ?? null) ? $transition['before'] : [];
        $afterState = is_array($transition['after'] ?? null) ? $transition['after'] : [];
        if ((int)($localObject['publication_generation'] ?? 0) > 0
            && (string)($beforeState['status'] ?? '') === 'published'
            && (string)($afterState['status'] ?? '') === 'published') {
            bms_activitypub_record_permalink_alias(
                $pdo,
                $postId,
                (string)($beforeState['slug'] ?? ''),
                (string)($afterState['slug'] ?? '')
            );
        }

        $state = json_encode(['before' => $transition['before'] ?? null, 'after' => $transition['after'] ?? null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $state = is_string($state) ? $state : '{}';
        $latest = $pdo->prepare("SELECT event_type, content_hash, state_json FROM " . bms_table('activitypub_publication_events') . " WHERE post_id = :post_id AND status <> 'observed' ORDER BY id DESC LIMIT 1");
        $latest->execute(['post_id' => $postId]);
        $previous = $latest->fetch();
        if (is_array($previous)
            && hash_equals((string)$previous['event_type'], (string)$transition['event_type'])
            && hash_equals((string)$previous['content_hash'], (string)($transition['content_hash'] ?? ''))
            && hash_equals((string)$previous['state_json'], $state)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return;
        }

        $snapshot = json_decode((string)($localObject['last_object_json'] ?? ''), true, bms_activitypub_json_max_depth());
        if ($activityType !== 'Delete' || !is_array($snapshot)) {
            if (!is_array($page)) {
                throw new RuntimeException('The local post snapshot is unavailable for federation.');
            }
            $snapshot = bms_activitypub_post_object($page, null, false);
        }
        $generation = (int)($localObject['publication_generation'] ?? 0) + ($activityType === 'Create' ? 1 : 0);
        $sequence = (int)($localObject['transition_sequence'] ?? 0) + 1;
        $fingerprint = hash('sha256', $postId . "\n" . $sequence . "\n" . $activityType . "\n" . $state);
        $insertEvent = $pdo->prepare('INSERT INTO ' . bms_table('activitypub_publication_events') . ' (post_id, event_type, activity_uri, source, content_hash, state_json, payload_json, transition_fingerprint, status, created_at, processed_at) VALUES (:post_id, :event_type, NULL, :source, :content_hash, :state_json, NULL, :fingerprint, \'recording\', UTC_TIMESTAMP(), NULL)');
        $insertEvent->execute([
            'post_id' => $postId,
            'event_type' => (string)$transition['event_type'],
            'source' => (string)($transition['source'] ?? 'application'),
            'content_hash' => (string)($transition['content_hash'] ?? ''),
            'state_json' => $state,
            'fingerprint' => $fingerprint,
        ]);
        $eventId = (int)$pdo->lastInsertId();
        $activityUri = $activityType === 'Create' && $generation === 1 && !is_array($previous)
            ? bms_activitypub_create_activity_url($postId)
            : bms_activitypub_event_activity_url($eventId);
        $document = bms_activitypub_publication_activity($activityType, $snapshot, $activityUri);
        $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) {
            throw new RuntimeException('The durable publication activity could not be encoded.');
        }
        $updateEvent = $pdo->prepare('UPDATE ' . bms_table('activitypub_publication_events') . ' SET activity_uri = :activity_uri, payload_json = :payload_json WHERE id = :id');
        $updateEvent->execute(['activity_uri' => $activityUri, 'payload_json' => $payload, 'id' => $eventId]);

        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $humanUrl = trim((string)($snapshot['url'] ?? ''));
        $updateObject = $pdo->prepare('UPDATE ' . bms_table('activitypub_local_objects') . ' SET content_hash = :content_hash, last_object_json = :object_json, last_human_url = :human_url, publication_generation = :generation, transition_sequence = :sequence, published_at = CASE WHEN :published_activity_type = \'Create\' THEN UTC_TIMESTAMP() ELSE published_at END, updated_at = UTC_TIMESTAMP(), deleted_at = CASE WHEN :deleted_activity_type = \'Delete\' THEN UTC_TIMESTAMP() ELSE NULL END WHERE id = :id');
        $updateObject->execute([
            'content_hash' => (string)($transition['content_hash'] ?? ''),
            'object_json' => is_string($snapshotJson) ? $snapshotJson : null,
            'human_url' => $humanUrl !== '' ? $humanUrl : null,
            'generation' => $generation,
            'sequence' => $sequence,
            'published_activity_type' => $activityType,
            'deleted_activity_type' => $activityType,
            'id' => (int)$localObject['id'],
        ]);
        $queued = bms_activitypub_queue_publication_fanout($eventId, $activityUri, $payload);
        $finish = $pdo->prepare('UPDATE ' . bms_table('activitypub_publication_events') . ' SET status = :status, processed_at = :processed_at WHERE id = :id');
        $finish->execute(['status' => $queued > 0 ? 'queued' : 'completed', 'processed_at' => $queued > 0 ? null : gmdate('Y-m-d H:i:s'), 'id' => $eventId]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_activitypub_ensure_tombstone_before_permanent_delete(int $postId): void
{
    if (!bms_activitypub_enabled() || $postId < 1) {
        return;
    }
    $localObject = bms_activitypub_local_object($postId);
    if (!is_array($localObject) || trim((string)($localObject['deleted_at'] ?? '')) !== '') {
        return;
    }
    $page = bms_activitypub_find_stream_post($postId);
    if (!is_array($page)) {
        throw new RuntimeException('The federated post snapshot is unavailable before permanent deletion.');
    }
    $state = bms_publication_state(array_replace($page, ['status' => 'published', 'content_status' => 'published']));
    bms_activitypub_record_actionable_publication_transition([
        'event_type' => 'deleted',
        'source' => 'permanent_delete',
        'post_id' => $postId,
        'post_type' => 'stream',
        'slug' => (string)($page['slug'] ?? ''),
        'content_hash' => (string)($localObject['content_hash'] ?? ''),
        'before' => $state,
        'after' => null,
    ]);
}

function bms_activitypub_publication_event(int $eventId): ?array
{
    if ($eventId < 1) {
        return null;
    }
    $stmt = bms_db()->prepare("SELECT * FROM " . bms_table('activitypub_publication_events') . " WHERE id = :id AND status <> 'observed' AND activity_uri IS NOT NULL AND payload_json IS NOT NULL LIMIT 1");
    $stmt->execute(['id' => $eventId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_publication_event_by_activity_uri(string $activityUri): ?array
{
    $activityUri = trim($activityUri);
    if ($activityUri === '') {
        return null;
    }
    $stmt = bms_db()->prepare("SELECT * FROM " . bms_table('activitypub_publication_events') . " WHERE activity_uri = :activity_uri AND status <> 'observed' AND payload_json IS NOT NULL LIMIT 1");
    $stmt->execute(['activity_uri' => $activityUri]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_publication_delivery_rows(int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $stmt = bms_db()->query("SELECT d.*, e.event_type, e.post_id FROM " . bms_table('activitypub_deliveries') . ' d INNER JOIN ' . bms_table('activitypub_publication_events') . " e ON e.id = d.event_id WHERE d.delivery_type = 'publication' AND d.event_id IS NOT NULL ORDER BY d.updated_at DESC, d.id DESC LIMIT " . $limit);
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

function bms_activitypub_manual_retry_publication_delivery(int $deliveryId): bool
{
    if ($deliveryId < 1 || !bms_activitypub_enabled()) {
        return false;
    }
    $stmt = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', attempt_count = 0, available_at = UTC_TIMESTAMP(), last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type = 'publication' AND event_id IS NOT NULL AND status IN ('retry', 'dead')");
    $stmt->execute(['id' => $deliveryId]);
    return $stmt->rowCount() > 0;
}

function bms_activitypub_retry_after_seconds(array $response, int $fallback): int
{
    $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
    $values = $headers['retry-after'] ?? [];
    $value = trim(is_array($values) ? (string)end($values) : (string)$values);
    if (preg_match('/^[0-9]+$/', $value) === 1) {
        return max(1, min(86400, (int)$value));
    }
    $timestamp = $value !== '' ? strtotime($value) : false;
    return $timestamp !== false ? max(1, min(86400, $timestamp - time())) : $fallback;
}

function bms_activitypub_reconcile_publication_delivery_target(array $delivery, ?callable $fetcher = null, ?callable $resolver = null): ?array
{
    $actorIds = json_decode((string)($delivery['recipient_actor_ids_json'] ?? '[]'), true);
    $actorIds = is_array($actorIds) ? array_values(array_unique(array_filter(array_map('intval', $actorIds)))) : [];
    if ($actorIds === []) {
        throw new RuntimeException('The publication delivery has no durable recipient actors.');
    }
    $groups = [];
    foreach ($actorIds as $actorId) {
        $stmt = bms_db()->prepare('SELECT actor_uri FROM ' . bms_table('activitypub_remote_actors') . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $actorId]);
        $actorUri = trim((string)($stmt->fetchColumn() ?: ''));
        if ($actorUri === '') {
            throw new RuntimeException('A publication recipient actor is unavailable.');
        }
        $actor = bms_activitypub_cached_remote_actor($actorUri, false);
        if (!is_array($actor)) {
            $actor = bms_activitypub_discover_remote_actor($actorUri, true, $fetcher, $resolver, true);
        }
        $target = trim((string)($actor['shared_inbox_url'] ?? '')) ?: trim((string)($actor['inbox_url'] ?? ''));
        bms_activitypub_validate_remote_url($target, $resolver, false);
        $key = hash('sha256', $target);
        if (!isset($groups[$key])) {
            $groups[$key] = ['inbox_url' => $target, 'actor_ids' => [], 'rfc9421' => true];
        }
        $groups[$key]['actor_ids'][] = $actorId;
        $groups[$key]['rfc9421'] = !empty($groups[$key]['rfc9421']) && bms_activitypub_actor_advertises_rfc9421($actor);
    }

    $currentUrl = (string)$delivery['inbox_url'];
    $currentGroup = null;
    foreach ($groups as $group) {
        if (hash_equals($currentUrl, (string)$group['inbox_url'])) {
            $currentGroup = $group;
            continue;
        }
        $ids = array_values(array_unique(array_map('intval', (array)$group['actor_ids'])));
        sort($ids, SORT_NUMERIC);
        $idsJson = json_encode($ids, JSON_UNESCAPED_SLASHES);
        $targetUrl = (string)$group['inbox_url'];
        $dedupeKey = hash('sha256', "publication\n" . (int)$delivery['event_id'] . "\n" . $targetUrl);
        $insert = bms_db()->prepare('INSERT IGNORE INTO ' . bms_table('activitypub_deliveries') . ' (delivery_type, event_id, activity_uri, payload_json, dedupe_key, inbox_url, recipient_actor_ids_json, signature_mode, status, attempt_count, available_at, last_attempt_at, delivered_at, http_status, last_error, created_at, updated_at) VALUES (\'publication\', :event_id, :activity_uri, :payload_json, :dedupe_key, :inbox_url, :actor_ids, :signature_mode, \'pending\', 0, UTC_TIMESTAMP(), NULL, NULL, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $insert->execute([
            'event_id' => (int)$delivery['event_id'],
            'activity_uri' => (string)$delivery['activity_uri'],
            'payload_json' => (string)$delivery['payload_json'],
            'dedupe_key' => $dedupeKey,
            'inbox_url' => $targetUrl,
            'actor_ids' => is_string($idsJson) ? $idsJson : '[]',
            'signature_mode' => !empty($group['rfc9421']) ? 'rfc9421' : 'legacy',
        ]);
    }
    if (!is_array($currentGroup)) {
        $update = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'superseded', last_error = 'Recipient inbox changed before delivery.', updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type = 'publication' AND event_id IS NOT NULL");
        $update->execute(['id' => (int)$delivery['id']]);
        return null;
    }
    $ids = array_values(array_unique(array_map('intval', (array)$currentGroup['actor_ids'])));
    sort($ids, SORT_NUMERIC);
    $idsJson = json_encode($ids, JSON_UNESCAPED_SLASHES);
    $mode = !empty($currentGroup['rfc9421']) ? 'rfc9421' : 'legacy';
    $update = bms_db()->prepare('UPDATE ' . bms_table('activitypub_deliveries') . ' SET recipient_actor_ids_json = :actor_ids, signature_mode = :signature_mode, updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type = \'publication\' AND event_id IS NOT NULL');
    $update->execute(['actor_ids' => is_string($idsJson) ? $idsJson : '[]', 'signature_mode' => $mode, 'id' => (int)$delivery['id']]);
    $delivery['recipient_actor_ids_json'] = is_string($idsJson) ? $idsJson : '[]';
    $delivery['signature_mode'] = $mode;
    return $delivery;
}

function bms_activitypub_deliver_publication_row(array $delivery, array $key, ?callable $transport = null, ?callable $resolver = null): array
{
    if ((string)($delivery['delivery_type'] ?? '') !== 'publication' || (int)($delivery['event_id'] ?? 0) < 1) {
        throw new RuntimeException('The publication worker received an isolated non-publication delivery.');
    }
    $payload = (string)($delivery['payload_json'] ?? '');
    $document = bms_activitypub_decode_json_document($payload, bms_activitypub_publication_payload_max_bytes());
    if (!in_array((string)($document['type'] ?? ''), ['Create', 'Update', 'Delete'], true)) {
        throw new RuntimeException('Only local publication activities are eligible for publication delivery.');
    }
    $url = trim((string)$delivery['inbox_url']);
    $mode = (string)($delivery['signature_mode'] ?? 'legacy') === 'rfc9421' ? 'rfc9421' : 'legacy';
    $send = static function (string $signatureMode) use ($url, $payload, $key, $transport, $resolver): array {
        $headers = bms_activitypub_sign_outbound_request('POST', $url, $payload, $key, $signatureMode);
        return bms_activitypub_http_request($url, [
            'method' => 'POST',
            'body' => $payload,
            'headers' => $headers,
            'max_bytes' => 262144,
            'max_redirects' => 0,
        ], $transport, $resolver);
    };
    $response = $send($mode);
    $status = (int)($response['status'] ?? 0);
    if ($mode === 'rfc9421' && in_array($status, [400, 401, 403], true)) {
        $response = $send('legacy');
        $response['signature_fallback'] = 'legacy';
    }
    return $response;
}

function bms_activitypub_update_publication_event_status(int $eventId): void
{
    $stmt = bms_db()->prepare("SELECT status, COUNT(*) AS total FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication' AND event_id = :event_id GROUP BY status");
    $stmt->execute(['event_id' => $eventId]);
    $counts = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $counts[(string)$row['status']] = (int)$row['total'];
    }
    $active = array_sum(array_intersect_key($counts, array_flip(['pending', 'retry', 'processing'])));
    $status = $active > 0 ? 'queued' : (!empty($counts['dead']) ? 'dead' : 'completed');
    $update = bms_db()->prepare('UPDATE ' . bms_table('activitypub_publication_events') . ' SET status = :status, processed_at = CASE WHEN :processed_status = \'queued\' THEN NULL ELSE UTC_TIMESTAMP() END WHERE id = :id');
    $update->execute(['status' => $status, 'processed_status' => $status, 'id' => $eventId]);
}

function bms_activitypub_run_publication_deliveries(int $limit = 20, ?callable $transport = null, ?callable $resolver = null, ?callable $fetcher = null): array
{
    if (!bms_activitypub_enabled()) {
        return ['ok' => true, 'count' => 0, 'message' => 'ActivityPub publication delivery is disabled.'];
    }
    try {
        $key = bms_activitypub_active_signing_key(true);
    } catch (Throwable $e) {
        return ['ok' => false, 'count' => 0, 'message' => 'ActivityPub publications are waiting for a usable signing key.'];
    }
    if (!is_array($key)) {
        return ['ok' => false, 'count' => 0, 'message' => 'ActivityPub publications are waiting for an active signing key.'];
    }
    $limit = max(1, min(100, $limit));
    bms_db()->exec("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', available_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE delivery_type = 'publication' AND event_id IS NOT NULL AND status = 'processing' AND last_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)");
    $stmt = bms_db()->query("SELECT * FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication' AND event_id IS NOT NULL AND status IN ('pending', 'retry') AND available_at <= UTC_TIMESTAMP() ORDER BY available_at ASC, id ASC LIMIT " . $limit);
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    $delivered = 0;
    foreach ($rows as $row) {
        $claim = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'processing', attempt_count = attempt_count + 1, last_attempt_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type = 'publication' AND event_id IS NOT NULL AND status IN ('pending', 'retry')");
        $claim->execute(['id' => (int)$row['id']]);
        if ($claim->rowCount() < 1) {
            continue;
        }
        $row['attempt_count'] = (int)$row['attempt_count'] + 1;
        $eventId = (int)$row['event_id'];
        try {
            $row = bms_activitypub_reconcile_publication_delivery_target($row, $fetcher, $resolver);
            if (!is_array($row)) {
                bms_activitypub_update_publication_event_status($eventId);
                continue;
            }
            $response = bms_activitypub_deliver_publication_row($row, $key, $transport, $resolver);
            $statusCode = (int)($response['status'] ?? 0);
            if ($statusCode >= 200 && $statusCode < 300) {
                $update = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'delivered', delivered_at = UTC_TIMESTAMP(), http_status = :http_status, last_error = NULL, signature_mode = CASE WHEN :fallback = 'legacy' THEN 'legacy' ELSE signature_mode END, updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type = 'publication' AND event_id IS NOT NULL");
                $update->execute(['http_status' => $statusCode, 'fallback' => (string)($response['signature_fallback'] ?? ''), 'id' => (int)$row['id']]);
                $delivered++;
            } else {
                $attempts = (int)$row['attempt_count'];
                $transient = $statusCode === 0 || in_array($statusCode, [408, 425, 429], true) || $statusCode >= 500;
                $permanent = !$transient || $attempts >= 8;
                $fallback = min(86400, 60 * (2 ** min(10, max(0, $attempts - 1))));
                $delay = $statusCode === 429 ? bms_activitypub_retry_after_seconds($response, $fallback) : $fallback;
                $update = bms_db()->prepare('UPDATE ' . bms_table('activitypub_deliveries') . " SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . (int)$delay . " SECOND), http_status = :http_status, last_error = :last_error, updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type = 'publication' AND event_id IS NOT NULL");
                $update->execute(['status' => $permanent ? 'dead' : 'retry', 'http_status' => $statusCode > 0 ? $statusCode : null, 'last_error' => 'The remote inbox returned HTTP ' . $statusCode . '.', 'id' => (int)$row['id']]);
            }
        } catch (Throwable $e) {
            $attempts = (int)$row['attempt_count'];
            $delay = min(86400, 60 * (2 ** min(10, max(0, $attempts - 1))));
            $update = bms_db()->prepare('UPDATE ' . bms_table('activitypub_deliveries') . " SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . (int)$delay . " SECOND), http_status = NULL, last_error = :last_error, updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type = 'publication' AND event_id IS NOT NULL");
            $update->execute(['status' => $attempts >= 8 ? 'dead' : 'retry', 'last_error' => bms_text_substr($e->getMessage(), 0, 1000), 'id' => (int)$row['id']]);
        }
        bms_activitypub_update_publication_event_status($eventId);
    }
    return ['ok' => true, 'count' => $delivered, 'message' => $delivered > 0 ? 'Delivered ' . $delivered . ' publication' . ($delivered === 1 ? '' : 's') . '.' : 'No publications were delivered.'];
}
