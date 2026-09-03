<?php

function bms_activitypub_queue_summary(): array
{
    $rows = bms_db()->query("SELECT delivery_type, status, COUNT(*) AS total, MIN(available_at) AS oldest_available_at, MAX(updated_at) AS latest_updated_at FROM " . bms_table('activitypub_deliveries') . ' GROUP BY delivery_type, status ORDER BY delivery_type, status');
    return $rows ? ($rows->fetchAll() ?: []) : [];
}

function bms_activitypub_operational_delivery_rows(int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $rows = bms_db()->query("SELECT * FROM " . bms_table('activitypub_deliveries') . " WHERE status IN ('pending', 'retry', 'processing', 'dead', 'cancelled') ORDER BY updated_at DESC, id DESC LIMIT " . $limit);
    return $rows ? ($rows->fetchAll() ?: []) : [];
}

function bms_activitypub_queue_issues(int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $sql = "SELECT d.id, d.delivery_type, d.status, d.event_id, d.activity_uri, d.inbox_url,
        CASE
          WHEN d.status = 'processing' AND d.last_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) THEN 'stale_processing'
          WHEN d.delivery_type = 'publication' AND d.event_id IS NULL THEN 'publication_missing_event'
          WHEN d.delivery_type = 'publication' AND e.id IS NULL THEN 'orphaned_publication_event'
          WHEN d.delivery_type = 'publication' AND (NOT (d.activity_uri <=> e.activity_uri) OR NOT (d.object_uri <=> e.object_uri) OR NOT (d.publication_generation <=> e.publication_generation)) THEN 'publication_identity_mismatch'
          WHEN d.delivery_type <> 'publication' AND d.event_id IS NOT NULL THEN 'unexpected_event_link'
          WHEN d.payload_json IS NULL OR JSON_VALID(d.payload_json) = 0 THEN 'invalid_payload'
          WHEN d.activity_uri IS NULL OR d.activity_uri = '' THEN 'missing_activity_identity'
          WHEN d.inbox_url NOT LIKE 'https://%' THEN 'unsafe_inbox_url'
          WHEN EXISTS (
            SELECT 1 FROM " . bms_table('activitypub_deliveries') . " d2
            WHERE d2.id <> d.id AND d2.delivery_type = d.delivery_type AND d2.inbox_url = d.inbox_url
              AND ((d.delivery_type = 'publication' AND d2.event_id = d.event_id)
                OR (d.delivery_type <> 'publication' AND d2.activity_uri = d.activity_uri))
              AND d2.status IN ('pending', 'retry', 'processing', 'dead')
          ) THEN 'duplicate_identity_target'
          ELSE NULL
        END AS issue_code
      FROM " . bms_table('activitypub_deliveries') . " d
      LEFT JOIN " . bms_table('activitypub_publication_events') . " e ON e.id = d.event_id
      HAVING issue_code IS NOT NULL
      ORDER BY d.id ASC LIMIT " . $limit;
    $rows = bms_db()->query($sql);
    return $rows ? ($rows->fetchAll() ?: []) : [];
}

function bms_activitypub_validate_delivery_for_retry(array $row): bool
{
    $type = (string)($row['delivery_type'] ?? '');
    if (!in_array($type, ['publication', 'follower_response', 'owner_activity', 'actor_delete'], true)
        || !bms_activitypub_delivery_url_is_structurally_safe((string)($row['inbox_url'] ?? ''))
        || ($type === 'publication') !== ((int)($row['event_id'] ?? 0) > 0)) {
        return false;
    }
    try {
        $document = bms_activitypub_decode_json_document((string)($row['payload_json'] ?? ''), bms_activitypub_publication_payload_max_bytes());
    } catch (Throwable $e) {
        return false;
    }
    if (!hash_equals((string)($row['activity_uri'] ?? ''), (string)($document['id'] ?? ''))) {
        return false;
    }
    if ($type === 'publication') {
        $event = bms_activitypub_publication_event((int)$row['event_id']);
        return is_array($event)
            && hash_equals((string)$event['activity_uri'], (string)$row['activity_uri'])
            && hash_equals((string)$event['object_uri'], (string)$row['object_uri'])
            && (int)$event['publication_generation'] === (int)$row['publication_generation']
            && hash_equals((string)$event['payload_json'], (string)$row['payload_json']);
    }
    $allowed = $type === 'follower_response' ? ['Accept', 'Reject'] : ($type === 'actor_delete' ? ['Delete'] : ['Follow', 'Undo', 'Like', 'Announce']);
    if (!in_array((string)($document['type'] ?? ''), $allowed, true)) {
        return false;
    }
    if ($type === 'actor_delete') {
        $retirement = bms_activitypub_actor_retirement();
        $objectUri = is_array($document['object'] ?? null) ? (string)($document['object']['id'] ?? '') : (string)($document['object'] ?? '');
        return is_array($retirement)
            && hash_equals((string)$retirement['delete_activity_uri'], (string)$row['activity_uri'])
            && hash_equals((string)$retirement['actor_uri'], $objectUri);
    }
    return true;
}

function bms_activitypub_sync_delivery_parent(array $row): void
{
    if ((string)($row['delivery_type'] ?? '') === 'publication' && (int)($row['event_id'] ?? 0) > 0) {
        bms_activitypub_update_publication_event_status((int)$row['event_id']);
    } elseif ((string)($row['delivery_type'] ?? '') === 'owner_activity') {
        bms_activitypub_mark_owner_delivery_result($row, 'queued', (string)($row['last_error'] ?? ''));
    }
}

function bms_activitypub_recover_stale_processing(): int
{
    $stmt = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', available_at = UTC_TIMESTAMP(), last_error = 'Recovered from stale processing state.', updated_at = UTC_TIMESTAMP() WHERE status = 'processing' AND last_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) AND (:retired = 0 OR delivery_type = 'actor_delete')");
    $stmt->execute(['retired' => bms_activitypub_actor_is_retired() ? 1 : 0]);
    return $stmt->rowCount();
}

function bms_activitypub_retry_delivery(int $deliveryId): bool
{
    if ($deliveryId < 1) {
        return false;
    }
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_deliveries') . ' WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $deliveryId]);
    $row = $stmt->fetch();
    if (!is_array($row) || !in_array((string)$row['status'], ['dead', 'retry'], true)) {
        return false;
    }
    $type = (string)$row['delivery_type'];
    if ((!bms_activitypub_enabled() && !bms_activitypub_actor_is_retired())
        || (bms_activitypub_actor_is_retired() && $type !== 'actor_delete')) {
        return false;
    }
    if (!bms_activitypub_validate_delivery_for_retry($row)) {
        return false;
    }
    $update = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', attempt_count = 0, available_at = UTC_TIMESTAMP(), last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id AND status IN ('dead', 'retry')");
    $update->execute(['id' => $deliveryId]);
    if ($update->rowCount() === 1) {
        bms_activitypub_sync_delivery_parent($row);
        return true;
    }
    return false;
}

function bms_activitypub_cancel_delivery(int $deliveryId): bool
{
    if ($deliveryId < 1) {
        return false;
    }
    $select = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_deliveries') . ' WHERE id = :id LIMIT 1');
    $select->execute(['id' => $deliveryId]);
    $row = $select->fetch();
    if (!is_array($row) || (string)$row['delivery_type'] === 'actor_delete') {
        return false;
    }
    $stmt = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'cancelled', last_error = 'Permanently cancelled by the site owner.', updated_at = UTC_TIMESTAMP() WHERE id = :id AND delivery_type <> 'actor_delete' AND status IN ('pending', 'retry', 'processing', 'dead')");
    $stmt->execute(['id' => $deliveryId]);
    if ($stmt->rowCount() === 1) {
        bms_activitypub_sync_delivery_parent($row);
        return true;
    }
    return false;
}

function bms_activitypub_reconcile_queue(): array
{
    $issues = bms_activitypub_queue_issues(500);
    $cancelled = 0;
    $recovered = bms_activitypub_recover_stale_processing();
    if (bms_activitypub_actor_is_retired()) {
        $retiredWork = bms_db()->query("SELECT id FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type <> 'actor_delete' AND status IN ('pending', 'retry', 'processing', 'dead') ORDER BY id ASC LIMIT 500");
        foreach ($retiredWork ? ($retiredWork->fetchAll() ?: []) : [] as $row) {
            $cancelled += bms_activitypub_cancel_delivery((int)$row['id']) ? 1 : 0;
        }
    }
    foreach ($issues as $issue) {
        if (!in_array((string)$issue['issue_code'], ['publication_missing_event', 'orphaned_publication_event', 'publication_identity_mismatch', 'unexpected_event_link', 'invalid_payload', 'missing_activity_identity', 'unsafe_inbox_url'], true)) {
            continue;
        }
        if ((string)$issue['delivery_type'] === 'actor_delete') {
            continue;
        }
        $select = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_deliveries') . ' WHERE id = :id LIMIT 1');
        $select->execute(['id' => (int)$issue['id']]);
        $row = $select->fetch();
        if (!is_array($row)) {
            continue;
        }
        $stmt = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'cancelled', last_error = :error, updated_at = UTC_TIMESTAMP() WHERE id = :id AND status IN ('pending', 'retry', 'processing', 'dead')");
        $stmt->execute(['error' => 'Queue reconciliation: ' . (string)$issue['issue_code'] . '.', 'id' => (int)$issue['id']]);
        if ($stmt->rowCount() === 1) {
            $cancelled++;
            bms_activitypub_sync_delivery_parent($row);
        }
    }
    return ['recovered' => $recovered, 'cancelled' => $cancelled, 'remaining_issues' => count(bms_activitypub_queue_issues(500))];
}
