<?php
// Verbatim cleanup function from e6fd5525c45adc3629cd9ea28066d0ce5b3e350f.
// Negative-control fixture only. Never loaded by the application.
function bms_activitypub_cleanup_remote_cache(int $limit = 200): array
{
    $limit = max(1, min(1000, $limit));
    $pdo = bms_db();
    $blocked = $pdo->prepare("UPDATE " . bms_table('activitypub_remote_objects') . " SET lifecycle_state = 'blocked', content_html = '', content_text = '', metadata_json = '{}', updated_at = UTC_TIMESTAMP() WHERE lifecycle_state = 'blocked' OR (lifecycle_state = 'active' AND actor_uri IN (SELECT block_value FROM " . bms_table('activitypub_blocks') . " WHERE block_type = 'actor')) LIMIT " . $limit);
    $blocked->execute();
    $candidates = $pdo->query("SELECT o.id FROM " . bms_table('activitypub_remote_objects') . " o LEFT JOIN " . bms_table('activitypub_following') . " f ON f.remote_actor_id = o.remote_actor_id AND f.state = 'accepted' LEFT JOIN " . bms_table('activitypub_owner_interactions') . " i ON i.target_object_uri = o.object_uri LEFT JOIN " . bms_table('activitypub_reply_targets') . " r ON r.remote_object_id = o.id WHERE o.lifecycle_state = 'active' AND o.expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) AND f.id IS NULL AND i.id IS NULL AND r.id IS NULL ORDER BY o.id ASC LIMIT " . $limit);
    $ids = array_values(array_unique(array_filter(array_map('intval', $candidates ? ($candidates->fetchAll(PDO::FETCH_COLUMN) ?: []) : []))));
    $removed = 0;
    if ($ids !== []) {
        $purge = $pdo->prepare('DELETE FROM ' . bms_table('activitypub_remote_objects') . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ") AND lifecycle_state = 'active'");
        $purge->execute($ids);
        $removed = $purge->rowCount();
    }
    return ['blocked_content_cleared' => $blocked->rowCount(), 'unreferenced_objects_removed' => $removed];
}
