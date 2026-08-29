<?php

/**
 * ActivityStreams 2.0 serialization for Bonumark's read-only federation view.
 *
 * These functions transform existing Bonumark database records into protocol
 * documents. They do not write state, contact remote servers, or render themes.
 */

function bms_activitypub_context(bool $includeSecurity = false): array|string
{
    if (!$includeSecurity) {
        return 'https://www.w3.org/ns/activitystreams';
    }
    return [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
    ];
}

function bms_activitypub_absolute_url(string $path = '', ?string $baseUrl = null): string
{
    $path = trim($path);
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    $baseUrl = rtrim(trim($baseUrl ?? (string)bms_setting_or_config('base_url', '')), '/');
    if ($path === '' || $path === '/') {
        return $baseUrl !== '' ? $baseUrl . '/' : '/';
    }

    $path = '/' . ltrim($path, '/');
    return $baseUrl !== '' ? $baseUrl . $path : $path;
}

function bms_activitypub_actor_url(?string $baseUrl = null): string
{
    return bms_activitypub_absolute_url('/activitypub/actor', $baseUrl);
}

function bms_activitypub_outbox_url(?string $baseUrl = null): string
{
    return bms_activitypub_absolute_url('/activitypub/outbox', $baseUrl);
}

function bms_activitypub_inbox_url(?string $baseUrl = null): string
{
    return bms_activitypub_absolute_url('/activitypub/inbox', $baseUrl);
}

function bms_activitypub_followers_url(?string $baseUrl = null): string
{
    return bms_activitypub_absolute_url('/activitypub/followers', $baseUrl);
}

function bms_activitypub_following_url(?string $baseUrl = null): string
{
    return bms_activitypub_absolute_url('/activitypub/following', $baseUrl);
}

function bms_activitypub_object_url(int $postId, ?string $baseUrl = null): string
{
    return bms_activitypub_absolute_url('/activitypub/objects/' . max(0, $postId), $baseUrl);
}

function bms_activitypub_generation_object_url(int $postId, int $generation, ?string $baseUrl = null): string
{
    $postId = max(0, $postId);
    $generation = max(1, $generation);
    if ($generation === 1) {
        return bms_activitypub_object_url($postId, $baseUrl);
    }
    return bms_activitypub_absolute_url('/activitypub/objects/' . $postId . '/generations/' . $generation, $baseUrl);
}

function bms_activitypub_create_activity_url(int $postId, ?string $baseUrl = null): string
{
    return bms_activitypub_absolute_url('/activitypub/activities/create/' . max(0, $postId), $baseUrl);
}

function bms_activitypub_event_activity_url(int $eventId, ?string $baseUrl = null): string
{
    return bms_activitypub_absolute_url('/activitypub/activities/events/' . max(0, $eventId), $baseUrl);
}

function bms_activitypub_owner_profile_url(array $owner, ?string $baseUrl = null): string
{
    $username = bms_normalize_username((string)($owner['username'] ?? ''));
    $path = $username !== ''
        ? '/profile/' . rawurlencode($username)
        : '/profile.php?id=' . max(0, (int)($owner['id'] ?? 0));
    return bms_activitypub_absolute_url($path, $baseUrl);
}

function bms_activitypub_post_public_url(array $page, ?string $baseUrl = null): string
{
    $slug = bms_slugify((string)($page['slug'] ?? ''));
    return bms_activitypub_absolute_url('/stream/' . rawurlencode($slug) . '/', $baseUrl);
}

function bms_activitypub_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '';
    }
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return '';
    }
}

function bms_activitypub_account_host(?string $baseUrl = null): string
{
    $baseUrl = trim($baseUrl ?? (string)bms_setting_or_config('base_url', ''));
    $host = strtolower(rtrim((string)(parse_url($baseUrl, PHP_URL_HOST) ?? ''), '.'));
    $port = (int)(parse_url($baseUrl, PHP_URL_PORT) ?? 0);
    return $port > 0 && $port !== 443 ? $host . ':' . $port : $host;
}

function bms_activitypub_account_subject(array $owner, ?string $baseUrl = null): string
{
    $username = bms_normalize_username((string)($owner['username'] ?? ''));
    return 'acct:' . $username . '@' . bms_activitypub_account_host($baseUrl);
}

function bms_activitypub_profile_media_url(string $value, ?string $baseUrl = null): string
{
    $value = trim(str_replace('\\', '/', $value));
    if ($value === '' || str_contains($value, "\0") || preg_match('/[\r\n]/', $value) === 1) {
        return '';
    }
    if (preg_match('#^https?://#i', $value) === 1) {
        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }
    $value = ltrim($value, '/');
    if (!str_starts_with($value, 'media/') || preg_match('#(^|/)\.\.(/|$)#', $value) === 1) {
        return '';
    }
    if (function_exists('bms_public_path') && !is_file(bms_public_path($value))) {
        return '';
    }
    return bms_activitypub_absolute_url('/' . $value, $baseUrl);
}

function bms_activitypub_media_type(string $url): string
{
    $extension = strtolower(pathinfo((string)(parse_url($url, PHP_URL_PATH) ?? ''), PATHINFO_EXTENSION));
    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        default => 'application/octet-stream',
    };
}

function bms_activitypub_absolutize_html(string $html, ?string $baseUrl = null): string
{
    return preg_replace_callback(
        '~\b(href|src|poster)=(["\'])([^"\']+)\2~i',
        static function (array $match) use ($baseUrl): string {
            $value = html_entity_decode((string)$match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($value === ''
                || str_starts_with($value, '#')
                || preg_match('#^(?:https?|mailto|data):#i', $value) === 1
                || str_starts_with($value, '//')) {
                return (string)$match[0];
            }
            $absolute = bms_activitypub_absolute_url('/' . ltrim($value, '/'), $baseUrl);
            return (string)$match[1] . '=' . (string)$match[2] . htmlspecialchars($absolute, ENT_QUOTES, 'UTF-8') . (string)$match[2];
        },
        $html
    ) ?? $html;
}

function bms_activitypub_actor_document(array $owner, array $identity = [], ?array $signingKey = null, ?string $baseUrl = null): array
{
    $actorUrl = bms_activitypub_actor_url($baseUrl);
    $username = bms_normalize_username((string)($owner['username'] ?? ''));
    $displayName = trim((string)($owner['display_name'] ?? ''));
    $summarySource = trim((string)($identity['about_markdown'] ?? ''));
    if ($summarySource === '') {
        $summarySource = trim((string)($owner['bio'] ?? ''));
    }

    $actor = [
        '@context' => bms_activitypub_context($signingKey !== null),
        'id' => $actorUrl,
        'type' => 'Person',
        'preferredUsername' => $username,
        'name' => $displayName !== '' ? $displayName : $username,
        'summary' => $summarySource !== '' ? bms_activitypub_absolutize_html(bms_markdown_to_html($summarySource, false), $baseUrl) : '',
        'url' => bms_activitypub_owner_profile_url($owner, $baseUrl),
        'inbox' => bms_activitypub_inbox_url($baseUrl),
        'outbox' => bms_activitypub_outbox_url($baseUrl),
        'followers' => bms_activitypub_followers_url($baseUrl),
        'following' => bms_activitypub_following_url($baseUrl),
    ];

    $avatar = bms_activitypub_profile_media_url((string)($owner['avatar_path'] ?? ''), $baseUrl);
    if ($avatar !== '') {
        $actor['icon'] = [
            'type' => 'Image',
            'mediaType' => bms_activitypub_media_type($avatar),
            'url' => $avatar,
        ];
    }
    $cover = bms_activitypub_profile_media_url((string)($identity['cover_image_path'] ?? ''), $baseUrl);
    if ($cover !== '') {
        $actor['image'] = [
            'type' => 'Image',
            'mediaType' => bms_activitypub_media_type($cover),
            'url' => $cover,
        ];
    }
    if (is_array($signingKey) && trim((string)($signingKey['public_key_pem'] ?? '')) !== '') {
        $actor['publicKey'] = [
            'id' => $actorUrl . '#main-key',
            'owner' => $actorUrl,
            'publicKeyPem' => trim((string)$signingKey['public_key_pem']) . "\n",
        ];
    }

    return $actor;
}

function bms_activitypub_post_attachments(array $page, ?string $baseUrl = null): array
{
    $featured = (string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? '');
    $gallery = bms_normalize_media_gallery(
        $page['media_gallery'] ?? $page['front_matter']['media_gallery'] ?? [],
        $featured
    );
    $attachments = [];
    foreach ($gallery as $item) {
        $url = bms_activitypub_profile_media_url((string)$item, $baseUrl);
        if ($url === '' && preg_match('#^https?://#i', (string)$item) === 1) {
            $url = filter_var((string)$item, FILTER_VALIDATE_URL) ? (string)$item : '';
        }
        if ($url === '') {
            continue;
        }
        $media = null;
        $relative = trim(str_replace('\\', '/', (string)(parse_url($url, PHP_URL_PATH) ?? '')), '/');
        $basePath = trim((string)(parse_url(trim($baseUrl ?? (string)bms_setting_or_config('base_url', '')), PHP_URL_PATH) ?? ''), '/');
        if ($basePath !== '' && str_starts_with($relative, $basePath . '/')) {
            $relative = substr($relative, strlen($basePath) + 1);
        }
        if (str_starts_with($relative, 'media/') && function_exists('bms_is_installed') && bms_is_installed()) {
            try {
                $mediaStmt = bms_db()->prepare('SELECT mime_type, width, height, alt_text, original_filename FROM ' . bms_table('media') . ' WHERE public_path = :public_path LIMIT 1');
                $mediaStmt->execute(['public_path' => $relative]);
                $mediaRow = $mediaStmt->fetch();
                $media = is_array($mediaRow) ? $mediaRow : null;
            } catch (Throwable $e) {
                $media = null;
            }
        }
        $mediaType = trim((string)($media['mime_type'] ?? '')) ?: bms_activitypub_media_type($url);
        $attachment = [
            'type' => str_starts_with($mediaType, 'image/') ? 'Image' : 'Document',
            'mediaType' => $mediaType,
            'url' => $url,
            'name' => trim((string)($media['alt_text'] ?? '')) ?: (trim((string)($media['original_filename'] ?? '')) ?: rawurldecode(basename((string)(parse_url($url, PHP_URL_PATH) ?? 'Media')))),
        ];
        if ((int)($media['width'] ?? 0) > 0 && (int)($media['height'] ?? 0) > 0) {
            $attachment['width'] = (int)$media['width'];
            $attachment['height'] = (int)$media['height'];
        }
        $attachments[] = $attachment;
    }
    return $attachments;
}

function bms_activitypub_post_object(array $page, ?string $baseUrl = null, bool $includeContext = true): array
{
    $postId = (int)($page['post_id'] ?? $page['id'] ?? 0);
    $objectUri = trim((string)($page['activitypub_object_uri'] ?? ''));
    if ($objectUri === '') {
        $generation = max(1, (int)($page['activitypub_publication_generation'] ?? 1));
        $objectUri = bms_activitypub_generation_object_url($postId, $generation, $baseUrl);
    }
    $published = bms_activitypub_datetime((string)($page['published_at'] ?? $page['stream_created_at'] ?? $page['created_at'] ?? ''));
    $updated = bms_activitypub_datetime((string)($page['updated_at'] ?? ''));
    $content = bms_activitypub_absolutize_html(bms_markdown_to_html((string)($page['body'] ?? ''), true), $baseUrl);
    $followers = bms_activitypub_followers_url($baseUrl);

    $object = [
        'id' => $objectUri,
        'type' => 'Note',
        'attributedTo' => bms_activitypub_actor_url($baseUrl),
        'content' => $content,
        'mediaType' => 'text/html',
        'url' => bms_activitypub_post_public_url($page, $baseUrl),
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => [$followers],
    ];
    if ($includeContext) {
        $object = ['@context' => bms_activitypub_context()] + $object;
    }
    if ($published !== '') {
        $object['published'] = $published;
    }
    if ($updated !== '' && $updated !== $published) {
        $object['updated'] = $updated;
    }
    $attachments = bms_activitypub_post_attachments($page, $baseUrl);
    if ($attachments !== []) {
        $object['attachment'] = $attachments;
    }
    return $object;
}

function bms_activitypub_create_activity(array $page, ?string $baseUrl = null, bool $includeContext = true): array
{
    $postId = (int)($page['post_id'] ?? $page['id'] ?? 0);
    $activityUri = trim((string)($page['activitypub_create_activity_uri'] ?? ''));
    if ($activityUri === '') {
        $activityUri = bms_activitypub_create_activity_url($postId, $baseUrl);
    }
    $published = bms_activitypub_datetime((string)($page['published_at'] ?? $page['stream_created_at'] ?? $page['created_at'] ?? ''));
    $activity = [
        'id' => $activityUri,
        'type' => 'Create',
        'actor' => bms_activitypub_actor_url($baseUrl),
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => [bms_activitypub_followers_url($baseUrl)],
        'object' => bms_activitypub_post_object($page, $baseUrl, false),
    ];
    if ($includeContext) {
        $activity = ['@context' => bms_activitypub_context()] + $activity;
    }
    if ($published !== '') {
        $activity['published'] = $published;
    }
    return $activity;
}

function bms_activitypub_publication_activity(string $type, array $object, string $activityUri, ?string $baseUrl = null, bool $includeContext = true): array
{
    $type = ucfirst(strtolower(trim($type)));
    if (!in_array($type, ['Create', 'Update', 'Delete'], true)) {
        throw new InvalidArgumentException('Unsupported local publication activity type.');
    }
    $objectId = trim((string)($object['id'] ?? ''));
    if ($objectId === '') {
        throw new RuntimeException('The local publication object has no durable identity.');
    }
    $activity = [
        'id' => $activityUri,
        'type' => $type,
        'actor' => bms_activitypub_actor_url($baseUrl),
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => [bms_activitypub_followers_url($baseUrl)],
        'object' => $type === 'Delete'
            ? ['id' => $objectId, 'type' => 'Tombstone', 'formerType' => (string)($object['type'] ?? 'Note'), 'deleted' => gmdate('Y-m-d\TH:i:s\Z')]
            : array_diff_key($object, ['@context' => true]),
        'published' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    return $includeContext ? ['@context' => bms_activitypub_context()] + $activity : $activity;
}

function bms_activitypub_empty_collection_document(string $collectionUrl): array
{
    return [
        '@context' => bms_activitypub_context(),
        'id' => $collectionUrl,
        'type' => 'OrderedCollection',
        'totalItems' => 0,
        'orderedItems' => [],
    ];
}

function bms_activitypub_relationship_collection_document(string $collectionUrl, array $actorUris): array
{
    $actorUris = array_values(array_unique(array_filter(array_map('strval', $actorUris))));
    return [
        '@context' => bms_activitypub_context(),
        'id' => $collectionUrl,
        'type' => 'OrderedCollection',
        'totalItems' => count($actorUris),
        'orderedItems' => $actorUris,
    ];
}

function bms_activitypub_outbox_document(array $posts, int $total, ?int $page = null, int $perPage = 20, ?string $baseUrl = null): array
{
    $outboxUrl = bms_activitypub_outbox_url($baseUrl);
    $total = max(0, $total);
    $perPage = max(1, min(100, $perPage));
    $lastPage = max(1, (int)ceil($total / $perPage));
    if ($page === null) {
        $collection = [
            '@context' => bms_activitypub_context(),
            'id' => $outboxUrl,
            'type' => 'OrderedCollection',
            'totalItems' => $total,
        ];
        if ($total > 0) {
            $collection['first'] = $outboxUrl . '?page=1';
            $collection['last'] = $outboxUrl . '?page=' . $lastPage;
        }
        return $collection;
    }

    $page = max(1, $page);
    $document = [
        '@context' => bms_activitypub_context(),
        'id' => $outboxUrl . '?page=' . $page,
        'type' => 'OrderedCollectionPage',
        'partOf' => $outboxUrl,
        'totalItems' => $total,
        'orderedItems' => array_values(array_map(
            static fn(array $post): array => bms_activitypub_create_activity($post, $baseUrl, false),
            $posts
        )),
    ];
    if ($page > 1) {
        $document['prev'] = $outboxUrl . '?page=' . ($page - 1);
    }
    if ($page < $lastPage) {
        $document['next'] = $outboxUrl . '?page=' . ($page + 1);
    }
    return $document;
}

function bms_activitypub_webfinger_document(array $owner, ?string $baseUrl = null): array
{
    $actorUrl = bms_activitypub_actor_url($baseUrl);
    return [
        'subject' => bms_activitypub_account_subject($owner, $baseUrl),
        'aliases' => [
            $actorUrl,
            bms_activitypub_owner_profile_url($owner, $baseUrl),
        ],
        'links' => [
            [
                'rel' => 'self',
                'type' => 'application/activity+json',
                'href' => $actorUrl,
            ],
            [
                'rel' => 'http://webfinger.net/rel/profile-page',
                'type' => 'text/html',
                'href' => bms_activitypub_owner_profile_url($owner, $baseUrl),
            ],
        ],
    ];
}
