<?php
/**
 * Bonumark Stream optional Remote API database smoke test.
 *
 * This CLI-only test uses a real MySQL/MariaDB database, copies the current
 * package to a temporary workspace, creates a temporary Bonumark config with a
 * random bms_api_ci_* table prefix, installs the schema, seeds an admin user,
 * and checks core Remote API behavior without adding endpoints or changing
 * production data.
 *
 * Required environment variables:
 *   BMS_DB_HOST
 *   BMS_DB_NAME
 *   BMS_DB_USER
 *   BMS_DB_PASS, may be empty
 *   BMS_DB_DANGER_RESET=1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

$scenario = (string)(getenv('BMS_API_SMOKE_SCENARIO') ?: '');
if ($scenario !== '') {
    bms_api_smoke_run_child($scenario);
    exit(0);
}

if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run. Set BMS_DB_DANGER_RESET=1 to confirm this test may create and drop temporary bms_api_ci_* tables.\n");
    exit(1);
}

foreach (['BMS_DB_HOST', 'BMS_DB_NAME', 'BMS_DB_USER'] as $required) {
    if ((string)getenv($required) === '') {
        fwrite(STDERR, "{$required} is required.\n");
        exit(1);
    }
}

$scenarios = [
    'disabled_api',
    'missing_token',
    'invalid_token',
    'stream_read',
    'durable_post_lifecycle',
    'activitypub_observer',
    'activitypub_publication',
    'activitypub_inbox',
    'activitypub_stage5',
    'activitypub_stage5_disabled',
    'activitypub_stage6',
    'activitypub_stage6_disabled',
    'draft_create',
    'publish_scope',
    'publish_confirmation',
    'media_scope',
    'idempotency_replay',
    'idempotency_conflict',
];

foreach ($scenarios as $name) {
    $env = array_merge($_ENV, getenv());
    $env['BMS_API_SMOKE_SCENARIO'] = $name;
    $command = [PHP_BINARY, __FILE__];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $env);
    if (!is_resource($process)) {
        fwrite(STDERR, "Could not start API smoke scenario: {$name}\n");
        exit(1);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        fwrite(STDERR, "Remote API database smoke scenario failed: {$name}\n");
        if ($stdout !== '') {
            fwrite(STDERR, $stdout);
        }
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }
        exit($status > 0 ? $status : 1);
    }
}

fwrite(STDOUT, "Remote API database smoke test passed. Scenarios: " . implode(', ', $scenarios) . "\n");

function bms_api_smoke_run_child(string $scenario): void
{
    if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
        throw new RuntimeException('BMS_DB_DANGER_RESET=1 is required.');
    }

    $sourceRoot = dirname(__DIR__);
    $tempRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bms-api-smoke-' . strtolower(bin2hex(random_bytes(5)));
    $prefix = 'bms_api_ci_' . strtolower(bin2hex(random_bytes(4))) . '_';

    bms_api_smoke_copy_tree($sourceRoot, $tempRoot);

    $configPath = $tempRoot . '/_bonumark_stream/config.php';
    $lockPath = $tempRoot . '/_bonumark_stream/installed.lock';
    $config = [
        'site_name' => 'Bonumark API Smoke',
        'site_tagline' => 'Temporary API smoke test install.',
        'version' => trim((string)file_get_contents($tempRoot . '/VERSION')),
        'base_url' => 'https://example.test',
        'base_path' => '',
        'public_path' => '',
        'security_salt' => 'api-smoke-' . bin2hex(random_bytes(12)),
        'timezone' => 'UTC',
        'database' => [
            'host' => (string)getenv('BMS_DB_HOST'),
            'name' => (string)getenv('BMS_DB_NAME'),
            'user' => (string)getenv('BMS_DB_USER'),
            'password' => (string)(getenv('BMS_DB_PASS') === false ? '' : getenv('BMS_DB_PASS')),
            'charset' => (string)(getenv('BMS_DB_CHARSET') ?: 'utf8mb4'),
            'prefix' => $prefix,
        ],
    ];
    file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    touch($lockPath);

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'Bonumark API Smoke Test';
    $_SERVER['REQUEST_URI'] = '/api/v1/status';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    require_once $tempRoot . '/_bonumark_stream/app/api.php';
    require_once $tempRoot . '/_bonumark_stream/app/renderer.php';
    require_once $tempRoot . '/_bonumark_stream/app/importers.php';

    try {
        bms_install_schema(bms_db(), $prefix);
        bms_db_insert_initial_data([
            'site_name' => 'Bonumark API Smoke',
            'site_tagline' => 'Temporary API smoke test install.',
            'timezone' => 'UTC',
            'base_url' => 'https://example.test',
            'base_path' => '',
            'public_path' => '',
        ], [
            'username' => 'admin',
            'display_name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bin2hex(random_bytes(12)),
        ]);

        bms_api_smoke_set_setting('remote_posting_enabled', $scenario === 'disabled_api' ? '0' : '1');
        bms_api_smoke_set_setting('remote_posting_direct_publish_enabled', '1');
        bms_api_smoke_set_setting('remote_posting_publish_confirmation_required', '1');
        bms_api_smoke_set_setting('remote_media_upload_enabled', '1');
        bms_api_smoke_set_setting('activitypub_enabled', in_array($scenario, ['activitypub_observer', 'activitypub_publication', 'activitypub_inbox', 'activitypub_stage5', 'activitypub_stage6'], true) ? '1' : '0');
        bms_api_smoke_set_setting('activitypub_follow_policy', 'manual');

        $GLOBALS['bms_api_smoke_temp_root'] = $tempRoot;
        bms_api_smoke_run_scenario($scenario);
        $activityPubEvents = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn();
        $activityPubDeliveries = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_deliveries'))->fetchColumn();
        if ($scenario === 'activitypub_observer') {
            $completedEvents = (int)bms_db()->query("SELECT COUNT(*) FROM " . bms_table('activitypub_publication_events') . " WHERE status = 'completed' AND processed_at IS NOT NULL AND activity_uri IS NOT NULL AND payload_json IS NOT NULL")->fetchColumn();
            $localObjects = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_local_objects'))->fetchColumn();
            if ($activityPubEvents !== 2 || $completedEvents !== 2 || $localObjects !== 1 || $activityPubDeliveries !== 0) {
                throw new RuntimeException('Stage 4 did not record exactly one Create and one changed Update without fan-out when there are no followers.');
            }
        } elseif ($scenario === 'activitypub_inbox') {
            $responseDeliveries = (int)bms_db()->query("SELECT COUNT(*) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'follower_response' AND event_id IS NULL AND status = 'delivered'")->fetchColumn();
            $publicationDeliveries = (int)bms_db()->query("SELECT COUNT(*) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication' OR event_id IS NOT NULL")->fetchColumn();
            $observedEvents = (int)bms_db()->query("SELECT COUNT(*) FROM " . bms_table('activitypub_publication_events') . " WHERE status = 'observed' AND processed_at IS NOT NULL")->fetchColumn();
            if ($activityPubEvents !== 1 || $observedEvents !== 1 || $activityPubDeliveries !== 1 || $responseDeliveries !== 1 || $publicationDeliveries !== 0) {
                throw new RuntimeException('The Stage 3 response queue was not isolated from historical observed publication events.');
            }
        } elseif ($scenario === 'activitypub_stage6') {
            $unfinished = (int)bms_db()->query("SELECT COUNT(*) FROM " . bms_table('activitypub_deliveries') . " WHERE status IN ('pending', 'retry', 'processing')")->fetchColumn();
            $ownerActions = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_owner_action_log'))->fetchColumn();
            $followActions = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_follow_log'))->fetchColumn();
            if ($activityPubEvents < 1 || $activityPubDeliveries < 1 || $unfinished !== 0 || $ownerActions < 4 || $followActions < 4) {
                throw new RuntimeException('Stage 6 did not leave a completed durable publication and owner-action checkpoint.');
            }
        } elseif ($scenario !== 'activitypub_publication' && ($activityPubEvents !== 0 || $activityPubDeliveries !== 0)) {
            throw new RuntimeException('Default-off Remote API behavior created ActivityPub events or deliveries.');
        }
    } finally {
        bms_api_smoke_drop_temp_tables($prefix);
        bms_api_smoke_remove_tree($tempRoot);
    }
}

function bms_api_smoke_run_scenario(string $scenario): void
{
    switch ($scenario) {
        case 'disabled_api':
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid';
            bms_api_smoke_expect_api_exception('remote_posting_disabled', function (): void {
                bms_api_authenticate(['status:read']);
            });
            return;

        case 'missing_token':
            unset($_SERVER['HTTP_AUTHORIZATION']);
            bms_api_smoke_expect_api_exception('missing_bearer_token', function (): void {
                bms_api_authenticate(['status:read']);
            });
            return;

        case 'invalid_token':
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token';
            bms_api_smoke_expect_api_exception('invalid_bearer_token', function (): void {
                bms_api_authenticate(['status:read']);
            });
            return;

        case 'stream_read':
            $tokenData = bms_api_create_token('Read token', ['status:read', 'stream:read'], null, 1);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
            bms_api_authenticate(['stream:read']);
            $authorId = 1;
            $postId = bms_upsert_database_content([
                'title' => 'Readable post',
                'slug' => 'readable-post',
                'status' => 'published',
                'content_type' => 'stream',
                'post_type' => 'stream',
                'date' => '2026-07-28',
                'description' => 'Read API smoke post.',
                'category' => 'Stream',
                'tags' => ['api'],
                'body' => 'Readable Stream content.',
                'front_matter' => [],
            ], 'published', 'readable-post.md', $authorId);
            $draftId = bms_upsert_database_content([
                'title' => 'Private draft',
                'slug' => 'private-draft',
                'status' => 'draft',
                'content_type' => 'stream',
                'post_type' => 'stream',
                'date' => '2026-07-28',
                'description' => 'This draft must not be returned by stream:read.',
                'category' => 'Stream',
                'tags' => ['private'],
                'body' => 'Private draft content.',
                'front_matter' => [],
            ], 'drafts', 'private-draft.md', $authorId);
            $_GET = ['status' => 'published', 'per_page' => '100', 'page' => '1', 'orderby' => 'id', 'order' => 'asc', 'include_html' => '1'];
            $catalog = bms_api_read_stream_posts();
            if ((int)($catalog['pagination']['total'] ?? 0) !== 1 || (int)($catalog['posts'][0]['id'] ?? 0) !== $postId) {
                throw new RuntimeException('Read token did not retrieve the complete Stream catalog.');
            }
            if (($catalog['posts'][0]['content']['markdown'] ?? '') !== 'Readable Stream content.' || trim((string)($catalog['posts'][0]['content']['html'] ?? '')) === '') {
                throw new RuntimeException('Read API did not preserve Markdown and optional rendered HTML.');
            }
            $outboxPosts = bms_activitypub_published_stream_posts(1, 20);
            if (bms_activitypub_published_stream_count() !== 1 || count($outboxPosts) !== 1 || (int)($outboxPosts[0]['post_id'] ?? 0) !== $postId) {
                throw new RuntimeException('The read-only ActivityPub outbox repository did not expose the existing public Stream post.');
            }
            $_GET = ['id' => (string)$postId];
            $single = bms_api_read_stream_posts();
            if (empty($single['single']) || (int)($single['post']['id'] ?? 0) !== $postId) {
                throw new RuntimeException('Read API did not retrieve a stable published Stream post by ID.');
            }
            $_GET = ['id' => (string)$draftId];
            bms_api_smoke_expect_api_exception('stream_post_not_found', function (): void {
                bms_api_read_stream_posts();
            });
            $_GET = ['status' => 'draft'];
            bms_api_smoke_expect_api_exception('invalid_status', function (): void {
                bms_api_read_stream_posts();
            });
            return;

        case 'durable_post_lifecycle':
            bms_api_smoke_verify_durable_post_lifecycle();
            return;

        case 'activitypub_observer':
            $postId = bms_upsert_database_content([
                'title' => 'Observed post',
                'slug' => 'observed-post',
                'status' => 'published',
                'content_type' => 'stream',
                'post_type' => 'stream',
                'date' => '2026-08-27',
                'description' => '',
                'category' => 'Stream',
                'tags' => [],
                'body' => 'Initial observed content.',
                'front_matter' => [],
            ], 'published', 'observed-post.md', 1);
            $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $postId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('The observer fixture post was not created.');
            }
            $page = bms_database_row_to_content_page($row);
            $updated = bms_update_stream_post_body($page, 'Changed observed content.');
            bms_update_stream_post_body($updated, 'Changed observed content.');
            return;

        case 'activitypub_publication':
            bms_api_smoke_verify_activitypub_publication();
            return;

        case 'activitypub_inbox':
            bms_activitypub_create_signing_key();
            $remoteKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            if ($remoteKey === false) {
                throw new RuntimeException('The remote actor fixture key could not be generated.');
            }
            $remotePrivate = '';
            openssl_pkey_export($remoteKey, $remotePrivate);
            $remoteDetails = openssl_pkey_get_details($remoteKey);
            $remotePublic = is_array($remoteDetails) ? (string)$remoteDetails['key'] : '';
            $remoteActorUri = 'https://93.184.216.34/actor';
            $remoteDocument = [
                'id' => $remoteActorUri,
                'type' => 'Person',
                'preferredUsername' => 'remote',
                'name' => 'Remote Fixture',
                'inbox' => 'https://93.184.216.34/inbox',
                'publicKey' => ['id' => $remoteActorUri . '#main-key', 'owner' => $remoteActorUri, 'publicKeyPem' => $remotePublic],
            ];
            $fetchCount = 0;
            $fetcher = static function (string $url) use (&$remoteDocument, &$fetchCount): array {
                $fetchCount++;
                return ['document' => $remoteDocument, 'url' => $url];
            };
            $resolver = static fn(string $host): array => ['93.184.216.34'];
            $now = time();
            $follow = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $remoteActorUri . '/activities/follow-1',
                'type' => 'Follow',
                'actor' => $remoteActorUri,
                'object' => bms_activitypub_actor_url(),
            ];
            $request = bms_api_smoke_signed_activity_request($follow, $remoteActorUri . '#main-key', $remotePrivate, $now);
            $received = bms_activitypub_receive_inbox($request, $fetcher, $resolver, $now);
            if ((string)($received['result_code'] ?? '') !== 'follow_pending') {
                throw new RuntimeException('A valid signed Follow did not enter manual moderation.');
            }
            $receiptCount = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_inbox_receipts'))->fetchColumn();
            $follower = bms_db()->query('SELECT * FROM ' . bms_table('activitypub_followers') . ' LIMIT 1')->fetch();
            if ($receiptCount !== 1 || !is_array($follower) || (string)$follower['state'] !== 'pending') {
                throw new RuntimeException('The valid Follow was not durably deduplicated and stored.');
            }

            bms_api_smoke_expect_security_exception(409, static function () use ($request, $fetcher, $resolver, $now): void {
                bms_activitypub_receive_inbox($request, $fetcher, $resolver, $now);
            });
            $malformedDigest = bms_api_smoke_signed_activity_request($follow, $remoteActorUri . '#main-key', $remotePrivate, $now + 1);
            $malformedDigest['headers']['digest'] = 'SHA-256=' . base64_encode(hash('sha256', 'malformed', true));
            bms_api_smoke_expect_security_exception(400, static function () use ($malformedDigest, $fetcher, $resolver, $now): void {
                bms_activitypub_receive_inbox($malformedDigest, $fetcher, $resolver, $now + 1);
            });
            if ($fetchCount !== 1) {
                throw new RuntimeException('A non-cryptographic signature failure caused an unnecessary remote actor refresh.');
            }
            $freshDuplicate = bms_api_smoke_rfc9421_activity_request($follow, $remoteActorUri . '#main-key', $remotePrivate, $now + 1);
            $duplicate = bms_activitypub_receive_inbox($freshDuplicate, $fetcher, $resolver, $now + 1);
            if ((string)($duplicate['result_code'] ?? '') !== 'duplicate_activity'
                || (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_inbox_receipts'))->fetchColumn() !== 1) {
                throw new RuntimeException('A duplicate activity with a fresh signature was not handled idempotently.');
            }

            $observed = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_publication_events') . ' (post_id, event_type, source, content_hash, state_json, status, created_at, processed_at) VALUES (NULL, :event_type, :source, :content_hash, :state_json, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
            $observed->execute(['event_type' => 'update', 'source' => 'stage3_isolation_fixture', 'content_hash' => hash('sha256', 'fixture'), 'state_json' => '{}', 'status' => 'observed']);
            bms_activitypub_moderate_follower((int)$follower['id'], 'approve');
            $queued = bms_db()->query('SELECT * FROM ' . bms_table('activitypub_deliveries') . ' LIMIT 1')->fetch();
            if (!is_array($queued) || (string)$queued['delivery_type'] !== 'follower_response' || $queued['event_id'] !== null) {
                throw new RuntimeException('Follower approval did not create an isolated response delivery.');
            }
            $transport = static fn(array $target, array $options): array => ['status' => 202, 'headers' => ['content-type' => ['application/activity+json']], 'body' => '', 'primary_ip' => '93.184.216.34'];
            $deliveryResult = bms_activitypub_run_response_deliveries(20, $transport, $resolver);
            if ((int)($deliveryResult['count'] ?? 0) !== 1) {
                throw new RuntimeException('The signed Accept response was not delivered through the queue.');
            }

            $undo = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $remoteActorUri . '/activities/undo-follow-1',
                'type' => 'Undo',
                'actor' => $remoteActorUri,
                'object' => $follow,
            ];
            $undoResult = bms_activitypub_receive_inbox(
                bms_api_smoke_signed_activity_request($undo, $remoteActorUri . '#main-key', $remotePrivate, $now + 2),
                $fetcher,
                $resolver,
                $now + 2
            );
            $followerState = (string)bms_db()->query('SELECT state FROM ' . bms_table('activitypub_followers') . ' LIMIT 1')->fetchColumn();
            if ((string)($undoResult['result_code'] ?? '') !== 'follow_undone' || $followerState !== 'removed'
                || bms_activitypub_collection_actor_uris('followers') !== []) {
                throw new RuntimeException('Undo of Follow did not remove the follower without another delivery.');
            }

            $remoteActorId = (int)bms_db()->query('SELECT id FROM ' . bms_table('activitypub_remote_actors') . ' LIMIT 1')->fetchColumn();
            $localFollowUri = bms_activitypub_absolute_url('/activitypub/activities/follow/test-outbound');
            $followingInsert = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_following') . ' (remote_actor_id, actor_uri, follow_activity_uri, state, created_at, updated_at) VALUES (:remote_actor_id, :actor_uri, :follow_activity_uri, :state, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
            $followingInsert->execute(['remote_actor_id' => $remoteActorId, 'actor_uri' => $remoteActorUri, 'follow_activity_uri' => $localFollowUri, 'state' => 'pending']);
            $accept = ['id' => $remoteActorUri . '/activities/accept-1', 'type' => 'Accept', 'actor' => $remoteActorUri, 'object' => $localFollowUri];
            bms_activitypub_receive_inbox(bms_api_smoke_signed_activity_request($accept, $remoteActorUri . '#main-key', $remotePrivate, $now + 3), $fetcher, $resolver, $now + 3);
            if ((string)bms_db()->query('SELECT state FROM ' . bms_table('activitypub_following') . ' LIMIT 1')->fetchColumn() !== 'accepted') {
                throw new RuntimeException('A valid Accept did not update the matching following relationship.');
            }
            $reject = ['id' => $remoteActorUri . '/activities/reject-1', 'type' => 'Reject', 'actor' => $remoteActorUri, 'object' => $localFollowUri];
            bms_activitypub_receive_inbox(bms_api_smoke_signed_activity_request($reject, $remoteActorUri . '#main-key', $remotePrivate, $now + 4), $fetcher, $resolver, $now + 4);
            if ((string)bms_db()->query('SELECT state FROM ' . bms_table('activitypub_following') . ' LIMIT 1')->fetchColumn() !== 'rejected') {
                throw new RuntimeException('A valid Reject did not update the matching following relationship.');
            }

            $unsupported = ['id' => $remoteActorUri . '/activities/like-1', 'type' => 'Like', 'actor' => $remoteActorUri, 'object' => bms_activitypub_object_url(999)];
            $ignored = bms_activitypub_receive_inbox(bms_api_smoke_signed_activity_request($unsupported, $remoteActorUri . '#main-key', $remotePrivate, $now + 5), $fetcher, $resolver, $now + 5);
            if ((string)($ignored['result_code'] ?? '') !== 'like_unknown_target') {
                throw new RuntimeException('A signed interaction with an unknown local object was not retained as ignored.');
            }

            $spoofed = ['id' => $remoteActorUri . '/activities/spoofed-1', 'type' => 'Follow', 'actor' => 'https://93.184.216.34/other-actor', 'object' => bms_activitypub_actor_url()];
            bms_api_smoke_expect_security_exception(502, static function () use ($spoofed, $remoteActorUri, $remotePrivate, $fetcher, $resolver, $now): void {
                bms_activitypub_receive_inbox(bms_api_smoke_signed_activity_request($spoofed, $remoteActorUri . '#main-key', $remotePrivate, $now + 6), $fetcher, $resolver, $now + 6);
            });

            $rotatedKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            $rotatedPrivate = '';
            if ($rotatedKey === false || !openssl_pkey_export($rotatedKey, $rotatedPrivate)) {
                throw new RuntimeException('The rotated remote actor key could not be generated.');
            }
            $rotatedDetails = openssl_pkey_get_details($rotatedKey);
            $remoteDocument['publicKey'] = [
                'id' => $remoteActorUri . '#rotated-key',
                'owner' => $remoteActorUri,
                'publicKeyPem' => is_array($rotatedDetails) ? (string)$rotatedDetails['key'] : '',
            ];
            $rotatedActivity = ['id' => $remoteActorUri . '/activities/like-rotated-key', 'type' => 'Like', 'actor' => $remoteActorUri, 'object' => bms_activitypub_object_url(999)];
            $rotatedResult = bms_activitypub_receive_inbox(
                bms_api_smoke_rfc9421_activity_request($rotatedActivity, $remoteActorUri . '#rotated-key', $rotatedPrivate, $now + 7),
                $fetcher,
                $resolver,
                $now + 7
            );
            $rotatedCachedActor = bms_activitypub_cached_remote_actor($remoteActorUri, true);
            if ((string)($rotatedResult['result_code'] ?? '') !== 'like_unknown_target'
                || !is_array($rotatedCachedActor)
                || (string)$rotatedCachedActor['public_key_id'] !== $remoteActorUri . '#rotated-key') {
                throw new RuntimeException('A legitimate authenticated remote key-ID rotation was not refreshed safely.');
            }
            bms_api_smoke_activitypub_route_responses((string)($GLOBALS['bms_api_smoke_temp_root'] ?? ''));
            return;

        case 'activitypub_stage5':
            bms_api_smoke_verify_activitypub_stage5();
            return;

        case 'activitypub_stage5_disabled':
            bms_api_smoke_expect_security_exception(404, static fn() => bms_activitypub_receive_inbox([]));
            return;

        case 'activitypub_stage6':
            bms_api_smoke_verify_activitypub_stage6();
            return;

        case 'activitypub_stage6_disabled':
            bms_api_smoke_verify_activitypub_stage6_disabled();
            return;

        case 'draft_create':
            $tokenData = bms_api_create_token('Draft token', ['status:read', 'stream:draft'], null, 1);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
            $token = bms_api_authenticate(['stream:draft']);
            $post = bms_api_create_remote_stream_post([
                'content' => 'Remote API smoke draft.',
                'status' => 'draft',
            ], $token, 'draft');
            if (($post['status'] ?? '') !== 'draft' || (int)($post['post_id'] ?? 0) < 1) {
                throw new RuntimeException('Draft token did not create a draft post.');
            }
            return;

        case 'publish_scope':
            $tokenData = bms_api_create_token('Draft only token', ['status:read', 'stream:draft'], null, 1);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
            bms_api_smoke_expect_api_exception('missing_scope', function (): void {
                bms_api_authenticate(['stream:draft', 'stream:publish']);
            });
            return;

        case 'publish_confirmation':
            $tokenData = bms_api_create_token('Publish token', ['status:read', 'stream:draft', 'stream:publish'], null, 1);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
            $token = bms_api_authenticate(['stream:draft', 'stream:publish']);
            bms_api_smoke_expect_api_exception('publish_confirmation_required', function () use ($token): void {
                bms_api_create_remote_stream_post([
                    'content' => 'Remote API smoke publish without confirmation.',
                    'status' => 'published',
                ], $token, 'published');
            });
            return;

        case 'media_scope':
            $tokenData = bms_api_create_token('Draft only token', ['status:read', 'stream:draft'], null, 1);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
            bms_api_smoke_expect_api_exception('missing_scope', function (): void {
                bms_api_authenticate(['media:upload']);
            });
            return;

        case 'idempotency_replay':
            $tokenData = bms_api_create_token('Idempotency token', ['status:read', 'stream:draft'], null, 1);
            $tokenId = (int)(($tokenData['token']['id'] ?? 0));
            if ($tokenId < 1) {
                throw new RuntimeException('Token ID was not created for idempotency test.');
            }
            $key = 'smoke-replay';
            $hash = 'hash-replay';
            if (bms_api_idempotency_begin($tokenId, $key, $hash) !== null) {
                throw new RuntimeException('New idempotency key unexpectedly returned a stored response.');
            }
            bms_api_idempotency_store($tokenId, $key, $hash, ['ok' => true, 'smoke' => 'replay'], 201);
            $stored = bms_api_idempotency_begin($tokenId, $key, $hash);
            if (!is_array($stored) || (int)($stored['status'] ?? 0) !== 201 || (($stored['payload']['smoke'] ?? '') !== 'replay')) {
                throw new RuntimeException('Idempotency replay did not return the stored response.');
            }
            return;

        case 'idempotency_conflict':
            $tokenData = bms_api_create_token('Idempotency token', ['status:read', 'stream:draft'], null, 1);
            $tokenId = (int)(($tokenData['token']['id'] ?? 0));
            if ($tokenId < 1) {
                throw new RuntimeException('Token ID was not created for idempotency conflict test.');
            }
            $key = 'smoke-conflict';
            bms_api_idempotency_begin($tokenId, $key, 'hash-one');
            bms_api_idempotency_store($tokenId, $key, 'hash-one', ['ok' => true], 201);
            bms_api_smoke_expect_api_exception('idempotency_key_conflict', function () use ($tokenId, $key): void {
                bms_api_idempotency_begin($tokenId, $key, 'hash-two');
            });
            return;
    }

    throw new RuntimeException('Unknown API smoke scenario: ' . $scenario);
}

function bms_api_smoke_verify_durable_post_lifecycle(): void
{
    $pdo = bms_db();
    $mediaPath = 'media/lifecycle-image.jpg';
    $mediaInsert = $pdo->prepare('INSERT INTO ' . bms_table('media') . ' (filename, original_filename, public_path, mime_type, file_size, width, height, alt_text, caption, uploaded_by, file_hash, image_variants_json, privacy_status, privacy_note, privacy_checked_at, created_at, updated_at, trashed_at, trashed_by) VALUES (:filename, :original_filename, :public_path, :mime_type, :file_size, :width, :height, :alt_text, :caption, :uploaded_by, :file_hash, :variants, :privacy_status, :privacy_note, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)');
    $mediaInsert->execute([
        'filename' => 'lifecycle-image.jpg',
        'original_filename' => 'Lifecycle Image.jpg',
        'public_path' => $mediaPath,
        'mime_type' => 'image/jpeg',
        'file_size' => 12345,
        'width' => 1200,
        'height' => 800,
        'alt_text' => 'Lifecycle image alt text',
        'caption' => 'Lifecycle image caption',
        'uploaded_by' => 1,
        'file_hash' => hash('sha256', 'lifecycle-image'),
        'variants' => '{}',
        'privacy_status' => 'clean',
        'privacy_note' => '',
    ]);

    $draft = bms_database_content_page_for_status([
        'title' => 'Durable identity post',
        'slug' => 'durable-identity-post',
        'content_type' => 'stream',
        'post_type' => 'stream',
        'date' => '2026-08-28',
        'description' => 'Lifecycle regression fixture.',
        'category' => 'Stream',
        'tags' => ['identity'],
        'body' => 'Initial lifecycle body.',
        'front_matter' => [],
        'featured_media' => $mediaPath,
        'media_gallery' => [$mediaPath],
        'stream_created_at' => '2026-08-28 01:00:00',
    ], 'draft', 'stream');
    $postId = bms_upsert_database_content($draft, 'drafts', 'durable-identity-post.md', 1);
    if ($postId < 1) {
        throw new RuntimeException('The durable lifecycle draft was not created.');
    }

    $published = bms_publish_file('durable-identity-post.md');
    if ((int)($published['post_id'] ?? $published['id'] ?? 0) !== $postId) {
        throw new RuntimeException('Draft publication replaced the durable post identity.');
    }

    $pdo->prepare('INSERT INTO ' . bms_table('comments') . ' (post_slug, post_id, user_id, parent_id, body, status, ip_hash, user_agent_hash, created_at, updated_at, approved_at) VALUES (:slug, :post_id, 1, NULL, :body, :status, :ip_hash, :agent_hash, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([
        'slug' => 'durable-identity-post',
        'post_id' => $postId,
        'body' => 'Identity-linked comment.',
        'status' => 'approved',
        'ip_hash' => hash('sha256', 'lifecycle-ip'),
        'agent_hash' => hash('sha256', 'lifecycle-agent'),
    ]);
    $pdo->prepare('INSERT INTO ' . bms_table('stream_likes') . ' (post_id, post_slug, visitor_hash, created_at) VALUES (:post_id, :slug, :visitor_hash, UTC_TIMESTAMP())')->execute([
        'post_id' => $postId,
        'slug' => 'durable-identity-post',
        'visitor_hash' => hash('sha256', 'lifecycle-like'),
    ]);

    $page = bms_find_database_content_by_slug_status('durable-identity-post', 'published', 'stream');
    if (!is_array($page)) {
        throw new RuntimeException('The published lifecycle fixture could not be loaded.');
    }
    bms_record_revision_from_page($page, 'published', 'durable-identity-post.md', 1);
    $edited = bms_update_stream_post_body($page, 'Material lifecycle edit.');
    if ((int)($edited['post_id'] ?? 0) !== $postId) {
        throw new RuntimeException('A body edit changed the durable post identity.');
    }

    $renamed = bms_database_content_page_for_status(array_replace($edited, ['slug' => 'durable-identity-renamed']), 'published', 'stream');
    $renamedId = bms_upsert_database_content($renamed, 'published', 'durable-identity-renamed.md', 1);
    if ($renamedId !== $postId || bms_find_database_content_by_slug_status('durable-identity-post', 'published', 'stream') !== null) {
        throw new RuntimeException('A slug change recreated the post or left a stale published row.');
    }
    $linkedComments = (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('comments') . ' WHERE post_id = ' . $postId . " AND post_slug = 'durable-identity-renamed'")->fetchColumn();
    $linkedLikes = (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('stream_likes') . ' WHERE post_id = ' . $postId . " AND post_slug = 'durable-identity-renamed'")->fetchColumn();
    $linkedRevisions = (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('revisions') . ' WHERE post_id = ' . $postId)->fetchColumn();
    if ($linkedComments !== 1 || $linkedLikes !== 1 || $linkedRevisions < 1) {
        throw new RuntimeException('Comments, likes, or revisions were detached by a slug change.');
    }

    $quickEdited = bms_update_stream_post_body($renamed, 'Quick edit lifecycle body.');
    $scheduledAt = gmdate('Y-m-d H:i:s', time() + 3600);
    $scheduledId = bms_schedule_post_page($quickEdited, 'scheduled', 'durable-identity-renamed.md', 1, $scheduledAt);
    if ($scheduledId !== $postId || bms_activitypub_published_stream_count() !== 0) {
        throw new RuntimeException('Scheduling changed post identity or exposed scheduled content publicly.');
    }
    $pdo->prepare('UPDATE ' . bms_table('posts') . ' SET scheduled_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = :id')->execute(['id' => $postId]);
    if (bms_publish_due_scheduled_posts(20) !== 1) {
        throw new RuntimeException('The due scheduled post was not published.');
    }
    $scheduledPublished = bms_activitypub_find_published_stream_post($postId);
    if (!is_array($scheduledPublished) || (int)($scheduledPublished['post_id'] ?? 0) !== $postId) {
        throw new RuntimeException('Scheduled publication did not preserve durable identity.');
    }

    $feed = bms_render_rss_feed(bms_list_content_records('published'), 'stream');
    if (!str_contains($feed, '/stream/durable-identity-renamed/') || str_contains($feed, '/stream/durable-identity-post/')) {
        throw new RuntimeException('The public feed did not follow the canonical slug change.');
    }
    $rawExport = bms_database_content_raw($scheduledPublished);
    if (!str_contains($rawExport, 'durable-identity-renamed') || !str_contains($rawExport, $mediaPath)) {
        throw new RuntimeException('The database export representation lost the current slug or media attachment.');
    }

    $unpublished = bms_unpublish_file('durable-identity-renamed.md');
    if ((int)($unpublished['post_id'] ?? 0) !== $postId || bms_activitypub_published_stream_count() !== 0) {
        throw new RuntimeException('Unpublish changed post identity or left the post publicly visible.');
    }
    $republished = bms_publish_file('durable-identity-renamed.md');
    if ((int)($republished['post_id'] ?? 0) !== $postId) {
        throw new RuntimeException('Republish replaced the durable post identity.');
    }

    bms_delete_content_file('published', 'durable-identity-renamed.md');
    $trash = $pdo->query('SELECT * FROM ' . bms_table('trash') . ' ORDER BY id DESC LIMIT 1')->fetch();
    $trashedPost = $pdo->query('SELECT * FROM ' . bms_table('posts') . ' WHERE id = ' . $postId)->fetch();
    if (!is_array($trash) || (int)($trash['post_id'] ?? 0) !== $postId || !is_array($trashedPost) || (string)$trashedPost['status'] !== 'trash'
        || bms_activitypub_published_stream_count() !== 0 || bms_api_smoke_public_post_count() !== 0) {
        throw new RuntimeException('Trash did not preserve identity or hide the post from public repositories.');
    }
    $restoredPublished = bms_restore_trash_item((int)$trash['id']);
    if ((int)($restoredPublished['post_id'] ?? 0) !== $postId || (string)($restoredPublished['restored_status'] ?? '') !== 'published') {
        throw new RuntimeException('Restore to published did not preserve durable identity.');
    }

    bms_unpublish_file('durable-identity-renamed.md');
    for ($cycle = 0; $cycle < 2; $cycle++) {
        bms_delete_content_file('draft', 'durable-identity-renamed.md');
        $cycleTrash = $pdo->query('SELECT * FROM ' . bms_table('trash') . ' ORDER BY id DESC LIMIT 1')->fetch();
        if (!is_array($cycleTrash) || (int)($cycleTrash['post_id'] ?? 0) !== $postId) {
            throw new RuntimeException('A repeated trash cycle lost the durable post reference.');
        }
        $cycleRestore = bms_restore_trash_item((int)$cycleTrash['id']);
        if ((int)($cycleRestore['post_id'] ?? 0) !== $postId || (string)($cycleRestore['restored_status'] ?? '') !== 'draft') {
            throw new RuntimeException('A repeated draft restore changed post identity.');
        }
    }

    $current = $pdo->query('SELECT * FROM ' . bms_table('posts') . ' WHERE id = ' . $postId)->fetch();
    $currentPage = is_array($current) ? bms_database_row_to_content_page($current) : null;
    if (!is_array($currentPage) || bms_normalize_media_gallery($currentPage['media_gallery'] ?? [], (string)($currentPage['featured_media'] ?? '')) !== [$mediaPath]) {
        throw new RuntimeException('Media attachment state did not survive the reversible lifecycle.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('posts') . ' WHERE id = ' . $postId)->fetchColumn() !== 1) {
        throw new RuntimeException('The reversible lifecycle created duplicate or stale post rows.');
    }

    $importItem = bms_import_make_item([
        'title' => 'Imported lifecycle control',
        'slug' => 'imported-lifecycle-control',
        'body' => 'Imported lifecycle content.',
        'status' => 'published',
        'content_type' => 'stream',
        'date' => '2026-08-28',
        'created_at' => '2026-08-28 02:00:00',
    ]);
    $importResult = bms_import_commit_items([$importItem->toArray()], 'published', true, 'skip');
    if ((int)($importResult['imported'] ?? 0) !== 1 || (int)($importResult['published'] ?? 0) !== 1) {
        throw new RuntimeException('Import behavior regressed during the durable identity correction.');
    }

    bms_delete_content_file('draft', 'durable-identity-renamed.md');
    $finalTrash = $pdo->query('SELECT * FROM ' . bms_table('trash') . ' WHERE post_id = ' . $postId . ' ORDER BY id DESC LIMIT 1')->fetch();
    if (!is_array($finalTrash)) {
        throw new RuntimeException('The final permanent-deletion fixture was not placed in Trash.');
    }
    bms_delete_trash_item_permanently((int)$finalTrash['id']);
    if ((int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('posts') . ' WHERE id = ' . $postId)->fetchColumn() !== 0) {
        throw new RuntimeException('Permanent deletion did not remove the post row.');
    }
}

function bms_api_smoke_verify_activitypub_publication(): void
{
    $pdo = bms_db();
    $localKey = bms_activitypub_create_signing_key();
    $publicKey = (string)$localKey['public_key_pem'];
    $resolver = static fn(string $host): array => ['93.184.216.34'];

    $actorInsert = $pdo->prepare('INSERT INTO ' . bms_table('activitypub_remote_actors') . ' (actor_uri, actor_type, preferred_username, display_name, inbox_url, shared_inbox_url, public_key_id, public_key_pem, key_owner_uri, document_json, fetched_at, expires_at, created_at, updated_at) VALUES (:actor_uri, \'Person\', :username, :display_name, :inbox_url, :shared_inbox_url, :public_key_id, :public_key_pem, :key_owner_uri, :document_json, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $followerInsert = $pdo->prepare('INSERT INTO ' . bms_table('activitypub_followers') . ' (remote_actor_id, actor_uri, follow_activity_uri, follow_receipt_id, state, response_activity_uri, followed_at, moderated_at, created_at, updated_at) VALUES (:remote_actor_id, :actor_uri, :follow_uri, 1, \'accepted\', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $actorIds = [];
    foreach ([
        ['one', 'https://93.184.216.34/inbox/one', 'https://93.184.216.34/inbox/shared', true],
        ['two', 'https://93.184.216.34/inbox/two', 'https://93.184.216.34/inbox/shared', true],
        ['three', 'https://93.184.216.34/inbox/three', null, false],
    ] as [$username, $inbox, $sharedInbox, $rfc9421]) {
        $actorUri = 'https://93.184.216.34/actors/' . $username;
        $document = [
            'id' => $actorUri,
            'type' => 'Person',
            'preferredUsername' => $username,
            'inbox' => $inbox,
            'endpoints' => array_filter([
                'sharedInbox' => $sharedInbox,
                'signatureAlgorithms' => $rfc9421 ? ['rfc9421'] : null,
            ], static fn(mixed $value): bool => $value !== null),
            'publicKey' => ['id' => $actorUri . '#main-key', 'owner' => $actorUri, 'publicKeyPem' => $publicKey],
        ];
        $actorInsert->execute([
            'actor_uri' => $actorUri,
            'username' => $username,
            'display_name' => ucfirst($username),
            'inbox_url' => $inbox,
            'shared_inbox_url' => $sharedInbox,
            'public_key_id' => $actorUri . '#main-key',
            'public_key_pem' => $publicKey,
            'key_owner_uri' => $actorUri,
            'document_json' => json_encode($document, JSON_UNESCAPED_SLASHES),
        ]);
        $actorId = (int)$pdo->lastInsertId();
        $actorIds[$username] = $actorId;
        $followerInsert->execute(['remote_actor_id' => $actorId, 'actor_uri' => $actorUri, 'follow_uri' => $actorUri . '/activities/follow']);
    }

    $mediaPaths = [];
    $mediaInsert = $pdo->prepare('INSERT INTO ' . bms_table('media') . ' (filename, original_filename, public_path, mime_type, file_size, width, height, alt_text, caption, uploaded_by, file_hash, image_variants_json, privacy_status, privacy_note, privacy_checked_at, created_at, updated_at, trashed_at, trashed_by) VALUES (:filename, :original_filename, :public_path, \'image/jpeg\', 100, :width, :height, :alt_text, \'\', 1, :file_hash, \'{}\', \'clean\', \'\', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)');
    for ($index = 1; $index <= 4; $index++) {
        $path = 'media/activitypub-stage4-' . $index . '.jpg';
        $file = bms_public_path($path);
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            throw new RuntimeException('The Stage 4 media fixture directory could not be created.');
        }
        file_put_contents($file, 'stage4-media-' . $index);
        $mediaInsert->execute([
            'filename' => basename($path),
            'original_filename' => 'Stage 4 Image ' . $index . '.jpg',
            'public_path' => $path,
            'width' => 1000 + $index,
            'height' => 700 + $index,
            'alt_text' => 'Stage 4 alt text ' . $index,
            'file_hash' => hash('sha256', 'stage4-media-' . $index),
        ]);
        $mediaPaths[] = $path;
    }

    $published = bms_database_content_page_for_status([
        'title' => 'Federated lifecycle post',
        'slug' => 'federated-lifecycle-post',
        'content_type' => 'stream',
        'post_type' => 'stream',
        'date' => '2026-08-28',
        'description' => '',
        'category' => 'Stream',
        'tags' => [],
        'body' => 'First federated publication.',
        'front_matter' => [],
        'featured_media' => $mediaPaths[0],
        'media_gallery' => $mediaPaths,
        'stream_created_at' => '2026-08-28 03:00:00',
    ], 'published', 'stream');
    $postId = bms_upsert_database_content($published, 'published', 'federated-lifecycle-post.md', 1);
    $objectId = bms_activitypub_generation_object_url($postId, 1);
    $firstEvent = $pdo->query('SELECT * FROM ' . bms_table('activitypub_publication_events') . ' ORDER BY id ASC LIMIT 1')->fetch();
    $firstPayload = is_array($firstEvent) ? json_decode((string)$firstEvent['payload_json'], true) : null;
    $attachments = is_array($firstPayload) ? ($firstPayload['object']['attachment'] ?? []) : [];
    $generationOneCreateObject = bms_activitypub_local_object_generation($postId, 1);
    $generationOnePublished = is_array($firstPayload) ? (string)($firstPayload['object']['published'] ?? '') : '';
    if (!is_array($firstEvent) || (int)($firstEvent['publication_generation'] ?? 0) !== 1
        || (string)($firstEvent['object_uri'] ?? '') !== $objectId
        || (string)$firstPayload['type'] !== 'Create' || (string)$firstPayload['object']['id'] !== $objectId
        || !is_array($generationOneCreateObject) || $generationOnePublished === ''
        || $generationOnePublished !== bms_activitypub_datetime((string)($generationOneCreateObject['published_at'] ?? ''))
        || count((array)$attachments) !== 4 || (string)($attachments[0]['name'] ?? '') !== 'Stage 4 alt text 1'
        || (int)($attachments[0]['width'] ?? 0) !== 1001 || (int)($attachments[0]['height'] ?? 0) !== 701) {
        throw new RuntimeException('The first Stage 4 Create did not preserve durable identity and complete media metadata.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_deliveries') . ' WHERE event_id = ' . (int)$firstEvent['id'])->fetchColumn() !== 2) {
        throw new RuntimeException('Shared inbox fan-out did not deduplicate three followers into two HTTP deliveries.');
    }
    for ($attachmentCount = 1; $attachmentCount <= 4; $attachmentCount++) {
        $attachmentPage = array_replace($published, [
            'post_id' => $postId,
            'id' => $postId,
            'featured_media' => $mediaPaths[0],
            'media_gallery' => array_slice($mediaPaths, 0, $attachmentCount),
        ]);
        $attachmentObject = bms_activitypub_post_object($attachmentPage, null, false);
        if (count((array)($attachmentObject['attachment'] ?? [])) !== $attachmentCount) {
            throw new RuntimeException('ActivityStreams serialization did not preserve a ' . $attachmentCount . '-item media gallery.');
        }
    }

    $current = bms_activitypub_find_stream_post($postId);
    if (!is_array($current)) {
        throw new RuntimeException('The federated fixture could not be reloaded.');
    }
    bms_upsert_database_content($current, 'published', 'federated-lifecycle-post.md', 1);
    if ((int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn() !== 1) {
        throw new RuntimeException('An identical published save created a duplicate activity.');
    }
    $edited = bms_update_stream_post_body($current, 'Material federated update.');
    $renamed = bms_database_content_page_for_status(array_replace($edited, ['slug' => 'federated-lifecycle-renamed']), 'published', 'stream');
    if (bms_upsert_database_content($renamed, 'published', 'federated-lifecycle-renamed.md', 1) !== $postId) {
        throw new RuntimeException('A federated slug update changed the durable post identity.');
    }
    $generationOneUpdate = $pdo->query('SELECT * FROM ' . bms_table('activitypub_publication_events') . ' WHERE post_id = ' . $postId . ' ORDER BY id DESC LIMIT 1')->fetch();
    $generationOneUpdatePayload = is_array($generationOneUpdate) ? json_decode((string)$generationOneUpdate['payload_json'], true) : null;
    if (!is_array($generationOneUpdate) || (int)$generationOneUpdate['publication_generation'] !== 1
        || (string)($generationOneUpdatePayload['type'] ?? '') !== 'Update'
        || (string)($generationOneUpdatePayload['object']['id'] ?? '') !== $objectId
        || (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_local_objects') . ' WHERE post_id = ' . $postId)->fetchColumn() !== 1) {
        throw new RuntimeException('A published edit or slug change escaped ActivityPub generation 1.');
    }
    $aliasCount = (int)$pdo->query("SELECT COUNT(*) FROM " . bms_table('activitypub_permalink_aliases') . " WHERE post_id = " . $postId . " AND slug = 'federated-lifecycle-post'")->fetchColumn();
    if ($aliasCount !== 1
        || bms_activitypub_permalink_alias_target('federated-lifecycle-post') !== bms_stream_url('federated-lifecycle-renamed')
        || bms_database_slug_exists('federated-lifecycle-post', 'federated-lifecycle-renamed', 'stream')
        || !bms_database_slug_exists('federated-lifecycle-post', 'unrelated-current-slug', 'stream')) {
        throw new RuntimeException('A federated slug update did not preserve and reserve its prior public permalink.');
    }
    $unpublished = bms_unpublish_file('federated-lifecycle-renamed.md');
    if (bms_activitypub_permalink_alias_target('federated-lifecycle-post') !== '') {
        throw new RuntimeException('An unpublished federated post remained reachable through its prior permalink.');
    }
    $generationOneDelete = $pdo->query('SELECT * FROM ' . bms_table('activitypub_publication_events') . ' WHERE post_id = ' . $postId . ' ORDER BY id DESC LIMIT 1')->fetch();
    $generationOneDeletePayload = is_array($generationOneDelete) ? json_decode((string)$generationOneDelete['payload_json'], true) : null;
    $generationOneObject = bms_activitypub_local_object_generation($postId, 1);
    $generationOneTombstone = is_array($generationOneObject) ? bms_activitypub_local_tombstone_document($generationOneObject) : [];
    if (!is_array($generationOneDelete) || (int)$generationOneDelete['publication_generation'] !== 1
        || (string)($generationOneDeletePayload['type'] ?? '') !== 'Delete'
        || (string)($generationOneDeletePayload['object']['id'] ?? '') !== $objectId
        || !is_array($generationOneObject) || trim((string)($generationOneObject['deleted_at'] ?? '')) === ''
        || (string)($generationOneTombstone['id'] ?? '') !== $objectId
        || (string)($generationOneTombstone['type'] ?? '') !== 'Tombstone') {
        throw new RuntimeException('Delete did not permanently retire ActivityPub generation 1 as a Tombstone.');
    }
    $generationOneDeleteDelivery = $pdo->query('SELECT * FROM ' . bms_table('activitypub_deliveries') . ' WHERE event_id = ' . (int)$generationOneDelete['id'] . ' ORDER BY id ASC LIMIT 1')->fetch();
    $republished = bms_publish_file((string)$unpublished['filename']);
    if (bms_activitypub_permalink_alias_target('federated-lifecycle-post') !== bms_stream_url('federated-lifecycle-renamed')) {
        throw new RuntimeException('A republished federated post did not restore its prior permalink redirect.');
    }
    $objectIdTwo = bms_activitypub_generation_object_url($postId, 2);
    $generationTwoCreate = $pdo->query('SELECT * FROM ' . bms_table('activitypub_publication_events') . ' WHERE post_id = ' . $postId . ' ORDER BY id DESC LIMIT 1')->fetch();
    $generationTwoCreatePayload = is_array($generationTwoCreate) ? json_decode((string)$generationTwoCreate['payload_json'], true) : null;
    $generationTwoObject = bms_activitypub_local_object_generation($postId, 2);
    $generationTwoPublished = is_array($generationTwoCreatePayload) ? (string)($generationTwoCreatePayload['object']['published'] ?? '') : '';
    if ((int)($republished['post_id'] ?? 0) !== $postId || !is_array($generationTwoCreate)
        || (int)$generationTwoCreate['publication_generation'] !== 2
        || (string)($generationTwoCreatePayload['type'] ?? '') !== 'Create'
        || (string)($generationTwoCreatePayload['object']['id'] ?? '') !== $objectIdTwo
        || $objectIdTwo === $objectId || !is_array($generationTwoObject)
        || $generationTwoPublished === ''
        || $generationTwoPublished !== bms_activitypub_datetime((string)($generationTwoObject['published_at'] ?? ''))
        || trim((string)($generationTwoObject['deleted_at'] ?? '')) !== ''
        || (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_local_objects') . ' WHERE post_id = ' . $postId)->fetchColumn() !== 2) {
        throw new RuntimeException('Republish did not create a distinct ActivityPub generation 2 for the same Bonumark post.');
    }
    $outboxPosts = bms_activitypub_published_stream_posts(1, 20);
    $outboxCreate = count($outboxPosts) === 1 ? bms_activitypub_create_activity($outboxPosts[0], null, false) : [];
    if ((string)($outboxCreate['id'] ?? '') !== (string)$generationTwoCreate['activity_uri']
        || (string)($outboxCreate['object']['id'] ?? '') !== $objectIdTwo) {
        throw new RuntimeException('The outbox did not represent the current ActivityPub publication generation.');
    }
    $stalePayloads = [];
    $staleTransport = static function (array $target, array $options) use (&$stalePayloads): array {
        $stalePayloads[] = json_decode((string)($options['body'] ?? ''), true);
        return ['status' => 202, 'headers' => [], 'body' => '', 'primary_ip' => '93.184.216.34'];
    };
    if (!is_array($generationOneDeleteDelivery)) {
        throw new RuntimeException('The generation 1 retry fixture did not retain immutable delivery work.');
    }
    bms_activitypub_deliver_publication_row($generationOneDeleteDelivery, bms_activitypub_active_signing_key(true), $staleTransport, $resolver);
    if ((string)($stalePayloads[0]['object']['id'] ?? '') !== $objectId
        || (string)($stalePayloads[0]['object']['id'] ?? '') === $objectIdTwo) {
        throw new RuntimeException('A stale generation 1 delivery targeted the current generation.');
    }
    $tamperedRetry = array_replace($generationOneDeleteDelivery, [
        'publication_generation' => 2,
        'object_uri' => $objectIdTwo,
    ]);
    $tamperedRejected = false;
    try {
        bms_activitypub_deliver_publication_row($tamperedRetry, bms_activitypub_active_signing_key(true), $staleTransport, $resolver);
    } catch (RuntimeException $e) {
        $tamperedRejected = str_contains($e->getMessage(), 'immutable generation event');
    }
    if (!$tamperedRejected || count($stalePayloads) !== 1) {
        throw new RuntimeException('Generation metadata could be changed to retarget stale delivery work.');
    }

    $generationTwoPage = bms_activitypub_find_stream_post($postId);
    $generationTwoPage = is_array($generationTwoPage)
        ? bms_update_stream_post_body($generationTwoPage, 'Generation 2 material update.')
        : null;
    $generationTwoUpdate = $pdo->query('SELECT * FROM ' . bms_table('activitypub_publication_events') . ' WHERE post_id = ' . $postId . ' ORDER BY id DESC LIMIT 1')->fetch();
    $generationTwoUpdatePayload = is_array($generationTwoUpdate) ? json_decode((string)$generationTwoUpdate['payload_json'], true) : null;
    if (!is_array($generationTwoPage) || !is_array($generationTwoUpdate)
        || (int)$generationTwoUpdate['publication_generation'] !== 2
        || (string)($generationTwoUpdatePayload['type'] ?? '') !== 'Update'
        || (string)($generationTwoUpdatePayload['object']['id'] ?? '') !== $objectIdTwo
        || (string)($generationTwoUpdatePayload['object']['published'] ?? '') !== $generationTwoPublished
        || trim((string)((bms_activitypub_local_object_generation($postId, 1)['deleted_at'] ?? ''))) === '') {
        throw new RuntimeException('An edit after republish did not update generation 2 exclusively.');
    }

    bms_delete_content_file('published', (string)$republished['filename']);
    $trash = $pdo->query('SELECT * FROM ' . bms_table('trash') . ' WHERE post_id = ' . $postId . ' ORDER BY id DESC LIMIT 1')->fetch();
    if (!is_array($trash)) {
        throw new RuntimeException('The federated fixture did not retain its Trash reference.');
    }
    $restored = bms_restore_trash_item((int)$trash['id']);
    if ((int)($restored['post_id'] ?? 0) !== $postId || (string)($restored['restored_status'] ?? '') !== 'published') {
        throw new RuntimeException('A federated restore did not reuse the true local object identity.');
    }
    $objectIdThree = bms_activitypub_generation_object_url($postId, 3);
    $generationThreeCreate = $pdo->query('SELECT * FROM ' . bms_table('activitypub_publication_events') . ' WHERE post_id = ' . $postId . ' ORDER BY id DESC LIMIT 1')->fetch();
    $generationThreePayload = is_array($generationThreeCreate) ? json_decode((string)$generationThreeCreate['payload_json'], true) : null;
    if (!is_array($generationThreeCreate) || (int)$generationThreeCreate['publication_generation'] !== 3
        || (string)($generationThreePayload['type'] ?? '') !== 'Create'
        || (string)($generationThreePayload['object']['id'] ?? '') !== $objectIdThree
        || (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_local_objects') . ' WHERE post_id = ' . $postId)->fetchColumn() !== 3) {
        throw new RuntimeException('A second Delete and republish cycle did not create generation 3.');
    }
    bms_delete_content_file('published', (string)$restored['filename']);
    $finalTrash = $pdo->query('SELECT * FROM ' . bms_table('trash') . ' WHERE post_id = ' . $postId . ' ORDER BY id DESC LIMIT 1')->fetch();
    $history = $pdo->query('SELECT publication_generation, object_uri, event_type, payload_json FROM ' . bms_table('activitypub_publication_events') . ' WHERE post_id = ' . $postId . " AND status <> 'observed' ORDER BY id")->fetchAll();
    $historyCounts = [];
    foreach ($history as $historyEvent) {
        $historyGeneration = (int)($historyEvent['publication_generation'] ?? 0);
        $historyObjectUri = (string)($historyEvent['object_uri'] ?? '');
        $historyPayload = json_decode((string)($historyEvent['payload_json'] ?? ''), true);
        $expectedHistoryUri = bms_activitypub_generation_object_url($postId, $historyGeneration);
        if ($historyGeneration < 1 || $historyObjectUri !== $expectedHistoryUri
            || (string)($historyPayload['object']['id'] ?? '') !== $expectedHistoryUri) {
            throw new RuntimeException('Immutable ActivityPub history crossed publication generation identities.');
        }
        $historyKey = $historyGeneration . ':' . (string)$historyEvent['event_type'];
        $historyCounts[$historyKey] = ($historyCounts[$historyKey] ?? 0) + 1;
    }
    foreach ([1, 2, 3] as $historyGeneration) {
        if (($historyCounts[$historyGeneration . ':published'] ?? 0) !== 1
            || ($historyCounts[$historyGeneration . ':unpublished'] ?? 0) + ($historyCounts[$historyGeneration . ':deleted'] ?? 0) !== 1) {
            throw new RuntimeException('ActivityPub history did not preserve exactly one Create and one Delete for generation ' . $historyGeneration . '.');
        }
    }
    $eventsBeforePermanentDelete = (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn();
    bms_delete_trash_item_permanently((int)$finalTrash['id']);
    $eventsAfterPermanentDelete = (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn();
    $tombstone = bms_activitypub_local_object($postId);
    if ($eventsBeforePermanentDelete !== $eventsAfterPermanentDelete || !is_array($tombstone) || (string)$tombstone['object_uri'] !== $objectIdThree || trim((string)$tombstone['deleted_at']) === '') {
        throw new RuntimeException('Permanent deletion duplicated Delete or lost the durable tombstone.');
    }

    $scheduled = bms_database_content_page_for_status([
        'title' => 'Scheduled federation post', 'slug' => 'scheduled-federation-post', 'content_type' => 'stream', 'post_type' => 'stream',
        'date' => '2026-08-28', 'description' => '', 'category' => 'Stream', 'tags' => [], 'body' => 'Scheduled federation body.', 'front_matter' => [],
    ], 'scheduled', 'stream');
    $scheduledId = bms_upsert_database_content($scheduled, 'scheduled', 'scheduled-federation-post.md', 1);
    $beforeDue = (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn();
    $pdo->prepare('UPDATE ' . bms_table('posts') . ' SET scheduled_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = :id')->execute(['id' => $scheduledId]);
    bms_publish_due_scheduled_posts(10);
    if ((int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn() !== $beforeDue + 1) {
        throw new RuntimeException('Scheduled federation ran before due or failed to create exactly one Create when published.');
    }

    $eventsBeforeRemoteApi = (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn();
    $tokenData = bms_api_create_token('Stage 4 publish token', ['status:read', 'stream:draft', 'stream:publish'], null, 1);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
    $token = bms_api_authenticate(['stream:draft', 'stream:publish']);
    $remotePost = bms_api_create_remote_stream_post([
        'content' => 'Remote API Stage 4 publication.',
        'status' => 'published',
        'slug' => 'remote-api-stage4-publication',
        'confirm_publish' => true,
    ], $token, 'published');
    if ((int)($remotePost['post_id'] ?? 0) < 1
        || (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn() !== $eventsBeforeRemoteApi + 1) {
        throw new RuntimeException('A confirmed Remote API publication did not create exactly one durable Create.');
    }

    $eventsBeforeImport = (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn();
    $importItem = bms_import_make_item([
        'title' => 'Stage 4 imported publication',
        'slug' => 'stage4-imported-publication',
        'body' => 'Imported Stage 4 publication.',
        'status' => 'published',
        'content_type' => 'stream',
        'date' => '2026-08-28',
        'created_at' => '2026-08-28 04:00:00',
    ]);
    $importResult = bms_import_commit_items([$importItem->toArray()], 'published', true, 'skip');
    if ((int)($importResult['published'] ?? 0) !== 1
        || (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn() !== $eventsBeforeImport + 1) {
        throw new RuntimeException('An imported publication did not create exactly one durable Create.');
    }

    $observed = $pdo->prepare('INSERT INTO ' . bms_table('activitypub_publication_events') . ' (post_id, event_type, source, content_hash, state_json, status, created_at, processed_at) VALUES (NULL, \'historical\', \'stage2_fixture\', :hash, \'{}\', \'observed\', UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $observed->execute(['hash' => hash('sha256', 'historical-observed')]);
    $observedId = (int)$pdo->lastInsertId();

    $attemptsBeforeMissingKey = (int)$pdo->query("SELECT COALESCE(SUM(attempt_count), 0) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication'")->fetchColumn();
    $pdo->exec("UPDATE " . bms_table('activitypub_keys') . " SET status = 'retired' WHERE status = 'active'");
    $missingKey = bms_activitypub_run_publication_deliveries(20, null, $resolver);
    $attemptsAfterMissingKey = (int)$pdo->query("SELECT COALESCE(SUM(attempt_count), 0) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication'")->fetchColumn();
    if (!empty($missingKey['ok']) || $attemptsBeforeMissingKey !== $attemptsAfterMissingKey) {
        throw new RuntimeException('A missing signing key claimed publication work or created a scheduled hot loop.');
    }
    $pdo->prepare("UPDATE " . bms_table('activitypub_keys') . " SET status = 'active' WHERE id = :id")->execute(['id' => (int)$localKey['id']]);

    $signatureKinds = [];
    $transport = static function (array $target, array $options) use (&$signatureKinds): array {
        $headers = implode("\n", array_map('strval', (array)($options['headers'] ?? [])));
        $signatureKinds[] = str_contains(strtolower($headers), 'signature-input:') ? 'rfc9421' : 'legacy';
        return ['status' => 202, 'headers' => [], 'body' => '', 'primary_ip' => '93.184.216.34'];
    };
    do {
        $result = bms_activitypub_run_publication_deliveries(20, $transport, $resolver);
    } while ((int)($result['count'] ?? 0) > 0);
    if (!in_array('legacy', $signatureKinds, true) || !in_array('rfc9421', $signatureKinds, true)) {
        throw new RuntimeException('Adaptive delivery did not exercise both legacy and RFC 9421 outbound signatures.');
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication' AND event_id IS NOT NULL AND status <> 'delivered'")->fetchColumn() !== 0) {
        throw new RuntimeException('Bounded publication delivery did not complete every queued endpoint.');
    }
    if ((string)$pdo->query('SELECT status FROM ' . bms_table('activitypub_publication_events') . ' WHERE id = ' . $observedId)->fetchColumn() !== 'observed') {
        throw new RuntimeException('The publication worker reactivated a historical observed event.');
    }
    $duplicateRun = bms_activitypub_run_publication_deliveries(20, $transport, $resolver);
    if ((int)($duplicateRun['count'] ?? 0) !== 0) {
        throw new RuntimeException('A duplicate worker run redelivered completed activities.');
    }

    $deliveryId = (int)$pdo->query("SELECT id FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication' ORDER BY id ASC LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'processing', last_attempt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 16 MINUTE) WHERE id = :id")->execute(['id' => $deliveryId]);
    bms_activitypub_run_publication_deliveries(1, $transport, $resolver);
    if ((string)$pdo->query('SELECT status FROM ' . bms_table('activitypub_deliveries') . ' WHERE id = ' . $deliveryId)->fetchColumn() !== 'delivered') {
        throw new RuntimeException('Stale processing work was not recovered safely.');
    }

    $pdo->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', available_at = UTC_TIMESTAMP(), attempt_count = 0 WHERE id = :id")->execute(['id' => $deliveryId]);
    $rateLimited = static fn(array $target, array $options): array => ['status' => 429, 'headers' => ['retry-after' => ['120']], 'body' => '', 'primary_ip' => '93.184.216.34'];
    bms_activitypub_run_publication_deliveries(1, $rateLimited, $resolver);
    $rateRow = $pdo->query('SELECT status, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), available_at) AS wait_seconds FROM ' . bms_table('activitypub_deliveries') . ' WHERE id = ' . $deliveryId)->fetch();
    if (!is_array($rateRow) || (string)$rateRow['status'] !== 'retry' || (int)$rateRow['wait_seconds'] < 100) {
        throw new RuntimeException('HTTP 429 Retry-After was not retained as bounded retry work.');
    }
    $pdo->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET available_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['id' => $deliveryId]);
    bms_activitypub_run_publication_deliveries(1, $transport, $resolver);

    foreach ([[503, 'retry'], [410, 'dead']] as [$statusCode, $expectedStatus]) {
        $pdo->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', available_at = UTC_TIMESTAMP(), attempt_count = 0 WHERE id = :id")->execute(['id' => $deliveryId]);
        $failureTransport = static fn(array $target, array $options): array => ['status' => $statusCode, 'headers' => [], 'body' => '', 'primary_ip' => '93.184.216.34'];
        bms_activitypub_run_publication_deliveries(1, $failureTransport, $resolver);
        if ((string)$pdo->query('SELECT status FROM ' . bms_table('activitypub_deliveries') . ' WHERE id = ' . $deliveryId)->fetchColumn() !== $expectedStatus) {
            throw new RuntimeException('Publication retry classification failed for HTTP ' . $statusCode . '.');
        }
    }
    if (!bms_activitypub_manual_retry_publication_delivery($deliveryId)) {
        throw new RuntimeException('A dead publication delivery could not be retried safely in place.');
    }
    bms_activitypub_run_publication_deliveries(1, $transport, $resolver);

    $privateDelivery = $pdo->prepare("SELECT id FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication' AND recipient_actor_ids_json = :actor_ids ORDER BY id ASC LIMIT 1");
    $privateDelivery->execute(['actor_ids' => json_encode([(int)$actorIds['three']])]);
    $privateDeliveryId = (int)$privateDelivery->fetchColumn();
    $pdo->prepare('UPDATE ' . bms_table('activitypub_remote_actors') . ' SET inbox_url = :inbox, shared_inbox_url = NULL, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = :id')->execute(['inbox' => 'https://127.0.0.1/private-inbox', 'id' => (int)$actorIds['three']]);
    $pdo->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', available_at = UTC_TIMESTAMP(), attempt_count = 0 WHERE id = :id")->execute(['id' => $privateDeliveryId]);
    $privateTransportCalls = 0;
    $mustNotConnect = static function (array $target, array $options) use (&$privateTransportCalls): array {
        $privateTransportCalls++;
        return ['status' => 202, 'headers' => [], 'body' => '', 'primary_ip' => '127.0.0.1'];
    };
    bms_activitypub_run_publication_deliveries(1, $mustNotConnect, $resolver);
    if ($privateTransportCalls !== 0 || (string)$pdo->query('SELECT status FROM ' . bms_table('activitypub_deliveries') . ' WHERE id = ' . $privateDeliveryId)->fetchColumn() !== 'retry') {
        throw new RuntimeException('A cached private-network inbox reached the outbound transport.');
    }
    $pdo->prepare('UPDATE ' . bms_table('activitypub_remote_actors') . ' SET inbox_url = :inbox, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = :id')->execute(['inbox' => 'https://93.184.216.34/inbox/three', 'id' => (int)$actorIds['three']]);
    $pdo->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'retry', available_at = UTC_TIMESTAMP(), attempt_count = 0 WHERE id = :id")->execute(['id' => $privateDeliveryId]);
    $unreachable = static function (array $target, array $options): array {
        throw new BmsActivityPubSecurityException('The fixture inbox is unreachable.', 502);
    };
    bms_activitypub_run_publication_deliveries(1, $unreachable, $resolver);
    if ((string)$pdo->query('SELECT status FROM ' . bms_table('activitypub_deliveries') . ' WHERE id = ' . $privateDeliveryId)->fetchColumn() !== 'retry') {
        throw new RuntimeException('An unreachable follower inbox was not retained for bounded retry.');
    }

    $pdo->prepare('UPDATE ' . bms_table('activitypub_remote_actors') . ' SET shared_inbox_url = :shared_inbox, inbox_url = :inbox, document_json = JSON_SET(document_json, \'$.inbox\', :document_inbox), expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = :id')->execute([
        'shared_inbox' => 'https://93.184.216.34/inbox/one-new',
        'inbox' => 'https://93.184.216.34/inbox/one-new',
        'document_inbox' => 'https://93.184.216.34/inbox/one-new',
        'id' => (int)$actorIds['one'],
    ]);
    $scheduledPage = bms_activitypub_find_stream_post($scheduledId);
    bms_update_stream_post_body((array)$scheduledPage, 'Scheduled post changed after publication.');
    $latestEventId = (int)$pdo->query('SELECT id FROM ' . bms_table('activitypub_publication_events') . ' WHERE post_id = ' . $scheduledId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    $latestDelivery = $pdo->query('SELECT * FROM ' . bms_table('activitypub_deliveries') . ' WHERE event_id = ' . $latestEventId . ' ORDER BY id ASC LIMIT 1')->fetch();
    if (!is_array($latestDelivery)) {
        throw new RuntimeException('The actor inbox-change fixture did not create delivery work.');
    }
    bms_activitypub_reconcile_publication_delivery_target($latestDelivery, null, $resolver);
    if ((int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_deliveries') . ' WHERE event_id = ' . $latestEventId)->fetchColumn() !== 3) {
        throw new RuntimeException('An accepted follower inbox change was not split from its former shared inbox safely.');
    }

    $rfcDelivery = $pdo->query("SELECT * FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication' AND signature_mode = 'rfc9421' ORDER BY id DESC LIMIT 1")->fetch();
    if (is_array($rfcDelivery)) {
        $fallbackCalls = [];
        $fallbackTransport = static function (array $target, array $options) use (&$fallbackCalls): array {
            $headers = implode("\n", array_map('strval', (array)($options['headers'] ?? [])));
            $rfc = str_contains(strtolower($headers), 'signature-input:');
            $fallbackCalls[] = $rfc ? 'rfc9421' : 'legacy';
            return ['status' => $rfc ? 401 : 202, 'headers' => [], 'body' => '', 'primary_ip' => '93.184.216.34'];
        };
        $fallbackResponse = bms_activitypub_deliver_publication_row($rfcDelivery, bms_activitypub_active_signing_key(true), $fallbackTransport, $resolver);
        if ((int)($fallbackResponse['status'] ?? 0) !== 202 || $fallbackCalls !== ['rfc9421', 'legacy']) {
            throw new RuntimeException('RFC 9421 authentication rejection did not receive one bounded legacy fallback.');
        }
    }
}

function bms_api_smoke_verify_activitypub_stage5(): void
{
    $pdo = bms_db();
    $postId = bms_upsert_database_content([
        'title' => 'Stage 5 target', 'slug' => 'stage-5-target', 'status' => 'draft',
        'content_type' => 'stream', 'post_type' => 'stream', 'date' => '2026-08-31',
        'description' => '', 'category' => 'Stream', 'tags' => [], 'body' => 'Stage 5 local content.', 'front_matter' => [],
    ], 'drafts', 'stage-5-target.md', 1);
    $pdo->prepare("UPDATE " . bms_table('posts') . " SET status = 'published', published_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['id' => $postId]);
    $retiredUri = bms_activitypub_object_url($postId);
    $currentUri = bms_activitypub_generation_object_url($postId, 2);
    $localInsert = $pdo->prepare('INSERT INTO ' . bms_table('activitypub_local_objects') . ' (post_id, object_uri, object_type, content_hash, last_object_json, last_human_url, publication_generation, transition_sequence, published_at, updated_at, deleted_at, created_at) VALUES (:post_id, :object_uri, :object_type, :content_hash, :object_json, :human_url, :generation, :sequence, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :deleted_at, UTC_TIMESTAMP())');
    $localInsert->execute(['post_id' => $postId, 'object_uri' => $retiredUri, 'object_type' => 'Note', 'content_hash' => hash('sha256', 'retired'), 'object_json' => json_encode(['id' => $retiredUri, 'type' => 'Tombstone']), 'human_url' => bms_stream_url('stage-5-target'), 'generation' => 1, 'sequence' => 1, 'deleted_at' => gmdate('Y-m-d H:i:s')]);
    $localInsert->execute(['post_id' => $postId, 'object_uri' => $currentUri, 'object_type' => 'Note', 'content_hash' => hash('sha256', 'current'), 'object_json' => json_encode(['id' => $currentUri, 'type' => 'Note', 'content' => '<p>Stage 5 local content.</p>']), 'human_url' => bms_stream_url('stage-5-target'), 'generation' => 2, 'sequence' => 2, 'deleted_at' => null]);

    $commentInsert = $pdo->prepare("INSERT INTO " . bms_table('comments') . " (post_slug, post_id, user_id, parent_id, body, status, ip_hash, user_agent_hash, created_at, updated_at, approved_at) VALUES ('stage-5-target', :post_id, 1, NULL, 'Local comment remains local.', 'approved', :ip_hash, :ua_hash, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $commentInsert->execute(['post_id' => $postId, 'ip_hash' => hash('sha256', 'stage5-ip'), 'ua_hash' => hash('sha256', 'stage5-ua')]);
    $localCommentCount = bms_comment_count_for_slug('stage-5-target');
    $localLikeCount = bms_stream_like_count_for_slug('stage-5-target');

    $actors = [];
    foreach (['alpha', 'beta'] as $name) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $private = '';
        if ($key === false || !openssl_pkey_export($key, $private)) {
            throw new RuntimeException('The Stage 5 actor key could not be generated.');
        }
        $details = openssl_pkey_get_details($key);
        $uri = 'https://93.184.216.34/actors/' . $name;
        $actors[$name] = [
            'uri' => $uri, 'key_id' => $uri . '#main-key', 'private' => $private,
            'document' => [
                'id' => $uri, 'type' => 'Person', 'preferredUsername' => $name,
                'name' => ucfirst($name) . ' Remote', 'inbox' => 'https://93.184.216.34/inbox/' . $name,
                'publicKey' => ['id' => $uri . '#main-key', 'owner' => $uri, 'publicKeyPem' => is_array($details) ? (string)$details['key'] : ''],
            ],
        ];
    }
    $fetcher = static function (string $url) use (&$actors): array {
        foreach ($actors as $actor) {
            if ($url === $actor['uri']) {
                return ['document' => $actor['document'], 'url' => $url];
            }
        }
        throw new RuntimeException('Unexpected Stage 5 actor fetch.');
    };
    $resolver = static fn(string $host): array => ['93.184.216.34'];
    $clock = time();
    $send = static function (array $activity, string $actorName = 'alpha') use (&$clock, &$actors, $fetcher, $resolver): array {
        $clock++;
        $actor = $actors[$actorName];
        return bms_activitypub_receive_inbox(
            bms_api_smoke_signed_activity_request($activity, (string)$actor['key_id'], (string)$actor['private'], $clock),
            $fetcher,
            $resolver,
            $clock
        );
    };
    $alphaUri = (string)$actors['alpha']['uri'];
    $betaUri = (string)$actors['beta']['uri'];
    $replyUri = $alphaUri . '/notes/reply-1';
    $reply = [
        'id' => $alphaUri . '/activities/create-reply-1', 'type' => 'Create', 'actor' => $alphaUri,
        'object' => [
            'id' => $replyUri, 'type' => 'Note', 'attributedTo' => $alphaUri, 'inReplyTo' => $currentUri,
            'published' => gmdate(DATE_ATOM, $clock),
            'content' => '<p>Hello <strong>federation</strong>.</p><script>alert(1)</script><p><a href="javascript:alert(1)" onclick="evil()">bad</a> <a href="https://remote.example/safe">safe</a></p>',
        ],
    ];
    $created = $send($reply);
    if (($created['result_code'] ?? '') !== 'reply_pending') {
        throw new RuntimeException('A valid current-generation remote reply did not enter moderation.');
    }
    $storedReply = bms_activitypub_remote_reply_by_uri($replyUri);
    if (!is_array($storedReply) || (int)$storedReply['target_publication_generation'] !== 2
        || str_contains((string)$storedReply['content_html'], '<script')
        || str_contains((string)$storedReply['content_html'], 'javascript:')
        || str_contains((string)$storedReply['content_html'], 'onclick')
        || !str_contains((string)$storedReply['content_html'], 'https://remote.example/safe')) {
        throw new RuntimeException('Remote reply identity, generation binding, or HTML sanitization failed.');
    }
    bms_activitypub_moderate_remote_reply((int)$storedReply['id'], 'approve', 1);
    $presented = bms_comments_view_data('stage-5-target');
    if ($localCommentCount !== 1 || (int)$presented['count'] !== 2
        || count(array_filter((array)$presented['comments'], static fn(array $item): bool => ($item['source'] ?? '') === 'activitypub')) !== 1) {
        throw new RuntimeException('Approved remote replies were not presented beside, but separate from, local comments.');
    }

    $nestedUri = $alphaUri . '/notes/reply-2';
    $nested = ['id' => $alphaUri . '/activities/create-reply-2', 'type' => 'Create', 'actor' => $alphaUri, 'object' => ['id' => $nestedUri, 'type' => 'Note', 'attributedTo' => $alphaUri, 'inReplyTo' => $replyUri, 'content' => '<p>Nested reply.</p>']];
    if (($send($nested)['result_code'] ?? '') !== 'reply_pending') {
        throw new RuntimeException('A nested reply did not inherit the exact root publication generation.');
    }
    $nestedStored = bms_activitypub_remote_reply_by_uri($nestedUri);
    if (!is_array($nestedStored) || (int)$nestedStored['parent_reply_id'] !== (int)$storedReply['id'] || (int)$nestedStored['target_publication_generation'] !== 2) {
        throw new RuntimeException('Nested reply identity was not preserved.');
    }

    $freshDuplicate = $reply;
    if (($send($freshDuplicate)['result_code'] ?? '') !== 'duplicate_activity') {
        throw new RuntimeException('Duplicate Create activity URI was not idempotent.');
    }
    $changedCreate = $reply;
    $changedCreate['id'] = $alphaUri . '/activities/create-reply-1-alias';
    if (($send($changedCreate)['result_code'] ?? '') !== 'reply_duplicate_object') {
        throw new RuntimeException('A changed Create URI bypassed remote object idempotency.');
    }

    $retiredReplyUri = $alphaUri . '/notes/retired-reply';
    $retiredReply = ['id' => $alphaUri . '/activities/create-retired-reply', 'type' => 'Create', 'actor' => $alphaUri, 'object' => ['id' => $retiredReplyUri, 'type' => 'Note', 'attributedTo' => $alphaUri, 'inReplyTo' => $retiredUri, 'content' => '<p>Retired generation reply.</p>']];
    if (($send($retiredReply)['result_code'] ?? '') !== 'reply_target_retired') {
        throw new RuntimeException('A reply to a retired generation was not deliberately retained as non-visible.');
    }
    $retiredStored = bms_activitypub_remote_reply_by_uri($retiredReplyUri);
    if (!is_array($retiredStored) || (int)$retiredStored['target_publication_generation'] !== 1 || (string)$retiredStored['moderation_state'] !== 'target_retired') {
        throw new RuntimeException('A retired-generation reply migrated or became visible.');
    }
    $unknown = ['id' => $alphaUri . '/activities/create-unknown', 'type' => 'Create', 'actor' => $alphaUri, 'object' => ['id' => $alphaUri . '/notes/unknown', 'type' => 'Note', 'attributedTo' => $alphaUri, 'inReplyTo' => bms_activitypub_generation_object_url(9999, 9), 'content' => '<p>Unknown.</p>']];
    if (($send($unknown)['result_code'] ?? '') !== 'reply_unknown_target') {
        throw new RuntimeException('A reply to an unknown local object was not ignored.');
    }

    $update = ['id' => $alphaUri . '/activities/update-reply-1', 'type' => 'Update', 'actor' => $alphaUri, 'object' => ['id' => $replyUri, 'type' => 'Note', 'attributedTo' => $alphaUri, 'inReplyTo' => $currentUri, 'updated' => gmdate(DATE_ATOM, $clock), 'content' => '<p>Updated reply text.</p>']];
    if (($send($update)['result_code'] ?? '') !== 'reply_updated') {
        throw new RuntimeException('The owning actor could not update its accepted remote reply.');
    }
    $updatedStored = bms_activitypub_remote_reply_by_uri($replyUri);
    if (!is_array($updatedStored) || (string)$updatedStored['moderation_state'] !== 'approved' || !str_contains((string)$updatedStored['content_text'], 'Updated reply text')) {
        throw new RuntimeException('Remote reply Update lost moderation state or sanitized content.');
    }
    $wrongUpdate = $update;
    $wrongUpdate['id'] = $betaUri . '/activities/wrong-update';
    $wrongUpdate['actor'] = $betaUri;
    $wrongUpdate['object']['attributedTo'] = $betaUri;
    bms_api_smoke_expect_security_exception(403, static fn() => $send($wrongUpdate, 'beta'));

    $wrongDelete = ['id' => $betaUri . '/activities/wrong-delete', 'type' => 'Delete', 'actor' => $betaUri, 'object' => $replyUri];
    bms_api_smoke_expect_security_exception(403, static fn() => $send($wrongDelete, 'beta'));
    $delete = ['id' => $alphaUri . '/activities/delete-reply-1', 'type' => 'Delete', 'actor' => $alphaUri, 'object' => $replyUri];
    if (($send($delete)['result_code'] ?? '') !== 'reply_deleted') {
        throw new RuntimeException('The owning actor could not delete its remote reply.');
    }
    $afterDelete = $update;
    $afterDelete['id'] = $alphaUri . '/activities/update-after-delete';
    if (($send($afterDelete)['result_code'] ?? '') !== 'reply_update_after_delete') {
        throw new RuntimeException('Update after Delete was not rejected without resurrection.');
    }
    if ((string)bms_activitypub_remote_reply_by_uri($replyUri)['lifecycle_state'] !== 'deleted') {
        throw new RuntimeException('A deleted remote reply lost its tombstone state.');
    }

    $like1 = ['id' => $alphaUri . '/activities/like-1', 'type' => 'Like', 'actor' => $alphaUri, 'object' => $currentUri];
    if (($send($like1)['result_code'] ?? '') !== 'like_recorded') {
        throw new RuntimeException('A valid inbound Like was not recorded.');
    }
    $likeDuplicate = $like1;
    $likeDuplicate['id'] = $alphaUri . '/activities/like-duplicate';
    if (($send($likeDuplicate)['result_code'] ?? '') !== 'like_duplicate' || bms_activitypub_federated_interaction_count($postId, 2, 'Like') !== 1) {
        throw new RuntimeException('A duplicate semantic Like created duplicate visible state.');
    }
    $wrongUndoLike = ['id' => $betaUri . '/activities/undo-like-wrong', 'type' => 'Undo', 'actor' => $betaUri, 'object' => ['id' => $like1['id'], 'type' => 'Like', 'actor' => $betaUri, 'object' => $currentUri]];
    bms_api_smoke_expect_security_exception(403, static fn() => $send($wrongUndoLike, 'beta'));
    $undoLike = ['id' => $alphaUri . '/activities/undo-like-1', 'type' => 'Undo', 'actor' => $alphaUri, 'object' => $like1];
    if (($send($undoLike)['result_code'] ?? '') !== 'like_undone' || bms_activitypub_federated_interaction_count($postId, 2, 'Like') !== 0) {
        throw new RuntimeException('Undo Like did not remove only the exact owning interaction.');
    }
    $likeAgain = $like1;
    $likeAgain['id'] = $alphaUri . '/activities/like-again';
    if (($send($likeAgain)['result_code'] ?? '') !== 'like_recorded' || bms_activitypub_federated_interaction_count($postId, 2, 'Like') !== 1) {
        throw new RuntimeException('A new Like after Undo did not create one current interaction.');
    }
    $undoDuplicate = ['id' => $alphaUri . '/activities/undo-like-duplicate', 'type' => 'Undo', 'actor' => $alphaUri, 'object' => $likeDuplicate];
    if (($send($undoDuplicate)['result_code'] ?? '') !== 'like_undo_inactive' || bms_activitypub_federated_interaction_count($postId, 2, 'Like') !== 1) {
        throw new RuntimeException('Undo of a duplicate activity removed a different current Like.');
    }

    $announce = ['id' => $alphaUri . '/activities/announce-1', 'type' => 'Announce', 'actor' => $alphaUri, 'object' => $currentUri];
    if (($send($announce)['result_code'] ?? '') !== 'announce_recorded') {
        throw new RuntimeException('A valid Announce was not recorded.');
    }
    $announceDuplicate = $announce;
    $announceDuplicate['id'] = $alphaUri . '/activities/announce-duplicate';
    if (($send($announceDuplicate)['result_code'] ?? '') !== 'announce_duplicate') {
        throw new RuntimeException('A duplicate Announce was not idempotent.');
    }
    $undoAnnounce = ['id' => $alphaUri . '/activities/undo-announce-1', 'type' => 'Undo', 'actor' => $alphaUri, 'object' => $announce];
    if (($send($undoAnnounce)['result_code'] ?? '') !== 'announce_undone' || bms_activitypub_federated_interaction_count($postId, 2, 'Announce') !== 0) {
        throw new RuntimeException('Undo Announce did not remove the exact owning interaction.');
    }

    $retiredLike = ['id' => $alphaUri . '/activities/like-retired', 'type' => 'Like', 'actor' => $alphaUri, 'object' => $retiredUri];
    if (($send($retiredLike)['result_code'] ?? '') !== 'like_target_retired') {
        throw new RuntimeException('A Like against a retired generation was not isolated.');
    }
    $retiredInteraction = $pdo->query("SELECT * FROM " . bms_table('activitypub_remote_interactions') . " WHERE current_activity_uri = " . $pdo->quote($retiredLike['id']))->fetch();
    if (!is_array($retiredInteraction) || (int)$retiredInteraction['target_publication_generation'] !== 1 || (string)$retiredInteraction['state'] !== 'target_retired') {
        throw new RuntimeException('A retired-generation interaction migrated to the current generation.');
    }

    $malformed = ['id' => $alphaUri . '/activities/malformed-create', 'type' => 'Create', 'actor' => $alphaUri, 'object' => $alphaUri . '/notes/not-embedded'];
    bms_api_smoke_expect_security_exception(400, static fn() => $send($malformed));
    bms_activitypub_block_actor($betaUri, 'Stage 5 blocked actor fixture.');
    $blockedLike = ['id' => $betaUri . '/activities/blocked-like', 'type' => 'Like', 'actor' => $betaUri, 'object' => $currentUri];
    if (($send($blockedLike, 'beta')['result_code'] ?? '') !== 'blocked_actor') {
        throw new RuntimeException('A blocked actor was able to create a Stage 5 interaction.');
    }
    $pdo->prepare('INSERT INTO ' . bms_table('activitypub_blocks') . " (block_type, block_value, reason, created_at, updated_at) VALUES ('domain', 'blocked.example', '', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute();
    if (!bms_activitypub_actor_is_blocked('https://blocked.example/users/test')) {
        throw new RuntimeException('A blocked domain did not apply across Stage 5 activity types.');
    }

    $rateCount = $pdo->prepare("SELECT COUNT(*) FROM " . bms_table('activitypub_inbox_receipts') . " WHERE actor_uri = :actor_uri AND activity_type = 'Create' AND received_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)");
    $rateCount->execute(['actor_uri' => $alphaUri]);
    $createCount = (int)$rateCount->fetchColumn();
    $rateInsert = $pdo->prepare("INSERT INTO " . bms_table('activitypub_inbox_receipts') . " (activity_uri, activity_type, actor_uri, key_id, body_hash, signature_date, activity_json, status, result_code, received_at, processed_at) VALUES (:activity_uri, 'Create', :actor_uri, :key_id, :body_hash, UTC_TIMESTAMP(), '{}', 'ignored', 'rate_fixture', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    for ($index = $createCount; $index < 30; $index++) {
        $rateInsert->execute(['activity_uri' => $alphaUri . '/activities/rate-' . $index, 'actor_uri' => $alphaUri, 'key_id' => (string)$actors['alpha']['key_id'], 'body_hash' => hash('sha256', 'rate-' . $index)]);
    }
    bms_api_smoke_expect_security_exception(429, static fn() => bms_activitypub_enforce_stage5_rate_limit($alphaUri, 'Create'));

    $replayActivity = ['id' => $alphaUri . '/activities/replay-check', 'type' => 'Like', 'actor' => $alphaUri, 'object' => $currentUri];
    $clock++;
    $replayRequest = bms_api_smoke_signed_activity_request($replayActivity, (string)$actors['alpha']['key_id'], (string)$actors['alpha']['private'], $clock);
    bms_activitypub_receive_inbox($replayRequest, $fetcher, $resolver, $clock);
    bms_api_smoke_expect_security_exception(409, static fn() => bms_activitypub_receive_inbox($replayRequest, $fetcher, $resolver, $clock));

    if (bms_comment_count_for_slug('stage-5-target') !== $localCommentCount || bms_stream_like_count_for_slug('stage-5-target') !== $localLikeCount) {
        throw new RuntimeException('Stage 5 changed local comments or anonymous local likes.');
    }
}

function bms_api_smoke_verify_activitypub_stage6(): void
{
    $pdo = bms_db();
    $resolver = static fn(string $host): array => ['93.184.216.34'];
    $actors = [];
    foreach (['owner-target', 'wrong-actor', 'failed-target'] as $name) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $private = '';
        if ($key === false || !openssl_pkey_export($key, $private)) {
            throw new RuntimeException('The Stage 6 actor key could not be generated.');
        }
        $details = openssl_pkey_get_details($key);
        $uri = 'https://93.184.216.34/actors/' . $name;
        $actors[$name] = [
            'uri' => $uri,
            'key_id' => $uri . '#main-key',
            'private' => $private,
            'document' => [
                'id' => $uri, 'type' => 'Person', 'preferredUsername' => $name,
                'name' => ucfirst($name), 'inbox' => 'https://93.184.216.34/inbox/' . $name,
                'publicKey' => ['id' => $uri . '#main-key', 'owner' => $uri, 'publicKeyPem' => is_array($details) ? (string)$details['key'] : ''],
            ],
        ];
    }
    $noteUri = 'https://93.184.216.34/notes/stage-6';
    $remoteNote = [
        'id' => $noteUri, 'type' => 'Note', 'attributedTo' => $actors['owner-target']['uri'],
        'url' => 'https://93.184.216.34/@owner-target/stage-6',
        'published' => '2026-08-31T12:00:00Z',
        'content' => '<p>Stage 6 remote Note.</p><script>alert(1)</script>',
    ];
    $fetchCounts = [];
    $fetcher = static function (string $url) use (&$actors, &$remoteNote, &$fetchCounts, $noteUri): array {
        $fetchCounts[$url] = (int)($fetchCounts[$url] ?? 0) + 1;
        foreach ($actors as $actor) {
            if ($url === $actor['uri']) {
                return ['document' => $actor['document'], 'url' => $url];
            }
        }
        if ($url === $noteUri) {
            return ['document' => $remoteNote, 'url' => $url];
        }
        throw new RuntimeException('Unexpected Stage 6 fetch.');
    };

    $follow = bms_activitypub_follow_remote_actor((string)$actors['owner-target']['uri'], $fetcher, $resolver);
    if (!empty($follow['duplicate']) || (string)($follow['following']['state'] ?? '') !== 'pending' || (int)($follow['delivery_id'] ?? 0) < 1) {
        throw new RuntimeException('Outbound Follow did not create one pending durable relationship.');
    }
    $duplicateFollow = bms_activitypub_follow_remote_actor((string)$actors['owner-target']['uri'], $fetcher, $resolver);
    if (empty($duplicateFollow['duplicate']) || (int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_follow_log'))->fetchColumn() !== 1) {
        throw new RuntimeException('Duplicate outbound Follow was not idempotent.');
    }
    try {
        bms_activitypub_follow_remote_actor('https://127.0.0.1/actor', $fetcher, static fn(string $host): array => ['127.0.0.1']);
        throw new RuntimeException('An owner Follow bypassed SSRF destination checks.');
    } catch (BmsActivityPubSecurityException $e) {
        if ($e->httpStatus() !== 400) {
            throw $e;
        }
    }

    $clock = time();
    $send = static function (array $activity, string $name) use (&$clock, &$actors, $fetcher, $resolver): array {
        $clock++;
        $actor = $actors[$name];
        return bms_activitypub_receive_inbox(
            bms_api_smoke_signed_activity_request($activity, (string)$actor['key_id'], (string)$actor['private'], $clock),
            $fetcher,
            $resolver,
            $clock
        );
    };
    $followUri = (string)$follow['following']['follow_activity_uri'];
    $wrongAccept = ['id' => $actors['wrong-actor']['uri'] . '/activities/accept-wrong', 'type' => 'Accept', 'actor' => $actors['wrong-actor']['uri'], 'object' => $followUri];
    if (($send($wrongAccept, 'wrong-actor')['result_code'] ?? '') !== 'response_unmatched'
        || (string)$pdo->query('SELECT state FROM ' . bms_table('activitypub_following'))->fetchColumn() !== 'pending') {
        throw new RuntimeException('A wrong-actor Accept changed Following state.');
    }
    $wrongReject = ['id' => $actors['wrong-actor']['uri'] . '/activities/reject-wrong', 'type' => 'Reject', 'actor' => $actors['wrong-actor']['uri'], 'object' => $followUri];
    if (($send($wrongReject, 'wrong-actor')['result_code'] ?? '') !== 'response_unmatched'
        || (string)$pdo->query('SELECT state FROM ' . bms_table('activitypub_following'))->fetchColumn() !== 'pending') {
        throw new RuntimeException('A wrong-actor Reject changed Following state.');
    }
    $reject = ['id' => $actors['owner-target']['uri'] . '/activities/reject', 'type' => 'Reject', 'actor' => $actors['owner-target']['uri'], 'object' => $followUri];
    if (($send($reject, 'owner-target')['result_code'] ?? '') !== 'following_rejected'
        || (string)$pdo->query('SELECT state FROM ' . bms_table('activitypub_following'))->fetchColumn() !== 'rejected') {
        throw new RuntimeException('The correct actor Reject did not complete Following.');
    }
    $follow = bms_activitypub_follow_remote_actor((string)$actors['owner-target']['uri'], $fetcher, $resolver);
    if (!empty($follow['duplicate']) || (string)$follow['following']['follow_activity_uri'] === $followUri) {
        throw new RuntimeException('A new Follow after Reject did not receive a new durable identity.');
    }
    $followUri = (string)$follow['following']['follow_activity_uri'];
    $accept = ['id' => $actors['owner-target']['uri'] . '/activities/accept', 'type' => 'Accept', 'actor' => $actors['owner-target']['uri'], 'object' => $followUri];
    if (($send($accept, 'owner-target')['result_code'] ?? '') !== 'following_accepted'
        || (string)$pdo->query('SELECT state FROM ' . bms_table('activitypub_following'))->fetchColumn() !== 'accepted') {
        throw new RuntimeException('The correct actor Accept did not complete Following.');
    }
    try {
        bms_activitypub_follow_remote_actor('https://93.184.216.34/actors/unavailable', $fetcher, $resolver);
        throw new RuntimeException('An unavailable actor produced a Following relationship.');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'An unavailable actor produced a Following relationship.') {
            throw $e;
        }
    }

    $create = ['id' => $actors['owner-target']['uri'] . '/activities/create-note', 'type' => 'Create', 'actor' => $actors['owner-target']['uri'], 'object' => $remoteNote];
    if (($send($create, 'owner-target')['result_code'] ?? '') !== 'remote_note_cached') {
        throw new RuntimeException('A followed actor Note did not enter the private cache.');
    }
    $duplicateCreate = $create;
    $duplicateCreate['id'] = $actors['owner-target']['uri'] . '/activities/create-note-duplicate';
    if (($send($duplicateCreate, 'owner-target')['result_code'] ?? '') !== 'remote_note_create_duplicate') {
        throw new RuntimeException('A duplicate remote Note Create was not idempotent by object identity.');
    }
    $cached = bms_activitypub_fetch_remote_object($noteUri, false, $fetcher, $resolver);
    if ((string)$cached['actor_uri'] !== (string)$actors['owner-target']['uri'] || str_contains((string)$cached['content_html'], '<script') || count(bms_activitypub_remote_inbox_rows()) !== 1) {
        throw new RuntimeException('Remote Note identity, sanitation, or private Following visibility failed.');
    }
    $remoteNote['content'] = '<p>Stage 6 updated remote Note.</p>';
    $update = ['id' => $actors['owner-target']['uri'] . '/activities/update-note', 'type' => 'Update', 'actor' => $actors['owner-target']['uri'], 'object' => $remoteNote];
    if (($send($update, 'owner-target')['result_code'] ?? '') !== 'remote_note_updated') {
        throw new RuntimeException('A followed actor Note Update was not applied.');
    }
    $wrongUpdate = $update;
    $wrongUpdate['id'] = $actors['wrong-actor']['uri'] . '/activities/update-wrong';
    $wrongUpdate['actor'] = $actors['wrong-actor']['uri'];
    $wrongUpdate['object']['attributedTo'] = $actors['wrong-actor']['uri'];
    bms_api_smoke_expect_security_exception(403, static fn() => $send($wrongUpdate, 'wrong-actor'));
    $pdo->prepare('UPDATE ' . bms_table('activitypub_remote_objects') . ' SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE object_uri = :object_uri')->execute(['object_uri' => $noteUri]);
    $remoteNote['content'] = '<p>Stage 6 refreshed stale Note.</p>';
    $refreshed = bms_activitypub_fetch_remote_object($noteUri, false, $fetcher, $resolver);
    if (!str_contains((string)$refreshed['content_text'], 'refreshed stale Note')) {
        throw new RuntimeException('A stale cached remote Note was not safely refreshed.');
    }

    $localPostId = bms_upsert_database_content([
        'title' => 'Stage 6 local guard', 'slug' => 'stage-6-local-guard', 'status' => 'published',
        'content_type' => 'stream', 'post_type' => 'stream', 'date' => '2026-08-31', 'description' => '',
        'category' => 'Stream', 'tags' => [], 'body' => 'Local state guard.', 'front_matter' => [],
    ], 'published', 'stage-6-local-guard.md', 1);
    $commentInsert = $pdo->prepare("INSERT INTO " . bms_table('comments') . " (post_slug, post_id, user_id, parent_id, body, status, ip_hash, user_agent_hash, created_at, updated_at, approved_at) VALUES ('stage-6-local-guard', :post_id, 1, NULL, 'Local comment.', 'approved', :ip, :ua, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $commentInsert->execute(['post_id' => $localPostId, 'ip' => hash('sha256', 'stage6-ip'), 'ua' => hash('sha256', 'stage6-ua')]);
    $likeInsert = $pdo->prepare("INSERT INTO " . bms_table('stream_likes') . " (post_id, post_slug, visitor_hash, created_at) VALUES (:post_id, 'stage-6-local-guard', :visitor, UTC_TIMESTAMP())");
    $likeInsert->execute(['post_id' => $localPostId, 'visitor' => hash('sha256', 'stage6-like')]);
    $localComments = bms_comment_count_for_slug('stage-6-local-guard');
    $localLikes = bms_stream_like_count_for_slug('stage-6-local-guard');

    $actorFetchesBefore = (int)($fetchCounts[$actors['owner-target']['uri']] ?? 0);
    $pdo->prepare('UPDATE ' . bms_table('activitypub_remote_actors') . ' SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE actor_uri = :actor_uri')->execute(['actor_uri' => (string)$actors['owner-target']['uri']]);
    $like = bms_activitypub_owner_interact('Like', $noteUri, $fetcher, $resolver);
    if ((int)($fetchCounts[$actors['owner-target']['uri']] ?? 0) <= $actorFetchesBefore) {
        throw new RuntimeException('An owner action reused an expired remote actor without safe refresh.');
    }
    $firstLikeUri = (string)$like['interaction']['current_activity_uri'];
    if (!empty($like['duplicate']) || empty(bms_activitypub_owner_interact('Like', $noteUri, $fetcher, $resolver)['duplicate'])) {
        throw new RuntimeException('Owner Like idempotency failed.');
    }
    $undoLike = bms_activitypub_owner_undo_interaction((int)$like['interaction']['id']);
    if (!empty($undoLike['duplicate']) || empty(bms_activitypub_owner_undo_interaction((int)$like['interaction']['id'])['duplicate'])) {
        throw new RuntimeException('Owner Undo Like idempotency failed.');
    }
    $likeAgain = bms_activitypub_owner_interact('Like', $noteUri, $fetcher, $resolver);
    if ((string)$likeAgain['interaction']['current_activity_uri'] === $firstLikeUri) {
        throw new RuntimeException('A new Like after Undo resurrected the old activity identity.');
    }
    $wrongActor = bms_activitypub_cached_remote_actor((string)$actors['wrong-actor']['uri'], true);
    if (!is_array($wrongActor)) {
        throw new RuntimeException('The Stage 6 follower fanout fixture actor was not cached.');
    }
    $followerInsert = $pdo->prepare("INSERT INTO " . bms_table('activitypub_followers') . " (remote_actor_id, actor_uri, follow_activity_uri, follow_receipt_id, state, response_activity_uri, followed_at, moderated_at, created_at, updated_at) VALUES (:remote_actor_id, :actor_uri, :follow_activity_uri, 1, 'accepted', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $followerInsert->execute(['remote_actor_id' => (int)$wrongActor['id'], 'actor_uri' => (string)$wrongActor['actor_uri'], 'follow_activity_uri' => (string)$wrongActor['actor_uri'] . '/activities/follow-local']);
    $announce = bms_activitypub_owner_interact('Announce', $noteUri, $fetcher, $resolver);
    $firstAnnounceUri = (string)$announce['interaction']['current_activity_uri'];
    $announcePayload = bms_activitypub_owner_activity_document($firstAnnounceUri);
    $announceDeliveries = $pdo->prepare("SELECT COUNT(DISTINCT inbox_url) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'owner_activity' AND activity_uri = :activity_uri");
    $announceDeliveries->execute(['activity_uri' => $firstAnnounceUri]);
    if (($announcePayload['to'][0] ?? '') !== 'https://www.w3.org/ns/activitystreams#Public' || (int)$announceDeliveries->fetchColumn() !== 2) {
        throw new RuntimeException('Owner Announce did not fan out as one public durable boost to its author and accepted followers.');
    }
    if (empty(bms_activitypub_owner_interact('Announce', $noteUri, $fetcher, $resolver)['duplicate'])) {
        throw new RuntimeException('Owner Announce idempotency failed.');
    }
    bms_activitypub_owner_undo_interaction((int)$announce['interaction']['id']);
    $announceAgain = bms_activitypub_owner_interact('Announce', $noteUri, $fetcher, $resolver);
    if ((string)$announceAgain['interaction']['current_activity_uri'] === $firstAnnounceUri) {
        throw new RuntimeException('A new Announce after Undo resurrected the old activity identity.');
    }

    $reply = bms_activitypub_create_owner_reply_draft($noteUri, 'Owner reply body.', 'Stage 6 owner reply');
    $replyPost = bms_activitypub_find_stream_post((int)$reply['post_id']);
    if (!is_array($replyPost) || (string)$replyPost['status'] !== 'draft' || (string)bms_activitypub_reply_target_for_post((int)$reply['post_id'])['in_reply_to_uri'] !== $noteUri) {
        throw new RuntimeException('Owner reply did not create a normal Bonumark draft with federation metadata.');
    }
    bms_publish_file((string)$reply['filename']);
    $generationOne = bms_activitypub_local_object_generation((int)$reply['post_id'], 1);
    $createPayload = is_array($generationOne) ? json_decode((string)$generationOne['last_object_json'], true) : null;
    if (!is_array($generationOne) || (string)($createPayload['inReplyTo'] ?? '') !== $noteUri) {
        throw new RuntimeException('Published owner reply lost inReplyTo or normal generation identity.');
    }
    $replyTargets = $pdo->prepare('SELECT COUNT(*) FROM ' . bms_table('activitypub_deliveries') . ' d INNER JOIN ' . bms_table('activitypub_publication_events') . ' e ON e.id = d.event_id WHERE e.post_id = :post_id AND d.recipient_actor_ids_json = :recipient_actor_ids_json');
    $replyTargets->execute(['post_id' => (int)$reply['post_id'], 'recipient_actor_ids_json' => json_encode([(int)$follow['following']['remote_actor_id']], JSON_UNESCAPED_SLASHES)]);
    if ((int)$replyTargets->fetchColumn() < 1) {
        throw new RuntimeException('Owner reply publication did not include its exact remote target actor.');
    }
    $publishedReply = bms_activitypub_find_stream_post((int)$reply['post_id']);
    bms_update_stream_post_body((array)$publishedReply, 'Owner reply changed.');
    $latestType = (string)$pdo->query('SELECT event_type FROM ' . bms_table('activitypub_publication_events') . ' WHERE post_id = ' . (int)$reply['post_id'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($latestType !== 'updated') {
        throw new RuntimeException('Owner reply Update did not use the existing publication lifecycle.');
    }
    bms_unpublish_file((string)$reply['filename']);
    $retired = bms_activitypub_local_object_generation((int)$reply['post_id'], 1);
    if (!is_array($retired) || empty($retired['deleted_at'])) {
        throw new RuntimeException('Owner reply Delete did not retire its ActivityPub generation.');
    }
    bms_publish_file((string)$reply['filename']);
    $generationTwo = bms_activitypub_local_object_generation((int)$reply['post_id'], 2);
    $republishedPayload = is_array($generationTwo) ? json_decode((string)$generationTwo['last_object_json'], true) : null;
    if (!is_array($generationTwo) || (string)($republishedPayload['inReplyTo'] ?? '') !== $noteUri || (string)$generationTwo['object_uri'] === (string)$retired['object_uri']) {
        throw new RuntimeException('Republished owner reply did not create a new generation with the same remote target.');
    }

    $failedFollow = bms_activitypub_follow_remote_actor((string)$actors['failed-target']['uri'], $fetcher, $resolver);
    $transport = static fn(array $target, array $options): array => [
        'status' => str_contains((string)($target['url'] ?? ''), 'failed-target') ? 400 : 202,
        'headers' => [], 'body' => '', 'primary_ip' => '93.184.216.34',
    ];
    $ownerDelivery = bms_activitypub_run_owner_deliveries(100, $transport, $resolver);
    $publicationDelivery = bms_activitypub_run_publication_deliveries(100, $transport, $resolver, $fetcher);
    if ((int)$ownerDelivery['count'] < 1 || (int)$publicationDelivery['count'] < 1) {
        throw new RuntimeException('Stage 6 durable owner or reply publication delivery did not run.');
    }
    $failedState = $pdo->prepare('SELECT state, last_error FROM ' . bms_table('activitypub_following') . ' WHERE id = :id');
    $failedState->execute(['id' => (int)$failedFollow['following']['id']]);
    $failedState = $failedState->fetch();
    if (!is_array($failedState) || (string)$failedState['state'] !== 'failed' || trim((string)$failedState['last_error']) === '') {
        throw new RuntimeException('A permanent Follow delivery error did not produce an auditable failed state.');
    }

    $wrongDelete = ['id' => $actors['wrong-actor']['uri'] . '/activities/delete-wrong', 'type' => 'Delete', 'actor' => $actors['wrong-actor']['uri'], 'object' => $noteUri];
    bms_api_smoke_expect_security_exception(403, static fn() => $send($wrongDelete, 'wrong-actor'));
    $delete = ['id' => $actors['owner-target']['uri'] . '/activities/delete-note', 'type' => 'Delete', 'actor' => $actors['owner-target']['uri'], 'object' => $noteUri];
    if (($send($delete, 'owner-target')['result_code'] ?? '') !== 'remote_note_deleted' || bms_activitypub_remote_inbox_rows() !== []) {
        throw new RuntimeException('A remote Note Delete did not retire it from the private inbox.');
    }
    $update['id'] = $actors['owner-target']['uri'] . '/activities/update-after-delete';
    if (($send($update, 'owner-target')['result_code'] ?? '') !== 'remote_note_update_after_delete') {
        throw new RuntimeException('A remote Note Update resurrected deleted cached content.');
    }
    $create['id'] = $actors['owner-target']['uri'] . '/activities/create-after-delete';
    if (($send($create, 'owner-target')['result_code'] ?? '') !== 'remote_note_create_after_delete') {
        throw new RuntimeException('A changed Create activity URI resurrected deleted cached content.');
    }
    bms_api_smoke_expect_security_exception(410, static fn() => bms_activitypub_fetch_remote_object($noteUri, true, $fetcher, $resolver));

    $followingId = (int)$follow['following']['id'];
    $unfollow = bms_activitypub_unfollow_remote_actor($followingId);
    if (!empty($unfollow['duplicate']) || empty(bms_activitypub_unfollow_remote_actor($followingId)['duplicate'])) {
        throw new RuntimeException('Undo Follow or duplicate Unfollow handling failed.');
    }
    $refollow = bms_activitypub_follow_remote_actor((string)$actors['owner-target']['uri'], $fetcher, $resolver);
    if ((string)$refollow['following']['follow_activity_uri'] === $followUri) {
        throw new RuntimeException('A new Follow after removal reused retired activity identity.');
    }

    bms_activitypub_block_actor((string)$actors['owner-target']['uri'], 'Stage 6 block fixture.');
    if (bms_activitypub_remote_inbox_rows() !== []) {
        throw new RuntimeException('A blocked actor remained visible in the private owner inbox.');
    }
    try {
        bms_activitypub_owner_interact('Like', $noteUri, $fetcher, $resolver);
        throw new RuntimeException('A blocked actor remained eligible for owner interaction.');
    } catch (BmsActivityPubSecurityException $e) {
        if ($e->httpStatus() !== 403) {
            throw $e;
        }
    }
    $blockedPending = $pdo->prepare("SELECT COUNT(*) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'owner_activity' AND recipient_actor_ids_json = :recipient_actor_ids_json AND status IN ('pending', 'retry', 'processing')");
    $blockedPending->execute(['recipient_actor_ids_json' => json_encode([(int)$follow['following']['remote_actor_id']], JSON_UNESCAPED_SLASHES)]);
    if ((int)$blockedPending->fetchColumn() !== 0) {
        throw new RuntimeException('Blocking an actor left queued owner actions eligible for delivery.');
    }
    $blockedDomain = bms_activitypub_block_domain_for_actor((string)$actors['failed-target']['uri'], 'Stage 6 domain block fixture.');
    if ($blockedDomain !== '93.184.216.34' || !bms_activitypub_actor_is_blocked((string)$actors['wrong-actor']['uri'])) {
        throw new RuntimeException('A blocked domain remained eligible in Stage 6 owner workflows.');
    }
    bms_api_smoke_expect_security_exception(403, static fn() => bms_activitypub_follow_remote_actor((string)$actors['wrong-actor']['uri'], $fetcher, $resolver));
    if (bms_comment_count_for_slug('stage-6-local-guard') !== $localComments || bms_stream_like_count_for_slug('stage-6-local-guard') !== $localLikes) {
        throw new RuntimeException('Stage 6 changed local comments or anonymous likes.');
    }
    if (str_contains((string)file_get_contents(__DIR__ . '/../_bonumark_stream/app/renderer.php'), 'activitypub_remote_objects')) {
        throw new RuntimeException('Stage 6 introduced a public remote timeline in the theme renderer.');
    }
}

function bms_api_smoke_verify_activitypub_stage6_disabled(): void
{
    foreach ([
        static fn() => bms_activitypub_follow_remote_actor('https://93.184.216.34/actors/disabled'),
        static fn() => bms_activitypub_owner_interact('Like', 'https://93.184.216.34/notes/disabled'),
        static fn() => bms_activitypub_create_owner_reply_draft('https://93.184.216.34/notes/disabled', 'Disabled reply.'),
    ] as $operation) {
        try {
            $operation();
            throw new RuntimeException('A Stage 6 owner action ran while ActivityPub was disabled.');
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'ActivityPub is disabled.') {
                throw $e;
            }
        }
    }
}

function bms_api_smoke_public_post_count(): int
{
    $_GET = ['status' => 'published', 'per_page' => '100', 'page' => '1'];
    $result = bms_api_read_stream_posts();
    return (int)($result['pagination']['total'] ?? 0);
}

function bms_api_smoke_expect_api_exception(string $expectedCode, callable $callback): void
{
    try {
        $callback();
    } catch (BMS_Api_Exception $e) {
        if ($e->apiCode !== $expectedCode) {
            throw new RuntimeException("Expected API error {$expectedCode}, got {$e->apiCode}.");
        }
        return;
    }
    throw new RuntimeException("Expected API error {$expectedCode}, but no API exception was thrown.");
}

function bms_api_smoke_expect_security_exception(int $expectedStatus, callable $callback): void
{
    try {
        $callback();
    } catch (BmsActivityPubSecurityException $e) {
        if ($e->httpStatus() !== $expectedStatus) {
            throw new RuntimeException("Expected ActivityPub HTTP {$expectedStatus}, got {$e->httpStatus()}.");
        }
        return;
    }
    throw new RuntimeException("Expected ActivityPub HTTP {$expectedStatus}, but no security exception was thrown.");
}

function bms_api_smoke_signed_activity_request(array $activity, string $keyId, string $privateKey, int $timestamp): array
{
    $body = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        throw new RuntimeException('The ActivityPub smoke activity could not be encoded.');
    }
    $date = gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $target = '/activitypub/inbox';
    $signing = "(request-target): post {$target}\nhost: example.test\ndate: {$date}\ndigest: {$digest}";
    $signature = '';
    if (!openssl_sign($signing, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The ActivityPub smoke request could not be signed.');
    }
    return [
        'method' => 'POST',
        'request_target' => $target,
        'body' => $body,
        'headers' => [
            'host' => 'example.test',
            'date' => $date,
            'digest' => $digest,
            'content-type' => 'application/activity+json',
            'content-length' => (string)strlen($body),
            'signature' => 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode($signature) . '"',
        ],
    ];
}

function bms_api_smoke_rfc9421_activity_request(array $activity, string $keyId, string $privateKey, int $timestamp): array
{
    $body = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        throw new RuntimeException('The RFC 9421 ActivityPub smoke activity could not be encoded.');
    }
    $target = '/activitypub/inbox';
    $targetUri = 'https://example.test' . $target;
    $contentDigest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
    $signatureParams = '("@method" "@target-uri" "content-digest");created=' . $timestamp
        . ';keyid="' . $keyId . '";alg="rsa-v1_5-sha256"';
    $signing = '"@method": POST'
        . "\n\"@target-uri\": " . $targetUri
        . "\n\"content-digest\": " . $contentDigest
        . "\n\"@signature-params\": " . $signatureParams;
    $signature = '';
    if (!openssl_sign($signing, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The RFC 9421 ActivityPub smoke request could not be signed.');
    }
    return [
        'method' => 'POST',
        'request_target' => $target,
        'target_uri' => $targetUri,
        'body' => $body,
        'headers' => [
            'host' => 'example.test',
            'content-digest' => $contentDigest,
            'content-type' => 'application/activity+json',
            'content-length' => (string)strlen($body),
            'signature-input' => 'sig1=' . $signatureParams,
            'signature' => 'sig1=:' . base64_encode($signature) . ':',
        ],
    ];
}

function bms_api_smoke_http_request(string $url, string $method = 'GET', array $headers = []): array
{
    $parts = parse_url($url);
    $host = is_array($parts) ? (string)($parts['host'] ?? '') : '';
    $port = is_array($parts) ? (int)($parts['port'] ?? 80) : 0;
    $target = is_array($parts) ? (string)($parts['path'] ?? '/') : '/';
    if (is_array($parts) && isset($parts['query'])) {
        $target .= '?' . (string)$parts['query'];
    }
    $errorNumber = 0;
    $errorMessage = '';
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errorNumber, $errorMessage, 2);
    if (!is_resource($socket)) {
        throw new RuntimeException('The route smoke request failed: ' . $errorMessage);
    }
    stream_set_timeout($socket, 5);
    $requestHeaders = array_merge(['Host: ' . $host . ':' . $port, 'Connection: close'], $headers);
    $request = strtoupper($method) . ' ' . $target . " HTTP/1.1\r\n" . implode("\r\n", $requestHeaders) . "\r\n\r\n";
    fwrite($socket, $request);
    $raw = stream_get_contents($socket);
    fclose($socket);
    if (!is_string($raw) || !str_contains($raw, "\r\n\r\n")) {
        throw new RuntimeException('The route smoke server returned an invalid HTTP response.');
    }
    [$headerText, $body] = explode("\r\n\r\n", $raw, 2);
    preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $headerText, $statusMatch);
    $status = (int)($statusMatch[1] ?? 0);
    $responseHeaders = [];
    foreach (preg_split('/\r?\n/', $headerText) ?: [] as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
}

function bms_api_smoke_activitypub_route_responses(string $root): void
{
    if ($root === '' || !is_file($root . '/index.php')) {
        throw new RuntimeException('The temporary route-test installation is unavailable.');
    }
    $tombstoneInsert = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_local_objects') . ' (post_id, object_uri, object_type, content_hash, last_object_json, last_human_url, publication_generation, transition_sequence, published_at, updated_at, deleted_at, created_at) VALUES (998, :object_uri, \'Note\', \'\', NULL, NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $tombstoneInsert->execute(['object_uri' => bms_activitypub_generation_object_url(998, 1)]);
    $activeGenerationUri = bms_activitypub_generation_object_url(997, 2);
    $activeGenerationObject = json_encode(['id' => $activeGenerationUri, 'type' => 'Note', 'content' => '<p>Active generation fixture.</p>'], JSON_UNESCAPED_SLASHES);
    $activeInsert = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_local_objects') . ' (post_id, object_uri, object_type, content_hash, last_object_json, last_human_url, publication_generation, transition_sequence, published_at, updated_at, deleted_at, created_at) VALUES (997, :object_uri, \'Note\', :content_hash, :object_json, NULL, 2, 2, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP())');
    $activeInsert->execute(['object_uri' => $activeGenerationUri, 'content_hash' => hash('sha256', 'active-generation-fixture'), 'object_json' => $activeGenerationObject]);
    $port = random_int(43100, 43999);
    $log = $root . '/activitypub-route-server.log';
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
    $process = proc_open($command, $descriptors, $pipes, $root, array_merge($_ENV, getenv()));
    if (!is_resource($process)) {
        throw new RuntimeException('The ActivityPub route test server could not be started.');
    }
    fclose($pipes[0]);
    $base = 'http://127.0.0.1:' . $port . '/index.php?__bonumark_route=';
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $probe = bms_api_smoke_http_request($base . 'activitypub_actor', 'GET', ['Accept: application/activity+json']);
                if ((int)$probe['status'] > 0) {
                    $ready = true;
                    break;
                }
            } catch (Throwable $e) {
            }
            usleep(100000);
        }
        if (!$ready) {
            $serverLog = is_file($log) ? trim((string)file_get_contents($log)) : '';
            throw new RuntimeException('The ActivityPub route test server did not become ready.' . ($serverLog !== '' ? ' ' . $serverLog : ''));
        }

        $actor = bms_api_smoke_http_request($base . 'activitypub_actor', 'GET', ['Accept: application/activity+json']);
        if ((int)$actor['status'] !== 200 || !str_starts_with(strtolower((string)($actor['headers']['content-type'] ?? '')), 'application/activity+json')
            || !str_contains((string)$actor['body'], '"inbox":"https://example.test/activitypub/inbox"')
            || str_contains((string)$actor['body'], 'private_key') || str_contains((string)$actor['body'], 'api-smoke-')) {
            throw new RuntimeException('The actor route failed ActivityPub negotiation or exposed private configuration.');
        }
        $jsonLd = bms_api_smoke_http_request($base . 'activitypub_actor', 'GET', ['Accept: application/ld+json; profile="https://www.w3.org/ns/activitystreams"']);
        if ((int)$jsonLd['status'] !== 200 || !str_starts_with(strtolower((string)($jsonLd['headers']['content-type'] ?? '')), 'application/ld+json')) {
            throw new RuntimeException('The actor route did not negotiate ActivityStreams JSON-LD.');
        }
        $ordinaryJson = bms_api_smoke_http_request($base . 'activitypub_actor', 'GET', ['Accept: application/json']);
        if ((int)$ordinaryJson['status'] !== 200 || !str_starts_with(strtolower((string)($ordinaryJson['headers']['content-type'] ?? '')), 'application/json')) {
            throw new RuntimeException('The actor route did not negotiate ordinary JSON.');
        }
        $head = bms_api_smoke_http_request($base . 'activitypub_actor', 'HEAD', ['Accept: application/activity+json']);
        if ((int)$head['status'] !== 200 || (string)$head['body'] !== '' || (int)($head['headers']['content-length'] ?? 0) < 1) {
            throw new RuntimeException('The actor HEAD response did not preserve GET metadata without a body.');
        }
        $method = bms_api_smoke_http_request($base . 'activitypub_actor', 'POST', ['Accept: application/activity+json']);
        if ((int)$method['status'] !== 405 || (string)($method['headers']['allow'] ?? '') !== 'GET, HEAD') {
            throw new RuntimeException('The read-only actor route did not reject an unsupported method.');
        }
        $unacceptable = bms_api_smoke_http_request($base . 'activitypub_actor', 'GET', ['Accept: text/html']);
        if ((int)$unacceptable['status'] !== 406) {
            throw new RuntimeException('The actor route did not reject an unacceptable media type.');
        }
        $webfingerUrl = $base . 'activitypub_webfinger&resource=' . rawurlencode('acct:admin@example.test');
        $jrd = bms_api_smoke_http_request($webfingerUrl, 'GET', ['Accept: application/jrd+json']);
        if ((int)$jrd['status'] !== 200 || !str_starts_with(strtolower((string)($jrd['headers']['content-type'] ?? '')), 'application/jrd+json')) {
            throw new RuntimeException('WebFinger did not return JRD for the owner identity.');
        }
        $foreign = bms_api_smoke_http_request($base . 'activitypub_webfinger&resource=' . rawurlencode('acct:other@example.test'), 'GET', ['Accept: application/jrd+json']);
        if ((int)$foreign['status'] !== 404) {
            throw new RuntimeException('WebFinger resolved an identity other than the intended owner.');
        }
        $inboxGet = bms_api_smoke_http_request($base . 'activitypub_inbox', 'GET', ['Accept: application/activity+json']);
        if ((int)$inboxGet['status'] !== 405 || (string)($inboxGet['headers']['allow'] ?? '') !== 'POST') {
            throw new RuntimeException('The signed inbox accepted a read method.');
        }
        $followers = bms_api_smoke_http_request($base . 'activitypub_followers', 'GET', ['Accept: application/activity+json']);
        if ((int)$followers['status'] !== 200 || !str_contains((string)$followers['body'], '"totalItems":0')) {
            throw new RuntimeException('The followers collection exposed a removed follower.');
        }
        $unpublished = bms_api_smoke_http_request($base . 'activitypub_object&post_id=999', 'GET', ['Accept: application/activity+json']);
        if ((int)$unpublished['status'] !== 404) {
            throw new RuntimeException('An unpublished or missing object was exposed.');
        }
        $tombstone = bms_api_smoke_http_request($base . 'activitypub_object&post_id=998', 'GET', ['Accept: application/activity+json']);
        $tombstoneDocument = json_decode((string)$tombstone['body'], true);
        if ((int)$tombstone['status'] !== 410 || (string)($tombstoneDocument['type'] ?? '') !== 'Tombstone'
            || (string)($tombstoneDocument['id'] ?? '') !== bms_activitypub_generation_object_url(998, 1)) {
            throw new RuntimeException('A retired publication generation did not dereference as a Tombstone with HTTP 410.');
        }
        $activeGeneration = bms_api_smoke_http_request($base . 'activitypub_object&post_id=997&generation=2', 'GET', ['Accept: application/activity+json']);
        $activeGenerationDocument = json_decode((string)$activeGeneration['body'], true);
        if ((int)$activeGeneration['status'] !== 200 || (string)($activeGenerationDocument['type'] ?? '') !== 'Note'
            || (string)($activeGenerationDocument['id'] ?? '') !== $activeGenerationUri) {
            throw new RuntimeException('An active later publication generation was not dereferenceable by its generation-aware URI.');
        }

        bms_api_smoke_set_setting('activitypub_enabled', '0');
        $disabledActor = bms_api_smoke_http_request($base . 'activitypub_actor', 'GET', ['Accept: application/activity+json']);
        $profile = bms_api_smoke_http_request($base . 'profile&user=admin', 'GET', ['Accept: text/html']);
        $apiStatus = bms_api_smoke_http_request($base . 'api_status', 'GET', ['Accept: application/json']);
        if ((int)$disabledActor['status'] !== 404 || (int)$profile['status'] !== 200 || (int)$apiStatus['status'] !== 200) {
            throw new RuntimeException('Disabling ActivityPub changed an existing Profile or Remote API route.');
        }
    } finally {
        proc_terminate($process);
        proc_close($process);
        @unlink($log);
    }
}

function bms_api_smoke_set_setting(string $key, string $value): void
{
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('settings') . ' (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
    $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
}

function bms_api_smoke_copy_tree(string $source, string $destination): void
{
    if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('Could not create temporary workspace.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
        if ($relative === '_bonumark_stream/config.php' || $relative === '_bonumark_stream/installed.lock') {
            continue;
        }
        $target = $destination . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
                throw new RuntimeException('Could not create temporary directory: ' . $relative);
            }
        } elseif ($item->isFile()) {
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Could not create temporary directory: ' . $targetDir);
            }
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Could not copy file into temporary workspace: ' . $relative);
            }
        }
    }
}

function bms_api_smoke_drop_temp_tables(string $prefix): void
{
    try {
        $stmt = bms_db()->query('SHOW TABLES LIKE ' . bms_db()->quote($prefix . '%'));
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($tables as $table) {
            if (is_string($table) && str_starts_with($table, $prefix)) {
                bms_db()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop temporary API smoke tables: " . $e->getMessage() . "\n");
    }
}

function bms_api_smoke_remove_tree(string $path): void
{
    if ($path === '' || !is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}
