<?php
/**
 * Database-free ActivityPub Stage 1 foundation test.
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
require_once $root . '/_bonumark_stream/app/scheduler.php';

function bms_activitypub_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

bms_activitypub_test_assert(!bms_activitypub_enabled(), 'ActivityPub must be disabled by default.');
bms_activitypub_test_assert(bms_activitypub_operational_state() === 'disabled', 'An unused default-off installation must report disabled state.');
bms_activitypub_test_assert(!bms_activitypub_accepts_inbox(), 'A disabled installation must not accept inbox traffic.');
bms_activitypub_test_assert(!bms_activitypub_records_publications(), 'A disabled installation must not record federation publication work.');
bms_activitypub_test_assert(!bms_activitypub_runs_deliveries(), 'A disabled installation must not run federation deliveries.');

$activityPubAdminSource = (string)file_get_contents($root . '/admin/activitypub.php');
$systemCheckAdminSource = (string)file_get_contents($root . '/admin/system-check.php');
$schedulerRequire = "require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';";
bms_activitypub_test_assert(
    str_contains($activityPubAdminSource, $schedulerRequire),
    'The ActivityPub Admin readiness check must load scheduled-task health before reporting delivery capability.'
);

foreach (['_bonumark_stream/app/activitypub-delivery.php', '_bonumark_stream/app/activitypub-inbox.php', '_bonumark_stream/app/activitypub-owner.php'] as $workerFile) {
    $workerSource = (string)file_get_contents($root . '/' . $workerFile);
    bms_activitypub_test_assert(
        str_contains($workerSource, 'bms_activitypub_runs_deliveries()'),
        $workerFile . ' must honor both the federation pause and outbound delivery suspension boundaries.'
    );
}
bms_activitypub_test_assert(
    str_contains($systemCheckAdminSource, $schedulerRequire),
    'System Check must load scheduled-task health before reporting ActivityPub delivery capability.'
);

$runnerNow = (new DateTimeImmutable('2026-08-28 14:20:00', new DateTimeZone('UTC')))->getTimestamp();
$recentDurableRun = [[
    'source' => 'server_cron',
    'status' => 'completed',
    'completed_at' => '2026-08-28 14:15:01',
]];
bms_activitypub_test_assert(
    bms_activitypub_recent_durable_task_history($recentDurableRun, 900, $runnerNow),
    'A recent completed server-cron history row must remain durable evidence after a fallback overwrites the last task source.'
);
bms_activitypub_test_assert(
    !bms_activitypub_recent_durable_task_history([array_merge($recentDurableRun[0], ['status' => 'error'])], 900, $runnerNow),
    'A failed durable task run must not satisfy federation delivery readiness.'
);
bms_activitypub_test_assert(
    !bms_activitypub_recent_durable_task_history([array_merge($recentDurableRun[0], ['source' => 'public_traffic'])], 900, $runnerNow),
    'A public-traffic fallback must not satisfy federation delivery readiness.'
);
bms_activitypub_test_assert(
    !bms_activitypub_recent_durable_task_history([array_merge($recentDurableRun[0], ['completed_at' => '2026-08-28 14:00:00'])], 900, $runnerNow),
    'A stale durable task run must not satisfy federation delivery readiness.'
);

bms_activitypub_test_assert(!empty(bms_activitypub_configured_base_url('https://example.com')['ok']), 'A canonical HTTPS root URL should be accepted.');
bms_activitypub_test_assert(empty(bms_activitypub_configured_base_url('http://example.com')['ok']), 'An HTTP canonical URL must be rejected.');
bms_activitypub_test_assert(empty(bms_activitypub_configured_base_url('https://example.com/?debug=1')['ok']), 'A canonical URL with a query string must be rejected.');
bms_activitypub_test_assert(!empty(bms_activitypub_webfinger_routing_capability('https://example.com', '')['ok']), 'A root installation should be eligible for WebFinger routing.');
bms_activitypub_test_assert(empty(bms_activitypub_webfinger_routing_capability('https://example.com/blog', '/blog')['ok']), 'A subdirectory installation must require domain-root WebFinger mapping.');

$published = [
    'id' => 41,
    'post_type' => 'stream',
    'status' => 'published',
    'slug' => 'foundation-test',
    'content_hash' => str_repeat('a', 64),
];
$updated = array_merge($published, ['content_hash' => str_repeat('b', 64)]);
$renamed = array_merge($published, ['slug' => 'foundation-test-renamed']);
$draft = array_merge($updated, ['id' => 84, 'status' => 'draft']);
$page = array_merge($published, ['post_type' => 'page']);

$publishTransition = bms_publication_transition(null, $published, ['source' => 'test']);
bms_activitypub_test_assert(($publishTransition['event_type'] ?? '') === 'published', 'A new published Stream Post must produce a published transition.');
$updateTransition = bms_publication_transition($published, $updated, ['source' => 'test']);
bms_activitypub_test_assert(($updateTransition['event_type'] ?? '') === 'updated', 'A changed published Stream Post must produce an updated transition.');
bms_activitypub_test_assert((bms_publication_transition($published, $renamed, ['source' => 'test'])['event_type'] ?? '') === 'updated', 'A changed public slug must produce an updated transition even when the content hash is unchanged.');
bms_activitypub_test_assert(bms_publication_transition($published, $published, ['source' => 'test']) === null, 'An unchanged published Stream Post must not produce a transition.');
$unpublishTransition = bms_publication_transition($updated, $draft, ['source' => 'test']);
bms_activitypub_test_assert(($unpublishTransition['event_type'] ?? '') === 'unpublished', 'Moving a published Stream Post to draft must produce an unpublished transition.');
bms_activitypub_test_assert((int)($unpublishTransition['post_id'] ?? 0) === 41, 'An unpublish transition must retain the published post identity.');
$deleteTransition = bms_publication_transition($updated, null, ['source' => 'test']);
bms_activitypub_test_assert(($deleteTransition['event_type'] ?? '') === 'deleted', 'Deleting a published Stream Post must produce a deleted transition.');
bms_activitypub_test_assert(bms_publication_transition(null, $page, ['source' => 'test']) === null, 'Pages must remain outside the ActivityPub publication seam.');

$observed = [];
bms_register_publication_transition_handler('foundation_test', static function (array $transition) use (&$observed): void {
    $observed[] = $transition;
});
bms_dispatch_publication_transition(null, $published, ['source' => 'foundation_test']);
bms_activitypub_test_assert(count($observed) === 1 && ($observed[0]['event_type'] ?? '') === 'published', 'Registered publication handlers must receive normalized transitions.');

bms_register_scheduled_task_handler('foundation_test', static fn(array $context): array => [
    'ok' => true,
    'count' => 2,
    'message' => 'Foundation handler ran from ' . (string)($context['source'] ?? 'unknown') . '.',
], [
    'label' => 'Foundation test',
    'allowed_sources' => ['server_cron'],
]);
$publicTaskResults = bms_run_registered_scheduled_tasks('public_traffic', ['scheduled_post_limit' => 1]);
bms_activitypub_test_assert(($publicTaskResults['foundation_test']['status'] ?? '') === 'skipped', 'Durable-only task handlers must be skipped on public traffic.');
$cronTaskResults = bms_run_registered_scheduled_tasks('server_cron', ['scheduled_post_limit' => 1]);
bms_activitypub_test_assert((int)($cronTaskResults['foundation_test']['count'] ?? 0) === 2, 'A registered handler must run from an allowed source.');
bms_activitypub_test_assert(isset($cronTaskResults['scheduled_posts']), 'The existing scheduled-post handler must remain registered.');

if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
    throw new RuntimeException('OpenSSL is required for the ActivityPub foundation test.');
}
$encryptionKey = random_bytes(32);
$privateKeyFixture = "-----BEGIN PRIVATE KEY-----\nfoundation-test\n-----END PRIVATE KEY-----\n";
$encrypted = bms_activitypub_encrypt_private_key_with_key($privateKeyFixture, $encryptionKey);
bms_activitypub_test_assert($encrypted !== $privateKeyFixture, 'A private key must not be stored as plaintext.');
bms_activitypub_test_assert(bms_activitypub_decrypt_private_key_with_key($encrypted, $encryptionKey) === $privateKeyFixture, 'An encrypted private key must round trip with the installation-derived key.');
$wrongKeyRejected = false;
try {
    bms_activitypub_decrypt_private_key_with_key($encrypted, random_bytes(32));
} catch (RuntimeException $e) {
    $wrongKeyRejected = true;
}
bms_activitypub_test_assert($wrongKeyRejected, 'An encrypted private key must reject the wrong installation-derived key.');

$generatedKey = bms_activitypub_generate_signing_key();
$probe = random_bytes(32);
$probeSignature = '';
bms_activitypub_test_assert(
    openssl_sign($probe, $probeSignature, (string)$generatedKey['private_key_pem'], OPENSSL_ALGO_SHA256)
        && openssl_verify($probe, $probeSignature, (string)$generatedKey['public_key_pem'], OPENSSL_ALGO_SHA256) === 1,
    'A generated ActivityPub signing key must contain a usable matching RSA key pair.'
);

$activityPubSource = (string)file_get_contents($root . '/_bonumark_stream/app/activitypub.php');
bms_activitypub_test_assert(
    str_contains($activityPubSource, "SET status = 'retired', retired_at = UTC_TIMESTAMP() WHERE status = 'active'")
        && str_contains($activityPubSource, "'status' => 'active'"),
    'Signing-key rotation must retain retired-key history and activate the verified replacement transactionally.'
);
$serializationSource = (string)file_get_contents($root . '/_bonumark_stream/app/activitypub-serialization.php');
$securitySource = (string)file_get_contents($root . '/_bonumark_stream/app/activitypub-security.php');
bms_activitypub_test_assert(
    str_contains($serializationSource, "'#main-key'") && str_contains($securitySource, "'#main-key'"),
    'Actor discovery and outbound signatures must keep the stable #main-key identifier across rotation.'
);

fwrite(STDOUT, "ActivityPub Stage 1 foundation test passed.\n");
