<?php

/**
 * Private owner-facing presentation for Stage 6 remote content.
 *
 * Protocol parsing, storage, identity, transport, and interaction semantics
 * remain owned by the ActivityPub services. This file exposes only bounded,
 * sanitized presentation data to the active public theme shell.
 */

function bms_activitypub_following_access_state(bool $enabled, bool $loggedIn, bool $owner, bool $staticExport): string
{
    if (!$enabled || $staticExport) {
        return 'not_found';
    }
    if (!$loggedIn) {
        return 'login';
    }
    return $owner ? 'allowed' : 'not_found';
}

function bms_activitypub_following_private_headers(): void
{
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
    header('Vary: Cookie', false);
}

function bms_activitypub_following_actor_avatar(array $row): string
{
    $document = json_decode((string)($row['document_json'] ?? ''), true, bms_activitypub_json_max_depth());
    if (!is_array($document) || array_is_list($document)) {
        return '';
    }
    $icon = $document['icon'] ?? null;
    if (is_array($icon) && array_is_list($icon)) {
        $icon = $icon[0] ?? null;
    }
    $url = is_array($icon) ? ($icon['url'] ?? '') : $icon;
    if (is_array($url)) {
        $url = $url['href'] ?? ($url[0] ?? '');
        if (is_array($url)) {
            $url = $url['href'] ?? '';
        }
    }
    return is_string($url) ? bms_activitypub_remote_link_url($url) : '';
}

function bms_activitypub_following_actor_handle(array $row): string
{
    $username = trim((string)($row['preferred_username'] ?? ''));
    $host = strtolower((string)(parse_url((string)($row['actor_uri'] ?? ''), PHP_URL_HOST) ?: ''));
    return $username !== '' && $host !== '' ? '@' . $username . '@' . $host : '';
}

function bms_activitypub_following_timestamp(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['datetime' => '', 'label' => 'Time unavailable'];
    }
    try {
        $date = new DateTimeImmutable($value, bms_utc_timezone());
        $local = $date->setTimezone(bms_site_timezone());
        return [
            'datetime' => $date->setTimezone(bms_utc_timezone())->format(DateTimeInterface::ATOM),
            'label' => $local->format('M j, Y \a\t g:i a'),
        ];
    } catch (Throwable $e) {
        return ['datetime' => '', 'label' => 'Time unavailable'];
    }
}

function bms_activitypub_following_presentation_row(array $row): array
{
    $state = (string)($row['lifecycle_state'] ?? 'active');
    $metadata = json_decode((string)($row['metadata_json'] ?? ''), true, bms_activitypub_json_max_depth());
    $metadata = is_array($metadata) && !array_is_list($metadata) ? $metadata : [];
    $media = [];
    foreach (is_array($metadata['media'] ?? null) ? array_slice($metadata['media'], 0, 8) : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $url = bms_activitypub_remote_link_url((string)($item['url'] ?? ''));
        $kind = (string)($item['kind'] ?? '');
        $mediaType = strtolower((string)($item['media_type'] ?? ''));
        if ($url === '' || !in_array($kind, ['image', 'video', 'audio'], true)) {
            continue;
        }
        $media[] = [
            'kind' => $kind,
            'media_type' => $mediaType,
            'url' => $url,
            'alt_text' => bms_activitypub_remote_plain_text((string)($item['alt_text'] ?? ''), 1000),
            'width' => max(0, min(10000, (int)($item['width'] ?? 0))),
            'height' => max(0, min(10000, (int)($item['height'] ?? 0))),
        ];
    }
    $contentHtml = '';
    if ($state === 'active' && trim((string)($row['content_html'] ?? '')) !== '') {
        $sanitized = bms_activitypub_sanitize_remote_html((string)$row['content_html']);
        $contentHtml = (string)$sanitized['html'];
    }
    $objectUri = bms_activitypub_remote_link_url((string)($row['object_uri'] ?? ''));
    $actorUri = bms_activitypub_remote_link_url((string)($row['actor_uri'] ?? ''));
    $humanUrl = bms_activitypub_remote_link_url((string)($row['human_url'] ?? ''));
    $time = bms_activitypub_following_timestamp((string)($row['remote_published_at'] ?? $row['created_at'] ?? ''));
    $conversationUrl = bms_url_path('following/conversation/?object=' . rawurlencode($objectUri));
    $replyAnchorId = 'following-reply-' . substr(hash('sha256', $objectUri), 0, 12);
    return [
        'id' => max(0, (int)($row['id'] ?? 0)),
        'object_uri' => $objectUri,
        'actor_uri' => $actorUri,
        'actor_name' => bms_activitypub_remote_plain_text(trim((string)($row['display_name'] ?? '')) ?: trim((string)($row['preferred_username'] ?? '')) ?: 'Remote actor', 200),
        'actor_handle' => bms_activitypub_following_actor_handle($row),
        'actor_avatar_url' => bms_activitypub_following_actor_avatar($row),
        'content_html' => $contentHtml,
        'content_text' => $state === 'active' ? bms_activitypub_remote_plain_text((string)($row['content_text'] ?? ''), 10000) : '',
        'summary' => bms_activitypub_remote_plain_text((string)($metadata['summary'] ?? ''), 1000),
        'sensitive' => !empty($metadata['sensitive']),
        'media' => $state === 'active' ? $media : [],
        'in_reply_to' => bms_activitypub_remote_link_url((string)($metadata['inReplyTo'] ?? '')),
        'permalink' => $humanUrl !== '' ? $humanUrl : $objectUri,
        'lifecycle_state' => $state === 'deleted' ? 'deleted' : 'active',
        'published_datetime' => $time['datetime'],
        'published_label' => $time['label'],
        'like' => [
            'active' => (string)($row['like_state'] ?? '') === 'active',
            'interaction_id' => max(0, (int)($row['like_interaction_id'] ?? 0)),
            'error' => bms_activitypub_remote_plain_text((string)($row['like_last_error'] ?? ''), 500),
        ],
        'announce' => [
            'active' => (string)($row['announce_state'] ?? '') === 'active',
            'interaction_id' => max(0, (int)($row['announce_interaction_id'] ?? 0)),
            'error' => bms_activitypub_remote_plain_text((string)($row['announce_last_error'] ?? ''), 500),
        ],
        'conversation_url' => $conversationUrl,
        'reply_url' => $conversationUrl . '#' . $replyAnchorId,
        'reply_anchor_id' => $replyAnchorId,
    ];
}

function bms_activitypub_following_presentation_rows(array $rows): array
{
    $presented = [];
    foreach ($rows as $row) {
        if (!is_array($row) || bms_activitypub_actor_is_blocked((string)($row['actor_uri'] ?? ''))) {
            continue;
        }
        $presented[] = bms_activitypub_following_presentation_row($row);
    }
    return $presented;
}

function bms_activitypub_following_cached_rows(bool $includeDeleted = false, int $limit = 500): array
{
    $limit = max(1, min(500, $limit));
    $stateSql = $includeDeleted ? "o.lifecycle_state IN ('active', 'deleted')" : "o.lifecycle_state = 'active'";
    $sql = 'SELECT o.*, a.preferred_username, a.display_name, a.document_json, i.id AS like_interaction_id, i.state AS like_state, i.last_error AS like_last_error, n.id AS announce_interaction_id, n.state AS announce_state, n.last_error AS announce_last_error FROM '
        . bms_table('activitypub_remote_objects') . ' o INNER JOIN ' . bms_table('activitypub_following') . " f ON f.remote_actor_id = o.remote_actor_id AND f.state = 'accepted'"
        . ' INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = o.remote_actor_id'
        . ' LEFT JOIN ' . bms_table('activitypub_owner_interactions') . " i ON i.remote_actor_id = o.remote_actor_id AND i.target_object_uri = o.object_uri AND i.interaction_type = 'Like'"
        . ' LEFT JOIN ' . bms_table('activitypub_owner_interactions') . " n ON n.remote_actor_id = o.remote_actor_id AND n.target_object_uri = o.object_uri AND n.interaction_type = 'Announce'"
        . ' WHERE ' . $stateSql . ' ORDER BY COALESCE(o.remote_published_at, o.created_at) DESC, o.id DESC LIMIT ' . $limit;
    $stmt = bms_db()->query($sql);
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    return array_values(array_filter($rows, static fn(array $row): bool => !bms_activitypub_actor_is_blocked((string)$row['actor_uri'])));
}

function bms_activitypub_following_conversation(string $objectUri): array
{
    $objectUri = bms_activitypub_identifier_uri($objectUri, false);
    $rows = bms_activitypub_following_cached_rows(true, 500);
    $byUri = [];
    foreach ($rows as $row) {
        $byUri[(string)$row['object_uri']] = $row;
    }
    if (!isset($byUri[$objectUri])) {
        return [];
    }
    $selected = [$objectUri => $byUri[$objectUri]];
    $ancestorUri = $objectUri;
    for ($depth = 0; $depth < 16; $depth++) {
        $ancestorRow = $byUri[$ancestorUri] ?? null;
        if (!is_array($ancestorRow)) {
            break;
        }
        $metadata = json_decode((string)($ancestorRow['metadata_json'] ?? ''), true, bms_activitypub_json_max_depth());
        $parent = is_array($metadata) ? (string)($metadata['inReplyTo'] ?? '') : '';
        if ($parent === '' || !isset($byUri[$parent]) || isset($selected[$parent])) {
            break;
        }
        $selected[$parent] = $byUri[$parent];
        $ancestorUri = $parent;
    }
    for ($depth = 0; $depth < 16; $depth++) {
        $changed = false;
        foreach ($byUri as $uri => $row) {
            if (isset($selected[$uri])) {
                continue;
            }
            $metadata = json_decode((string)($row['metadata_json'] ?? ''), true, bms_activitypub_json_max_depth());
            $parent = is_array($metadata) ? (string)($metadata['inReplyTo'] ?? '') : '';
            if ($parent !== '' && isset($selected[$parent])) {
                $selected[$uri] = $row;
                $changed = true;
            }
        }
        if (!$changed) {
            break;
        }
    }
    $selectedRows = array_values($selected);
    usort($selectedRows, static function (array $a, array $b): int {
        $aTime = (string)($a['remote_published_at'] ?? $a['created_at'] ?? '');
        $bTime = (string)($b['remote_published_at'] ?? $b['created_at'] ?? '');
        return $aTime === $bTime ? ((int)$a['id'] <=> (int)$b['id']) : strcmp($aTime, $bTime);
    });
    return bms_activitypub_following_presentation_rows($selectedRows);
}

function bms_activitypub_following_validate_undo(int $interactionId, string $type, string $objectUri): void
{
    $stmt = bms_db()->prepare('SELECT interaction_type, target_object_uri, state FROM ' . bms_table('activitypub_owner_interactions') . ' WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $interactionId]);
    $row = $stmt->fetch();
    if (!is_array($row) || !hash_equals($type, (string)$row['interaction_type'])
        || !hash_equals($objectUri, (string)$row['target_object_uri']) || (string)$row['state'] !== 'active') {
        throw new RuntimeException('The selected owner interaction is not eligible for this Undo.');
    }
}

function bms_activitypub_following_redirect_url(string $objectUri = ''): string
{
    if ($objectUri !== '') {
        try {
            $objectUri = bms_activitypub_identifier_uri($objectUri, false);
            return bms_url_path('following/conversation/?object=' . rawurlencode($objectUri));
        } catch (Throwable $e) {
        }
    }
    return bms_url_path('following/');
}

function bms_handle_activitypub_following_route(bool $conversation = false): void
{
    $access = bms_activitypub_following_access_state(
        bms_activitypub_enabled(),
        bms_is_logged_in(),
        bms_current_user_can('view_admin'),
        bms_static_site_export_rendering()
    );
    if ($access === 'login') {
        bms_redirect(bms_url_path('account.php?return_to=' . rawurlencode(bms_url_path('following/'))));
    }
    if ($access !== 'allowed') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not found.';
        return;
    }
    bms_activitypub_following_private_headers();

    $objectUri = trim((string)($_GET['object'] ?? $_POST['object_uri'] ?? ''));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            bms_verify_csrf();
            $action = strtolower(trim((string)($_POST['following_action'] ?? '')));
            $objectUri = bms_activitypub_identifier_uri((string)($_POST['object_uri'] ?? ''), false);
            if ($action === 'reply') {
                $reply = bms_activitypub_create_owner_reply_draft($objectUri, (string)($_POST['reply_body'] ?? ''), '');
                bms_flash('A normal Bonumark Stream reply draft was created.', 'success');
                bms_redirect(bms_admin_url('edit.php?type=draft&file=' . rawurlencode((string)$reply['filename'])));
            }
            if ($action === 'like' || $action === 'boost') {
                $type = $action === 'like' ? 'Like' : 'Announce';
                bms_activitypub_owner_interact($type, $objectUri);
                bms_flash(($type === 'Like' ? 'Like' : 'Boost') . ' queued for signed delivery.', 'success');
            } elseif ($action === 'unlike' || $action === 'unboost') {
                $type = $action === 'unlike' ? 'Like' : 'Announce';
                $interactionId = max(0, (int)($_POST['interaction_id'] ?? 0));
                bms_activitypub_following_validate_undo($interactionId, $type, $objectUri);
                bms_activitypub_owner_undo_interaction($interactionId);
                bms_flash(($type === 'Like' ? 'Unlike' : 'Unboost') . ' queued for signed delivery.', 'success');
            } else {
                throw new RuntimeException('The Following action was not recognized.');
            }
        } catch (Throwable $e) {
            error_log('Bonumark Stream Following action error: ' . $e->getMessage());
            bms_flash(bms_public_safe_exception_notice($e, 'The Following action could not be completed.'), 'error');
        }
        bms_redirect(bms_activitypub_following_redirect_url($conversation ? $objectUri : ''));
    }

    $items = bms_activitypub_following_presentation_rows(bms_activitypub_remote_inbox_rows(200));
    $conversationItems = [];
    if ($conversation) {
        try {
            $objectUri = bms_activitypub_identifier_uri($objectUri, false);
            $conversationItems = bms_activitypub_following_conversation($objectUri);
            if ($conversationItems === []) {
                http_response_code(404);
            }
        } catch (Throwable $e) {
            http_response_code(404);
            $conversationItems = [];
        }
    }

    $siteName = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    echo bms_render_public_theme_template('following', [
        'private_surface' => true,
        'site_name' => $siteName,
        'title' => ($conversation ? 'Conversation' : 'Following') . ' | ' . $siteName,
        'robots_meta' => '<meta name="robots" content="noindex,nofollow,noarchive">',
        'style_url' => bms_asset_url('assets/style.css'),
        'head_preload_html' => '<link rel="stylesheet" href="' . htmlspecialchars(bms_asset_url('assets/following.css'), ENT_QUOTES, 'UTF-8') . '">',
        'script_url' => bms_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => bms_public_theme_stylesheet_links(),
        'theme_script_tags' => bms_public_theme_script_tags(),
        'body_class' => bms_public_theme_class('following-page'),
        'header_html' => bms_render_public_header('following', null, bms_url_path('following/')),
        'footer_html' => bms_render_public_footer(bms_url_path('following/')),
        'csrf' => bms_csrf_token(),
        'notice_html' => bms_render_public_flash_notices(),
        'items' => $conversation ? $conversationItems : $items,
        'timeline_count' => count($items),
        'conversation' => $conversation,
        'conversation_object_uri' => $conversation ? $objectUri : '',
        'conversation_found' => !$conversation || $conversationItems !== [],
        'following_url' => bms_url_path('following/'),
    ]);
}
