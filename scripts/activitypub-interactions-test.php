<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

require_once __DIR__ . '/../_bonumark_stream/app/functions.php';
require_once __DIR__ . '/../_bonumark_stream/app/activitypub-inbox.php';

function bms_ap_stage5_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$sanitized = bms_activitypub_sanitize_remote_html(
    '<p onclick="evil()">Safe <strong>text</strong>.</p>'
    . '<script>alert(1)</script><style>body{display:none}</style>'
    . '<p><a href="javascript:alert(1)">bad</a> '
    . '<a href="https://remote.example/path" target="_blank">good</a></p>'
    . '<img src="https://remote.example/tracker" onerror="evil()">'
);
bms_ap_stage5_assert(!str_contains((string)$sanitized['html'], '<script'), 'Remote scripts were not removed.');
bms_ap_stage5_assert(!str_contains((string)$sanitized['html'], '<style'), 'Remote styles were not removed.');
bms_ap_stage5_assert(!str_contains((string)$sanitized['html'], '<img'), 'Remote tracking images were not removed.');
bms_ap_stage5_assert(!str_contains((string)$sanitized['html'], 'javascript:'), 'A dangerous remote link survived sanitization.');
bms_ap_stage5_assert(!str_contains((string)$sanitized['html'], 'onclick'), 'A remote event handler survived sanitization.');
bms_ap_stage5_assert(str_contains((string)$sanitized['html'], 'https://remote.example/path'), 'A safe HTTPS link was removed.');
bms_ap_stage5_assert(str_contains((string)$sanitized['html'], 'nofollow noopener noreferrer'), 'A remote link is missing safety relationship attributes.');
bms_ap_stage5_assert(str_contains((string)$sanitized['text'], 'Safe text.'), 'Sanitized remote plain text was not retained.');

bms_ap_stage5_assert(bms_activitypub_remote_link_url('https://remote.example/profile') === 'https://remote.example/profile', 'A safe remote profile URL was rejected.');
foreach (['http://remote.example/profile', 'javascript:alert(1)', 'https://user:pass@remote.example/profile', "https://remote.example/\nheader"] as $unsafeUrl) {
    bms_ap_stage5_assert(bms_activitypub_remote_link_url($unsafeUrl) === '', 'An unsafe remote URL was accepted: ' . $unsafeUrl);
}

$key = bms_activitypub_interaction_semantic_key('https://remote.example/actor', 'Like', 'https://local.example/activitypub/objects/1/generations/9');
bms_ap_stage5_assert(strlen($key) === 64, 'The interaction semantic key is not a SHA-256 identity.');
bms_ap_stage5_assert($key === bms_activitypub_interaction_semantic_key('https://remote.example/actor', 'Like', 'https://local.example/activitypub/objects/1/generations/9'), 'Interaction semantic identity is not deterministic.');
bms_ap_stage5_assert($key !== bms_activitypub_interaction_semantic_key('https://remote.example/actor', 'Like', 'https://local.example/activitypub/objects/1/generations/8'), 'Interaction identity migrated between publication generations.');
bms_ap_stage5_assert($key !== bms_activitypub_interaction_semantic_key('https://remote.example/other', 'Like', 'https://local.example/activitypub/objects/1/generations/9'), 'Interaction identity does not distinguish remote actors.');
bms_ap_stage5_assert($key !== bms_activitypub_interaction_semantic_key('https://remote.example/actor', 'Announce', 'https://local.example/activitypub/objects/1/generations/9'), 'Like and Announce identities were conflated.');

try {
    bms_activitypub_sanitize_remote_html(str_repeat('x', 65537));
    throw new RuntimeException('Oversized remote Note content was accepted.');
} catch (BmsActivityPubSecurityException $e) {
    bms_ap_stage5_assert($e->httpStatus() === 413, 'Oversized remote Note content returned the wrong status.');
}

fwrite(STDOUT, "ActivityPub Stage 5 interaction unit test passed.\n");
