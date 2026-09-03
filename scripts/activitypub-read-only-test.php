<?php
/**
 * Database-free ActivityPub Stage 2 serialization, routing, and negotiation test.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

$root = dirname(__DIR__);
ini_set('session.save_path', sys_get_temp_dir());
require_once $root . '/_bonumark_stream/app/activitypub-routes.php';

function bms_activitypub_read_only_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

bms_activitypub_read_only_assert(
    defined('BMS_STATELESS_PROTOCOL_REQUEST') && BMS_STATELESS_PROTOCOL_REQUEST === true,
    'ActivityPub routes must declare a stateless protocol request before loading application authentication helpers.'
);
bms_activitypub_read_only_assert(
    session_status() !== PHP_SESSION_ACTIVE,
    'Public ActivityPub discovery and inbox routes must not start a Bonumark browser session.'
);

$baseUrl = 'https://stream.example';
$owner = [
    'id' => 1,
    'username' => 'jim',
    'display_name' => 'Jim Lunsford',
    'bio' => 'Owner bio.',
    'avatar_path' => 'https://cdn.example/avatar.jpg',
];
$identity = [
    'about_markdown' => 'Writing from [home](/stream/).',
    'cover_image_path' => 'https://cdn.example/cover.webp',
];
$post = [
    'post_id' => 42,
    'slug' => 'hello-fediverse',
    'status' => 'published',
    'post_type' => 'stream',
    'body' => "Hello **fediverse**.\n\n[Read more](/pages/about/)",
    'published_at' => '2026-08-27 12:30:00',
    'updated_at' => '2026-08-27 13:00:00',
    'featured_media' => 'https://cdn.example/photo.jpg',
    'media_gallery' => ['https://cdn.example/photo.jpg'],
];

bms_activitypub_read_only_assert(!bms_activitypub_enabled(), 'ActivityPub must remain disabled by default in Stage 2.');

bms_activitypub_read_only_assert(
    str_starts_with((string)bms_activitypub_negotiate_content_type('', false), 'application/activity+json'),
    'An absent Accept header should receive the canonical ActivityPub representation.'
);
bms_activitypub_read_only_assert(
    str_starts_with((string)bms_activitypub_negotiate_content_type('application/activity+json', false), 'application/activity+json'),
    'The ActivityPub media type must be accepted.'
);
bms_activitypub_read_only_assert(
    str_starts_with((string)bms_activitypub_negotiate_content_type('application/ld+json; profile="https://www.w3.org/ns/activitystreams"', false), 'application/ld+json'),
    'The ActivityStreams JSON-LD profile must be accepted.'
);
bms_activitypub_read_only_assert(
    str_starts_with((string)bms_activitypub_negotiate_content_type('application/json', false), 'application/json'),
    'Generic JSON clients must receive JSON.'
);
bms_activitypub_read_only_assert(
    bms_activitypub_negotiate_content_type('text/html, application/activity+json;q=0', false) === null,
    'An HTML-only request must receive 406 instead of a theme-rendered representation.'
);
bms_activitypub_read_only_assert(
    str_starts_with((string)bms_activitypub_negotiate_content_type('application/activity+json;q=0.2, application/json;q=0.9', false), 'application/json'),
    'Content negotiation must honor quality weights.'
);
bms_activitypub_read_only_assert(
    str_starts_with((string)bms_activitypub_negotiate_content_type('application/jrd+json', true), 'application/jrd+json'),
    'WebFinger must support application/jrd+json.'
);
bms_activitypub_read_only_assert(
    bms_activitypub_negotiate_content_type('application/activity+json', true) === null,
    'WebFinger must reject an unsupported ActivityPub-only representation.'
);

bms_activitypub_read_only_assert(
    bms_activitypub_webfinger_resource_matches('acct:jim@stream.example', $owner, $baseUrl),
    'The current owner acct resource must resolve.'
);
bms_activitypub_read_only_assert(
    bms_activitypub_webfinger_resource_matches('acct:JIM@STREAM.EXAMPLE', $owner, $baseUrl),
    'WebFinger account and host matching should be case-insensitive.'
);
bms_activitypub_read_only_assert(
    bms_activitypub_webfinger_resource_matches('https://stream.example/activitypub/actor', $owner, $baseUrl),
    'The stable actor URI must resolve through WebFinger.'
);
bms_activitypub_read_only_assert(
    !bms_activitypub_webfinger_resource_matches('acct:jim@elsewhere.example', $owner, $baseUrl),
    'A foreign WebFinger host must not resolve locally.'
);

$webfinger = bms_activitypub_webfinger_document($owner, $baseUrl);
bms_activitypub_read_only_assert(($webfinger['subject'] ?? '') === 'acct:jim@stream.example', 'WebFinger must publish the canonical owner subject.');
bms_activitypub_read_only_assert(($webfinger['links'][0]['href'] ?? '') === 'https://stream.example/activitypub/actor', 'WebFinger self must point to the stable actor URI.');

$key = [
    'public_key_pem' => "-----BEGIN PUBLIC KEY-----\nfixture\n-----END PUBLIC KEY-----",
    'private_key_encrypted' => 'must-not-leak',
];
$actor = bms_activitypub_actor_document($owner, $identity, $key, $baseUrl);
bms_activitypub_read_only_assert(($actor['id'] ?? '') === 'https://stream.example/activitypub/actor', 'The actor ID must not depend on the username.');
bms_activitypub_read_only_assert(($actor['url'] ?? '') === 'https://stream.example/profile/jim', 'The actor must preserve the human-facing Profile URL.');
bms_activitypub_read_only_assert(($actor['outbox'] ?? '') === 'https://stream.example/activitypub/outbox', 'The actor must link its outbox.');
bms_activitypub_read_only_assert(($actor['inbox'] ?? '') === 'https://stream.example/activitypub/inbox', 'The Stage 3 actor must advertise the signed inbox without changing its stable ID.');
bms_activitypub_read_only_assert(str_contains((string)($actor['summary'] ?? ''), 'https://stream.example/stream/'), 'Actor profile HTML must use absolute local links.');
bms_activitypub_read_only_assert(isset($actor['publicKey']['publicKeyPem']) && !str_contains(json_encode($actor) ?: '', 'must-not-leak'), 'Actor serialization may expose the public key but never protected private-key data.');

$object = bms_activitypub_post_object($post, $baseUrl);
bms_activitypub_read_only_assert(($object['id'] ?? '') === 'https://stream.example/activitypub/objects/42', 'A post object must use its stable numeric database identity.');
bms_activitypub_read_only_assert(bms_activitypub_generation_object_url(42, 2, $baseUrl) === 'https://stream.example/activitypub/objects/42/generations/2', 'Later publication generations must use deterministic generation-aware object identities.');
bms_activitypub_read_only_assert(($object['url'] ?? '') === 'https://stream.example/stream/hello-fediverse/', 'A post object must preserve the human-facing Stream Post URL.');
bms_activitypub_read_only_assert(($object['attributedTo'] ?? '') === 'https://stream.example/activitypub/actor', 'A post object must be attributed to the site owner actor.');
bms_activitypub_read_only_assert(($object['published'] ?? '') === '2026-08-27T12:30:00Z', 'Published timestamps must be canonical UTC ActivityStreams values.');
bms_activitypub_read_only_assert(($object['updated'] ?? '') === '2026-08-27T13:00:00Z', 'Updated timestamps must be canonical UTC ActivityStreams values.');
bms_activitypub_read_only_assert(str_contains((string)($object['content'] ?? ''), 'https://stream.example/pages/about/'), 'Post HTML must make local links absolute for remote readers.');
bms_activitypub_read_only_assert(($object['attachment'][0]['mediaType'] ?? '') === 'image/jpeg', 'Published media must serialize as an ActivityStreams attachment.');
bms_activitypub_read_only_assert(!isset($object['comments']) && !isset($object['likes']), 'Stage 2 objects must not invent federated comments or likes.');

$activity = bms_activitypub_create_activity($post, $baseUrl);
bms_activitypub_read_only_assert(($activity['id'] ?? '') === 'https://stream.example/activitypub/activities/create/42', 'The historical Create activity must have a stable dereferenceable URI.');
bms_activitypub_read_only_assert(($activity['object']['id'] ?? '') === ($object['id'] ?? ''), 'Create activities must embed the same stable post object.');

$outbox = bms_activitypub_outbox_document([], 41, null, 20, $baseUrl);
bms_activitypub_read_only_assert(($outbox['type'] ?? '') === 'OrderedCollection' && (int)($outbox['totalItems'] ?? 0) === 41, 'The outbox root must expose the public post count.');
bms_activitypub_read_only_assert(($outbox['first'] ?? '') === 'https://stream.example/activitypub/outbox?page=1', 'The outbox root must link its first page.');
bms_activitypub_read_only_assert(($outbox['last'] ?? '') === 'https://stream.example/activitypub/outbox?page=3', 'The outbox root must link its computed last page.');
$outboxPage = bms_activitypub_outbox_document([$post], 41, 1, 20, $baseUrl);
bms_activitypub_read_only_assert(($outboxPage['type'] ?? '') === 'OrderedCollectionPage' && count($outboxPage['orderedItems'] ?? []) === 1, 'An outbox page must embed ordered Create activities.');
bms_activitypub_read_only_assert(($outboxPage['next'] ?? '') === 'https://stream.example/activitypub/outbox?page=2', 'An outbox page must link the next page when available.');

$followers = bms_activitypub_empty_collection_document(bms_activitypub_followers_url($baseUrl));
bms_activitypub_read_only_assert(($followers['type'] ?? '') === 'OrderedCollection' && ($followers['orderedItems'] ?? null) === [], 'Stage 2 follower and following collections must be explicitly empty.');

$routeNames = bms_activitypub_route_names();
foreach (['activitypub_webfinger', 'activitypub_actor', 'activitypub_inbox', 'activitypub_outbox', 'activitypub_followers', 'activitypub_following', 'activitypub_object', 'activitypub_create_activity'] as $routeName) {
    bms_activitypub_read_only_assert(in_array($routeName, $routeNames, true), 'The Stage 2 route registry is missing ' . $routeName . '.');
}
$indexSource = (string)file_get_contents($root . '/index.php');
$apacheSource = (string)file_get_contents($root . '/.htaccess');
$nginxSource = (string)file_get_contents($root . '/docs/server/bonumark-stream-nginx.conf');
foreach (['activitypub_webfinger', 'activitypub_actor', 'activitypub_outbox', 'activitypub_object', 'activitypub_create_activity'] as $routeName) {
    bms_activitypub_read_only_assert(str_contains($indexSource . $apacheSource . $nginxSource, $routeName), 'Web-server routing is missing ' . $routeName . '.');
}
$routeSource = (string)file_get_contents($root . '/_bonumark_stream/app/activitypub-routes.php');
bms_activitypub_read_only_assert(!str_contains($routeSource, 'curl_') && !str_contains($routeSource, 'file_get_contents("http'), 'Stage 2 routes must not contain outbound network behavior.');
bms_activitypub_read_only_assert(str_contains($routeSource, 'activitypub_inbox'), 'Stage 3 must add inbox processing without changing the read-only discovery routes.');

fwrite(STDOUT, "ActivityPub Stage 2 read-only identity and discovery test passed.\n");
