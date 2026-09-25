<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activitypub-interactions.php';

/**
 * Public engagement: durable local interactions plus eligible current-generation
 * federation. Local comments/Likes retain their existing local-post lifetime.
 * Remote Like identities remain private; only totals are returned.
 */
function bms_public_interaction_counts_for_slugs(array $slugs): array
{
    $slugs = array_values(array_unique(array_filter(array_map(static fn($slug): string => bms_slugify((string)$slug), $slugs))));
    if (!$slugs || !bms_is_installed() || !bms_has_database_config()) {
        return [];
    }
    $result = [];
    foreach (array_chunk($slugs, 100) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $posts = bms_db()->prepare("SELECT id, slug FROM " . bms_table('posts') . " WHERE status = 'published' AND post_type = 'stream' AND slug IN (" . $marks . ")");
        $posts->execute($chunk);
        $ids = [];
        foreach ($posts->fetchAll() ?: [] as $post) {
            $slug = (string)$post['slug'];
            $ids[(int)$post['id']] = $slug;
            $result[$slug] = ['comments' => 0, 'likes' => 0];
        }
        if (!$ids) {
            continue;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $params = array_keys($ids);
        $local = bms_db()->prepare("SELECT c.post_id, COUNT(*) AS total FROM " . bms_table('comments') . " c INNER JOIN " . bms_table('users') . " u ON u.id = c.user_id WHERE c.post_id IN (" . $marks . ") AND c.status = 'approved' GROUP BY c.post_id");
        $local->execute($params);
        foreach ($local->fetchAll() ?: [] as $row) {
            $result[$ids[(int)$row['post_id']]]['comments'] = (int)$row['total'];
        }
        $local = bms_db()->prepare('SELECT post_id, COUNT(*) AS total FROM ' . bms_table('stream_likes') . ' WHERE post_id IN (' . $marks . ') GROUP BY post_id');
        $local->execute($params);
        foreach ($local->fetchAll() ?: [] as $row) {
            $result[$ids[(int)$row['post_id']]]['likes'] = (int)$row['total'];
        }
        if (!bms_activitypub_enabled()) {
            continue;
        }
        foreach (bms_activitypub_public_reply_rows($params) as $reply) {
            $result[$ids[(int)$reply['target_post_id']]]['comments']++;
        }
        $remote = bms_db()->prepare("SELECT i.target_post_id, i.actor_uri FROM " . bms_table('activitypub_remote_interactions') . " i INNER JOIN " . bms_table('activitypub_local_objects') . " o ON o.post_id = i.target_post_id AND o.publication_generation = i.target_publication_generation AND o.object_uri = i.target_object_uri INNER JOIN " . bms_table('activitypub_remote_actors') . " a ON a.id = i.remote_actor_id WHERE i.target_post_id IN (" . $marks . ") AND i.interaction_type = 'Like' AND i.state = 'active' AND o.deleted_at IS NULL AND a.lifecycle_state = 'active'");
        $remote->execute($params);
        $blocked = [];
        foreach ($remote->fetchAll() ?: [] as $reaction) {
            $actor = (string)$reaction['actor_uri'];
            $blocked[$actor] ??= bms_activitypub_actor_is_blocked($actor);
            if (!$blocked[$actor]) {
                $result[$ids[(int)$reaction['target_post_id']]]['likes']++;
            }
        }
    }
    return $result;
}

function bms_public_interaction_counts_for_slug(string $slug): array
{
    $slug = bms_slugify($slug);
    return bms_public_interaction_counts_for_slugs([$slug])[$slug] ?? ['comments' => 0, 'likes' => 0];
}
