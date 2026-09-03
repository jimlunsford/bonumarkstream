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
bms_ap_following_assert((string)$presented['conversation_url'] !== '' && str_starts_with((string)$presented['reply_url'], (string)$presented['conversation_url'] . '#following-reply-'), 'Following reply navigation does not target the private conversation reply area.');
bms_ap_following_assert((string)$presented['reply_anchor_id'] !== '' && str_ends_with((string)$presented['reply_url'], '#' . (string)$presented['reply_anchor_id']), 'Following reply navigation and its reply anchor do not match.');
bms_ap_following_assert(!array_key_exists('metadata_json', $presented) && !array_key_exists('document_json', $presented), 'Raw protocol cache fields escaped into the theme model.');

$row['lifecycle_state'] = 'deleted';
$deleted = bms_activitypub_following_presentation_row($row);
bms_ap_following_assert((string)$deleted['content_html'] === '' && $deleted['media'] === [] && (string)$deleted['lifecycle_state'] === 'deleted', 'A tombstoned remote object remained visibly active.');

$template = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/views/default/templates/following.php');
$routes = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/routes.php');
$appearance = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/appearance.php');
$themes = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/themes.php');
$templateHelpers = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/views/default/templates/_helpers.php');
$commentsTemplate = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/views/default/templates/comments.php');
$followingController = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/following.php');
$followingCss = (string)file_get_contents(__DIR__ . '/../assets/following.css');
$activityPubAdmin = (string)file_get_contents(__DIR__ . '/../admin/activitypub.php');
$streamJs = (string)file_get_contents(__DIR__ . '/../assets/stream.js');
$sourceThemeCss = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/themes/default/assets/css/theme.css');
$publicThemeCss = (string)file_get_contents(__DIR__ . '/../assets/themes/default/assets/css/theme.css');
bms_ap_following_assert(str_contains($template, 'csrf_token') && str_contains($template, 'following_action'), 'Frontend federation actions are missing CSRF form boundaries.');
bms_ap_following_assert(str_contains($routes, "'following_conversation'") && str_contains($routes, 'bms_handle_activitypub_following_route'), 'Private Following routes are not core-owned.');
bms_ap_following_assert(str_contains($appearance, "'source' => 'system-following'") && str_contains($appearance, "bms_current_user_can('view_admin')"), 'Owner-only Following navigation is incomplete.');
bms_ap_following_assert(str_contains($themes, "'following'") && str_contains($themes, 'bms_public_theme_template_path($template, \'default\')') && str_contains($themes, '$privateSurface') && str_contains($themes, '!$privateSurface'), 'The core theme fallback or private analytics boundary is incomplete.');
bms_ap_following_assert(str_contains($appearance, "'bonumark-public public-theme-'"), 'The public theme class contract changed unexpectedly.');
bms_ap_following_assert(str_contains($appearance, "' context-'"), 'The public theme context class contract changed unexpectedly.');
bms_ap_following_assert(str_contains($template, 'site-main stream-shell timeline following-shell'), 'Following does not expose the semantic public Stream shell contract.');
bms_ap_following_assert(str_contains($template, 'following-card stream-card'), 'Following cards do not inherit the public Stream card surface.');
bms_ap_following_assert(!str_contains($template, 'ledger-following-shell') && !str_contains($template, 'ledger-stream-card'), 'Following core markup depends on Midnight Ledger-specific card or shell classes.');
bms_ap_following_assert(str_contains($template, 'following-card-inner stream-card-inner'), 'Following cards do not inherit the public Stream card layout.');
bms_ap_following_assert(str_contains($template, 'following-card-header stream-card-headerline'), 'Following cards do not inherit the public Stream header layout.');
bms_ap_following_assert(str_contains($template, 'following-content stream-card-content'), 'Following content does not inherit public Stream typography.');
bms_ap_following_assert(str_contains($template, 'following-media stream-card-media'), 'Following media does not reuse the public Stream media surface contract.');
bms_ap_following_assert(str_contains($template, 'following-meta stream-card-meta') && str_contains($template, 'following-actions stream-card-actions'), 'Following actions do not inherit the public Stream metadata layout.');
bms_ap_following_assert(!str_contains($template, '<div class="stream-card-tags"></div>'), 'Following retains an empty Stream tags column that can collapse the reply layout.');
bms_ap_following_assert(str_contains($commentsTemplate, 'class="comment-form"') && str_contains($commentsTemplate, 'class="comment-form-actions"') && str_contains($commentsTemplate, '>Add a comment</label>'), 'The local comment-form presentation contract changed unexpectedly.');
bms_ap_following_assert(str_contains($template, 'class="following-reply-form comment-form"') && str_contains($template, 'class="comment-form-actions"'), 'Remote replies do not reuse the local comment-form presentation contract.');
bms_ap_following_assert(str_contains($template, '>Add a reply</label>') && str_contains($template, '>Create Reply Draft</button>'), 'Remote reply labels do not match the approved frontend copy.');
bms_ap_following_assert(!str_contains($template, '>Reply text<') && !str_contains($template, '>Create Bonumark draft</button>'), 'Obsolete remote reply labels remain in the template.');
bms_ap_following_assert(str_contains($template, 'for="<?= $h($replyFieldId) ?>"') && str_contains($template, 'id="<?= $h($replyFieldId) ?>"') && str_contains($template, 'rows="4"'), 'The remote reply field lost its local-comment sizing or accessible label association.');
bms_ap_following_assert(substr_count($template, 'class="following-reply-form comment-form"') === 1, 'Following conversation replies no longer use one focused rendering path.');
bms_ap_following_assert(!str_contains($template, 'following-reply-control') && !str_contains($template, '>Reply</summary>'), 'A redundant disclosure still separates remote replies from the local comment-form experience.');
bms_ap_following_assert(str_contains($template, 'class="following-feed-item"') && str_contains($template, 'class="following-reply-region stream-comments"'), 'Remote replies do not reuse the full-width local comments surface.');
bms_ap_following_assert(str_contains($template, "!\$conversation ? ' stream-card-clickable' : ''") && str_contains($template, "!\$conversation ? ' data-stream-card data-stream-url=\"") && str_contains($template, '$h($conversationUrl)'), 'Following timeline cards do not open their private conversation view like local Stream cards.');
bms_ap_following_assert(str_contains($followingController, "bms_asset_url('assets/stream.js')") && str_contains($streamJs, 'function setupCards(root)') && str_contains($streamJs, "window.location.href = url;"), 'Following cards do not retain the core Stream card-navigation behavior.');
bms_ap_following_assert(str_contains($template, 'class="stream-meta-pill following-reply-link"') && str_contains($template, 'href="<?= $h($replyUrl) ?>">Reply</a>'), 'Following cards do not expose an explicit Reply path to the conversation composer.');
bms_ap_following_assert(str_contains($template, '$replyTarget = $conversation') && str_contains($template, '$conversationObjectUri') && str_contains($template, 'if (!$deleted && $replyTarget)'), 'The reply composer is not restricted to the selected conversation object.');
bms_ap_following_assert(preg_match('/<\/article>\s*<\?php if \(!\$deleted && \$replyTarget\): \?>\s*<section class="following-reply-region stream-comments".*?<form method="post" class="following-reply-form comment-form">/s', $template) === 1, 'The conversation reply form is not a post-card sibling discussion surface.');
bms_ap_following_assert(str_contains($template, 'id="<?= $h($replyAnchorId) ?>"'), 'The conversation reply area cannot receive the timeline Reply anchor.');
bms_ap_following_assert(str_contains($followingController, "'conversation_object_uri' => \$conversation ? \$objectUri : ''") && str_contains($followingController, "'reply_url' => \$conversationUrl . '#' . \$replyAnchorId"), 'Core does not supply the selected conversation identity and Reply destination.');
bms_ap_following_assert(str_contains($followingCss, '.following-reply-form') && str_contains($followingCss, 'box-sizing: border-box;') && str_contains($followingCss, 'max-width: 100%;') && str_contains($followingCss, 'min-width: 0;') && str_contains($followingCss, 'width: 100%;'), 'The reply composer can overflow or collapse inside the Following card.');
bms_ap_following_assert(str_contains($followingCss, '.following-feed') && str_contains($followingCss, '.following-content pre') && str_contains($followingCss, '.following-media img') && str_contains($followingCss, 'flex-wrap: wrap;'), 'The neutral Following fallback no longer prevents common overflow or action-row failures.');
bms_ap_following_assert(preg_match('/\.following-actions\s*\{[^}]*width:\s*100%;/s', $followingCss) === 1, 'The neutral Following fallback allows the reply action region to collapse below the card width.');
bms_ap_following_assert(!str_contains($followingCss, '--ledger-') && !str_contains($followingCss, 'ledger-'), 'Core Following CSS depends on Midnight Ledger-specific visual tokens or classes.');
bms_ap_following_assert(preg_match('/(?:^|\n)\s*(?:color|background|border|border-color|border-radius|border-left|box-shadow|font|font-size|font-weight|padding|margin|gap|aspect-ratio|object-fit|justify-content)\s*:/m', $followingCss) !== 1, 'Core Following CSS still owns theme appearance.');
bms_ap_following_assert(!str_contains($followingCss, '.following-reply-form button'), 'The remote reply button overrides the local comment button treatment.');
bms_ap_following_assert(str_contains($followingController, 'bms_activitypub_create_owner_reply_draft') && str_contains($followingController, "bms_admin_url('edit.php?type=draft&file='"), 'Remote replies no longer create a normal Bonumark draft.');
bms_ap_following_assert(!str_contains($template, 'following-intro') && !str_contains($template, 'Private federation') && !str_contains($template, 'cached note'), 'Following retains the removed introductory panel.');
bms_ap_following_assert(str_contains($template, 'following-conversation-nav') && str_contains($template, 'Back to Following'), 'Conversation navigation disappeared with the Following introduction.');
$coreFollowingLink = strpos($templateHelpers, "data['head_preload_html']");
$coreBaseLink = strpos($templateHelpers, "data['style_url']");
$activeThemeLinks = strpos($templateHelpers, "data['theme_stylesheet_links']");
bms_ap_following_assert($coreFollowingLink !== false && $coreBaseLink !== false && $activeThemeLinks !== false && $coreFollowingLink < $coreBaseLink && $coreBaseLink < $activeThemeLinks, 'The active theme stylesheet no longer loads after core Following and base CSS.');
bms_ap_following_assert(str_contains($followingController, "bms_asset_url('assets/following.css')") && str_contains($followingController, 'bms_public_theme_stylesheet_links()'), 'Following no longer loads both the neutral core fallback and active theme stylesheet.');
foreach ([$sourceThemeCss, $publicThemeCss] as $themeCss) {
    bms_ap_following_assert(str_contains($themeCss, 'Midnight Ledger Following presentation'), 'Midnight Ledger is missing its theme-owned Following presentation layer.');
    bms_ap_following_assert(str_contains($themeCss, 'body.bonumark-public .following-shell'), 'Following does not share the Midnight Ledger public Stream content width.');
    bms_ap_following_assert(str_contains($themeCss, 'body.bonumark-public.context-following-page .ledger-header'), 'Following masthead does not share the public Stream width.');
    bms_ap_following_assert(str_contains($themeCss, 'body.bonumark-public .stream-card-media') && str_contains($themeCss, 'body.bonumark-public.context-following-page .following-media.is-gallery') && str_contains($themeCss, 'aspect-ratio: 16 / 10;'), 'Midnight Ledger does not own Following media composition.');
    bms_ap_following_assert(str_contains($themeCss, '.following-actions > .following-action-form > .stream-meta-pill') && str_contains($themeCss, 'var(--ledger-accent)'), 'Midnight Ledger does not own Following interaction presentation.');
    bms_ap_following_assert(str_contains($themeCss, 'body.bonumark-public .comment-form') && str_contains($themeCss, 'body.bonumark-public .comment-form-actions') && !str_contains($themeCss, '.following-reply-form button'), 'Midnight Ledger no longer styles remote replies through the local comment-form contract.');
    bms_ap_following_assert(str_contains($themeCss, 'body.bonumark-public.context-following-page .following-feed-item') && str_contains($themeCss, 'body.bonumark-public.context-following-page .following-reply-region'), 'Midnight Ledger does not align the remote reply discussion surface with local comments.');
    bms_ap_following_assert(!str_contains($themeCss, 'activitypub_remote_objects') && !str_contains($themeCss, 'following_action') && !str_contains($themeCss, 'inReplyTo'), 'ActivityPub behavior or protocol data moved into theme CSS.');
}
bms_ap_following_assert(!str_contains((string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/renderer.php'), 'activitypub_remote_objects'), 'Remote content leaked into the public Stream renderer.');
bms_ap_following_assert(!str_contains((string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/sitemap.php'), 'activitypub_remote_objects'), 'Remote content leaked into sitemap rendering.');
bms_ap_following_assert(!str_contains($activityPubAdmin, 'Private owner inbox') && !str_contains($activityPubAdmin, "'owner_reply'") && !str_contains($activityPubAdmin, "'owner_like'") && !str_contains($activityPubAdmin, "'owner_announce'") && !str_contains($activityPubAdmin, "'undo_owner_interaction'"), 'Normal remote-content participation drifted back into Admin instead of remaining in Following.');

fwrite(STDOUT, "ActivityPub Stage 6.5 frontend federation unit test passed.\n");
