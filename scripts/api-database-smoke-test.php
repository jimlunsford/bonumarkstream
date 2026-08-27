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
    'activitypub_observer',
    'activitypub_inbox',
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
        bms_api_smoke_set_setting('activitypub_enabled', in_array($scenario, ['activitypub_observer', 'activitypub_inbox'], true) ? '1' : '0');
        bms_api_smoke_set_setting('activitypub_follow_policy', 'manual');

        $GLOBALS['bms_api_smoke_temp_root'] = $tempRoot;
        bms_api_smoke_run_scenario($scenario);
        $activityPubEvents = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events'))->fetchColumn();
        $activityPubDeliveries = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_deliveries'))->fetchColumn();
        if ($scenario === 'activitypub_observer') {
            $completedEvents = (int)bms_db()->query("SELECT COUNT(*) FROM " . bms_table('activitypub_publication_events') . " WHERE status = 'observed' AND processed_at IS NOT NULL")->fetchColumn();
            if ($activityPubEvents !== 2 || $completedEvents !== 2 || $activityPubDeliveries !== 0) {
                throw new RuntimeException('The ActivityPub observer did not record exactly one publish and one changed update as completed observations.');
            }
        } elseif ($scenario === 'activitypub_inbox') {
            $responseDeliveries = (int)bms_db()->query("SELECT COUNT(*) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'follower_response' AND event_id IS NULL AND status = 'delivered'")->fetchColumn();
            $publicationDeliveries = (int)bms_db()->query("SELECT COUNT(*) FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'publication' OR event_id IS NOT NULL")->fetchColumn();
            $observedEvents = (int)bms_db()->query("SELECT COUNT(*) FROM " . bms_table('activitypub_publication_events') . " WHERE status = 'observed' AND processed_at IS NOT NULL")->fetchColumn();
            if ($activityPubEvents !== 1 || $observedEvents !== 1 || $activityPubDeliveries !== 1 || $responseDeliveries !== 1 || $publicationDeliveries !== 0) {
                throw new RuntimeException('The Stage 3 response queue was not isolated from historical observed publication events.');
            }
        } elseif ($activityPubEvents !== 0 || $activityPubDeliveries !== 0) {
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
            if ((string)($ignored['result_code'] ?? '') !== 'unsupported_activity') {
                throw new RuntimeException('An unsupported signed activity was not retained as ignored.');
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
            if ((string)($rotatedResult['result_code'] ?? '') !== 'unsupported_activity'
                || !is_array($rotatedCachedActor)
                || (string)$rotatedCachedActor['public_key_id'] !== $remoteActorUri . '#rotated-key') {
                throw new RuntimeException('A legitimate authenticated remote key-ID rotation was not refreshed safely.');
            }
            bms_api_smoke_activitypub_route_responses((string)($GLOBALS['bms_api_smoke_temp_root'] ?? ''));
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
