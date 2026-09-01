<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../_bonumark_stream/app/functions.php';
require_once __DIR__ . '/../_bonumark_stream/app/activitypub-inbox.php';
require_once __DIR__ . '/../_bonumark_stream/app/following.php';

function bms_ap_following_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

bms_ap_following_assert(bms_activitypub_following_access_state(false, true, true, false) === 'not_found', 'Disabled ActivityPub exposed Following.');
bms_ap_following_assert(bms_activitypub_following_access_state(true, false, false, false) === 'login', 'Logged-out Following did not require authentication.');
bms_ap_following_assert(bms_activitypub_following_access_state(true, true, false, false) === 'not_found', 'A Commenter received Following access.');
bms_ap_following_assert(bms_activitypub_following_access_state(true, true, true, true) === 'not_found', 'Static export received Following access.');
bms_ap_following_assert(bms_activitypub_following_access_state(true, true, true, false) === 'allowed', 'The authenticated owner could not access Following.');

$note = [
    'id' => 'https://remote.example/notes/frontend',
    'type' => 'Note',
    'attributedTo' => 'https://remote.example/users/owner',
    'content' => '<p onclick="evil()">Safe <strong>content</strong>.</p><script>alert(1)</script><a href="javascript:alert(1)">bad</a>',
    'url' => 'https://remote.example/@owner/frontend',
    'inReplyTo' => 'https://remote.example/notes/parent',
    'summary' => '<b>Content warning</b>',
    'sensitive' => true,
    'published' => '2026-09-01T00:00:00Z',
    'attachment' => [
        ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://media.remote.example/photo.jpg', 'name' => '<b>Useful alt text</b>', 'width' => 1200, 'height' => 800],
        ['type' => 'Image', 'mediaType' => 'image/svg+xml', 'url' => 'https://media.remote.example/active.svg', 'name' => 'Rejected active format'],
        ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'file:///etc/passwd', 'name' => 'Rejected URL'],
    ],
];
$stored = bms_activitypub_remote_note_data($note, 'https://remote.example/users/owner');
$metadata = json_decode((string)$stored['metadata_json'], true);
bms_ap_following_assert(count((array)($metadata['media'] ?? [])) === 1, 'Unsafe or unsupported remote media entered the cache model.');
bms_ap_following_assert((string)($metadata['media'][0]['alt_text'] ?? '') === 'Useful alt text', 'Remote media alt text was not normalized.');

$row = array_merge($stored, [
    'id' => 9,
    'actor_uri' => 'https://remote.example/users/owner',
    'preferred_username' => 'owner',
    'display_name' => '<b>Remote Owner</b>',
    'document_json' => json_encode(['icon' => ['type' => 'Image', 'url' => 'https://media.remote.example/avatar.jpg']], JSON_UNESCAPED_SLASHES),
    'lifecycle_state' => 'active',
    'created_at' => '2026-09-01 00:00:00',
    'like_interaction_id' => 15,
    'like_state' => 'active',
    'like_last_error' => '<script>error</script>Like queued',
    'announce_interaction_id' => null,
    'announce_state' => null,
    'announce_last_error' => null,
]);
$presented = bms_activitypub_following_presentation_row($row);
bms_ap_following_assert(str_contains((string)$presented['content_html'], '<strong>content</strong>') && !str_contains((string)$presented['content_html'], '<script'), 'The theme presentation model exposed unsafe remote HTML.');
bms_ap_following_assert((string)$presented['actor_name'] === 'Remote Owner' && (string)$presented['actor_handle'] === '@owner@remote.example', 'Remote actor presentation identity is invalid.');
bms_ap_following_assert((string)$presented['actor_avatar_url'] === 'https://media.remote.example/avatar.jpg', 'A safe remote avatar was not exposed through the presentation model.');
bms_ap_following_assert(!empty($presented['like']['active']) && (int)$presented['like']['interaction_id'] === 15, 'Owner Like state did not survive a presentation reload.');
bms_ap_following_assert((string)$presented['in_reply_to'] === 'https://remote.example/notes/parent', 'Conversation identity was not retained.');
bms_ap_following_assert(!array_key_exists('metadata_json', $presented) && !array_key_exists('document_json', $presented), 'Raw protocol cache fields escaped into the theme model.');

$row['lifecycle_state'] = 'deleted';
$deleted = bms_activitypub_following_presentation_row($row);
bms_ap_following_assert((string)$deleted['content_html'] === '' && $deleted['media'] === [] && (string)$deleted['lifecycle_state'] === 'deleted', 'A tombstoned remote object remained visibly active.');

$template = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/views/default/templates/following.php');
$routes = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/routes.php');
$appearance = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/appearance.php');
$themes = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/themes.php');
$followingCss = (string)file_get_contents(__DIR__ . '/../assets/following.css');
bms_ap_following_assert(str_contains($template, 'csrf_token') && str_contains($template, 'following_action'), 'Frontend federation actions are missing CSRF form boundaries.');
bms_ap_following_assert(str_contains($routes, "'following_conversation'") && str_contains($routes, 'bms_handle_activitypub_following_route'), 'Private Following routes are not core-owned.');
bms_ap_following_assert(str_contains($appearance, "'source' => 'system-following'") && str_contains($appearance, "bms_current_user_can('view_admin')"), 'Owner-only Following navigation is incomplete.');
bms_ap_following_assert(str_contains($themes, "'following'") && str_contains($themes, '$privateSurface') && str_contains($themes, '!$privateSurface'), 'The core theme fallback or private analytics boundary is incomplete.');
bms_ap_following_assert(str_contains($appearance, "'bonumark-public public-theme-'"), 'The public theme class contract changed unexpectedly.');
bms_ap_following_assert(str_contains($appearance, "' context-'"), 'The public theme context class contract changed unexpectedly.');
bms_ap_following_assert(str_contains($followingCss, 'body.bonumark-public.context-following-page .following-shell'), 'Following styles do not target the actual core theme context class.');
bms_ap_following_assert(!str_contains($followingCss, 'body.bonumark-public.following-page '), 'Following styles retain the invalid pre-fix body selector.');
bms_ap_following_assert(str_contains($template, 'following-card stream-card ledger-stream-card'), 'Following cards do not inherit the public Stream card surface.');
bms_ap_following_assert(str_contains($template, 'following-card-inner stream-card-inner'), 'Following cards do not inherit the public Stream card layout.');
bms_ap_following_assert(str_contains($template, 'following-card-header stream-card-headerline'), 'Following cards do not inherit the public Stream header layout.');
bms_ap_following_assert(str_contains($template, 'following-content stream-card-content'), 'Following content does not inherit public Stream typography.');
bms_ap_following_assert(str_contains($template, 'following-meta stream-card-meta') && str_contains($template, 'following-actions stream-card-actions'), 'Following actions do not inherit the public Stream metadata layout.');
bms_ap_following_assert(!str_contains((string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/renderer.php'), 'activitypub_remote_objects'), 'Remote content leaked into the public Stream renderer.');
bms_ap_following_assert(!str_contains((string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/sitemap.php'), 'activitypub_remote_objects'), 'Remote content leaked into sitemap rendering.');

fwrite(STDOUT, "ActivityPub Stage 6.5 frontend federation unit test passed.\n");
