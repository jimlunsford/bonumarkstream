<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../_bonumark_stream/app/functions.php';
require_once __DIR__ . '/../_bonumark_stream/app/activitypub-inbox.php';

function bms_ap_stage6_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$target = 'https://remote.example/notes/one';
$likeKey = bms_activitypub_owner_interaction_key('Like', $target);
bms_ap_stage6_assert(strlen($likeKey) === 64, 'The owner interaction key is not a SHA-256 identity.');
bms_ap_stage6_assert($likeKey === bms_activitypub_owner_interaction_key('Like', $target), 'Owner interaction identity is not deterministic.');
bms_ap_stage6_assert($likeKey !== bms_activitypub_owner_interaction_key('Announce', $target), 'Owner Like and Announce state were conflated.');
bms_ap_stage6_assert($likeKey !== bms_activitypub_owner_interaction_key('Like', 'https://remote.example/notes/two'), 'Owner interactions do not distinguish exact remote objects.');

$followUri = bms_activitypub_owner_activity_url('follow', 'https://local.example');
$follow = bms_activitypub_owner_action_document('Follow', $followUri, 'https://remote.example/actor', 'https://remote.example/actor');
bms_ap_stage6_assert(($follow['type'] ?? '') === 'Follow' && ($follow['object'] ?? '') === 'https://remote.example/actor', 'Outbound Follow serialization is invalid.');
$publicResolver = static fn(string $host): array => ['93.184.216.34'];
$webfingerRequests = [];
$webfingerFetcher = static function (string $url, string $resource) use (&$webfingerRequests): array {
    $webfingerRequests[] = ['url' => $url, 'resource' => $resource];
    return [
        'subject' => $resource,
        'links' => [[
            'rel' => 'self',
            'type' => 'application/activity+json',
            'href' => 'https://remote.example/users/owner',
        ]],
    ];
};
bms_ap_stage6_assert(
    bms_activitypub_resolve_owner_actor_reference('@owner@remote.example', $webfingerFetcher, $publicResolver) === 'https://remote.example/users/owner',
    'A normal fediverse handle did not resolve to its canonical actor URI.'
);
bms_ap_stage6_assert(
    bms_activitypub_resolve_owner_actor_reference('https://remote.example/@owner', $webfingerFetcher, $publicResolver) === 'https://remote.example/users/owner',
    'A normal fediverse profile URL did not resolve through WebFinger.'
);
bms_ap_stage6_assert(
    bms_activitypub_resolve_owner_actor_reference('https://remote.example/users/owner', $webfingerFetcher, $publicResolver) === 'https://remote.example/users/owner',
    'A canonical actor URI was changed during owner Follow discovery.'
);
bms_ap_stage6_assert(count($webfingerRequests) === 2, 'Canonical actor discovery unexpectedly used WebFinger.');
bms_ap_stage6_assert(
    ($webfingerRequests[0]['resource'] ?? '') === 'acct:owner@remote.example'
        && str_contains((string)($webfingerRequests[0]['url'] ?? ''), '/.well-known/webfinger?resource='),
    'Handle discovery did not request the expected bounded WebFinger resource.'
);
$wrongSubjectFetcher = static fn(string $url, string $resource): array => [
    'subject' => 'acct:other@remote.example',
    'links' => [['rel' => 'self', 'type' => 'application/activity+json', 'href' => 'https://remote.example/users/owner']],
];
try {
    bms_activitypub_resolve_owner_actor_reference('@owner@remote.example', $wrongSubjectFetcher, $publicResolver);
    throw new RuntimeException('A mismatched WebFinger subject was accepted.');
} catch (BmsActivityPubSecurityException $e) {
    bms_ap_stage6_assert($e->httpStatus() === 502, 'A mismatched WebFinger subject returned the wrong status.');
}
try {
    bms_activitypub_resolve_owner_actor_reference('@owner@evil.example', $webfingerFetcher, static fn(string $host): array => ['127.0.0.1']);
    throw new RuntimeException('A WebFinger SSRF destination was accepted.');
} catch (BmsActivityPubSecurityException $e) {
    bms_ap_stage6_assert(in_array($e->httpStatus(), [400, 403], true), 'A WebFinger SSRF destination returned the wrong status.');
}
$undoUri = bms_activitypub_owner_activity_url('undo-follow', 'https://local.example');
$undo = bms_activitypub_owner_action_document('Undo', $undoUri, 'https://remote.example/actor', 'https://remote.example/actor', $follow);
bms_ap_stage6_assert(
    ($undo['type'] ?? '') === 'Undo'
        && is_array($undo['object'] ?? null)
        && ($undo['object']['id'] ?? '') === $followUri
        && ($undo['object']['type'] ?? '') === 'Follow'
        && ($undo['object']['object'] ?? '') === 'https://remote.example/actor',
    'Undo Follow does not embed the exact durable Follow.'
);
$likeUri = bms_activitypub_owner_activity_url('like', 'https://local.example');
$like = bms_activitypub_owner_action_document('Like', $likeUri, 'https://remote.example/actor', $target);
$undoLike = bms_activitypub_owner_action_document(
    'Undo',
    bms_activitypub_owner_activity_url('undo-like', 'https://local.example'),
    'https://remote.example/actor',
    $target,
    $like
);
bms_ap_stage6_assert(
    is_array($undoLike['object'] ?? null)
        && ($undoLike['object']['id'] ?? '') === $likeUri
        && ($undoLike['object']['type'] ?? '') === 'Like'
        && ($undoLike['object']['object'] ?? '') === $target,
    'Undo Like does not embed the exact original Like for Mastodon interoperability.'
);
try {
    bms_activitypub_owner_action_document(
        'Undo',
        bms_activitypub_owner_activity_url('undo-like', 'https://local.example'),
        'https://remote.example/actor',
        $target,
        $likeUri
    );
    throw new RuntimeException('A reference-only owner Undo was accepted.');
} catch (InvalidArgumentException) {
}
bms_ap_stage6_assert($followUri !== bms_activitypub_owner_activity_url('follow', 'https://local.example'), 'A later owner activity reused an immutable activity identity.');
$announce = bms_activitypub_owner_action_document('Announce', bms_activitypub_owner_activity_url('announce', 'https://local.example'), 'https://remote.example/actor', $target, '', true);
bms_ap_stage6_assert(($announce['to'][0] ?? '') === 'https://www.w3.org/ns/activitystreams#Public' && in_array('https://remote.example/actor', (array)($announce['cc'] ?? []), true), 'An owner Announce was not serialized as a public boost addressed to the remote actor.');

$actorDeleteUri = bms_activitypub_owner_activity_url('delete-actor', 'https://local.example');
$actorDelete = bms_activitypub_actor_delete_document($actorDeleteUri, 'https://local.example');
bms_ap_stage6_assert(
    ($actorDelete['id'] ?? '') === $actorDeleteUri
        && ($actorDelete['type'] ?? '') === 'Delete'
        && ($actorDelete['actor'] ?? '') === 'https://local.example/activitypub/actor'
        && ($actorDelete['object'] ?? '') === 'https://local.example/activitypub/actor'
        && in_array('https://www.w3.org/ns/activitystreams#Public', (array)($actorDelete['to'] ?? []), true),
    'Permanent deactivation must serialize one explicit public Actor Delete for the stable actor URI.'
);

$retirementFixture = ['retired_at' => '2026-09-03 03:00:00'];
$actorTombstone = bms_activitypub_actor_tombstone_document($retirementFixture, 'https://local.example');
bms_ap_stage6_assert(
    ($actorTombstone['id'] ?? '') === 'https://local.example/activitypub/actor'
        && ($actorTombstone['type'] ?? '') === 'Tombstone'
        && ($actorTombstone['formerType'] ?? '') === 'Person'
        && ($actorTombstone['deleted'] ?? '') === '2026-09-03T03:00:00Z',
    'A retired actor must dereference as a generation-independent Person Tombstone.'
);

$ownerSource = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/activitypub-owner.php');
$operationsSource = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/activitypub-operations.php');
$inboxSource = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/activitypub-inbox.php');
$adminSource = (string)file_get_contents(__DIR__ . '/../admin/activitypub.php');
$routeSource = (string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/activitypub-routes.php');
$activityPubDocumentation = (string)file_get_contents(__DIR__ . '/../docs/ACTIVITYPUB.md');
bms_ap_stage6_assert(substr_count($ownerSource, "bms_activitypub_owner_activity_url('delete-actor')") === 1, 'Actor Delete must only originate from explicit permanent deactivation.');
bms_ap_stage6_assert(
    str_contains($ownerSource, "['owner_activity', 'actor_delete']")
        && str_contains($ownerSource, '$deliveryType === \'actor_delete\' ? [\'Delete\']'),
    'The retired-actor worker must permit only the durable Actor Delete through the owner delivery boundary.'
);
foreach (['publishing system first', 'publication generations', 'Permanently deactivated', 'queue operations', 'Signing keys', 'Hosting requirements', 'Misskey.io', 'NodeInfo is intentionally not implemented'] as $documentedContract) {
    bms_ap_stage6_assert(str_contains($activityPubDocumentation, $documentedContract), 'ActivityPub operator documentation is missing: ' . $documentedContract);
}
bms_ap_stage6_assert(
    str_contains($inboxSource, "return 'remote_actor_deleted'")
        && str_contains($inboxSource, "lifecycle_state = 'deleted'")
        && str_contains($inboxSource, "state = 'removed'")
        && str_contains($inboxSource, "The remote actor is permanently deleted."),
    'Authenticated remote Actor Delete must retire cached actor identity and active relationships without permitting rediscovery resurrection.'
);
bms_ap_stage6_assert(
    str_contains($inboxSource, 'if ($status === 410)')
        && str_contains($inboxSource, "WHEN :http_status = 404 THEN 'unavailable'"),
    'Remote actor dereference must distinguish permanent HTTP 410 deletion from recoverable HTTP 404 unavailability.'
);
bms_ap_stage6_assert(
    str_contains($operationsSource, "o.lifecycle_state = 'active'")
        && str_contains($operationsSource, 'f.id IS NULL AND i.id IS NULL AND r.id IS NULL')
        && !str_contains($operationsSource, "DELETE FROM ' . bms_table('activitypub_remote_actors')"),
    'Remote cache cleanup must remove only expired unreferenced active objects and retain remote actor audit identity.'
);
bms_ap_stage6_assert(str_contains($adminSource, 'PERMANENTLY DELETE FEDERATED ACTOR'), 'Permanent deactivation must require exact irreversible confirmation text.');
bms_ap_stage6_assert(str_contains($routeSource, 'bms_activitypub_actor_tombstone_document') && str_contains($routeSource, "'activitypub_webfinger'"), 'Retired actor routing must serve an actor Tombstone and stop live WebFinger discovery.');
foreach (['posts', 'comments', 'likes', 'media', 'pages', 'themes'] as $localTable) {
    bms_ap_stage6_assert(!preg_match('/(?:DELETE FROM|UPDATE)\s+[^;\n]*' . preg_quote($localTable, '/') . '/i', $ownerSource), 'Permanent federation deactivation must not mutate local ' . $localTable . ' content.');
}
bms_ap_stage6_assert(
    str_contains($operationsSource, "delivery_type <> 'actor_delete'")
        && str_contains($operationsSource, 'bms_activitypub_actor_is_retired() && $type !== \'actor_delete\'')
        && str_contains($operationsSource, 'bms_activitypub_validate_delivery_for_retry'),
    'Queue repair must protect Actor Delete from cancellation and prevent retired identities from retrying ordinary work.'
);
bms_ap_stage6_assert(
    str_contains($operationsSource, 'bms_activitypub_update_publication_event_status')
        && str_contains($operationsSource, 'bms_activitypub_mark_owner_delivery_result'),
    'Queue repair must reconcile delivery changes back to their durable publication or owner-action parent state.'
);

$note = [
    'id' => $target,
    'type' => 'Note',
    'attributedTo' => 'https://remote.example/actor',
    'content' => '<p onclick="evil()">Safe <strong>remote</strong> text.</p><script>alert(1)</script><a href="javascript:alert(1)">bad</a>',
    'url' => 'https://remote.example/@owner/one',
    'summary' => '<b>summary</b>',
    'published' => '2026-08-31T12:00:00Z',
];
$data = bms_activitypub_remote_note_data($note, 'https://remote.example/actor');
bms_ap_stage6_assert(($data['object_uri'] ?? '') === $target, 'Remote Note identity was not retained.');
bms_ap_stage6_assert(!str_contains((string)$data['content_html'], '<script') && !str_contains((string)$data['content_html'], 'onclick') && !str_contains((string)$data['content_html'], 'javascript:'), 'Remote Note HTML was not sanitized.');
bms_ap_stage6_assert(str_contains((string)$data['content_text'], 'Safe remote text.'), 'Safe remote Note text was not retained.');
$maliciousNote = $note;
$maliciousNote['url'] = 'javascript:alert(1)';
$maliciousNote['inReplyTo'] = 'file:///etc/passwd';
$maliciousData = bms_activitypub_remote_note_data($maliciousNote, 'https://remote.example/actor');
$maliciousMetadata = json_decode((string)$maliciousData['metadata_json'], true);
bms_ap_stage6_assert($maliciousData['human_url'] === null && (string)($maliciousMetadata['inReplyTo'] ?? '') === '', 'Unsafe remote object links entered the owner inbox cache.');

try {
    bms_activitypub_remote_note_data($note, 'https://other.example/actor');
    throw new RuntimeException('A spoofed remote Note actor was accepted.');
} catch (BmsActivityPubSecurityException $e) {
    bms_ap_stage6_assert($e->httpStatus() === 403, 'A spoofed remote Note returned the wrong status.');
}

foreach (['http://remote.example/notes/one', 'https://127.0.0.1/private', 'https://user:pass@remote.example/notes/one'] as $unsafe) {
    try {
        bms_activitypub_identifier_uri($unsafe, false);
        if (str_starts_with($unsafe, 'https://127.0.0.1')) {
            bms_activitypub_validate_remote_url($unsafe, static fn(string $host): array => ['127.0.0.1'], false);
        }
        throw new RuntimeException('An unsafe Stage 6 target was accepted: ' . $unsafe);
    } catch (BmsActivityPubSecurityException $e) {
        bms_ap_stage6_assert(in_array($e->httpStatus(), [400, 403], true), 'An unsafe Stage 6 target returned the wrong status.');
    }
}

fwrite(STDOUT, "ActivityPub Stage 6 owner participation unit test passed.\n");
