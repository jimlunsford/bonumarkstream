<?php
/**
 * Bonumark Stream database smoke test.
 *
 * This CLI-only test exercises two distinct database paths against a real
 * MySQL/MariaDB database:
 *
 * 1. Fresh install: run the current installer schema path exactly as Bonumark
 *    does today, including the current cumulative 0001 baseline and all bundled
 *    migrations through the idempotent migration helper.
 * 2. Supported upgrade: start from the historical v0.4.x public baseline and
 *    replay only the migrations that follow that baseline while preserving
 *    representative owner, profile, post, media, comment, Like, and setting
 *    records.
 *
 * Keeping these paths separate prevents a current cumulative fresh-install
 * schema from being mistaken for an old install and then having historical DDL
 * blindly applied on top of columns/indexes that already exist.
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

if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run. Set BMS_DB_DANGER_RESET=1 to confirm this test may create and drop temporary bms_ci_* tables.\n");
    exit(1);
}

$host = (string)getenv('BMS_DB_HOST');
$dbName = (string)getenv('BMS_DB_NAME');
$user = (string)getenv('BMS_DB_USER');
$pass = getenv('BMS_DB_PASS');
$pass = $pass === false ? '' : (string)$pass;
$charset = (string)(getenv('BMS_DB_CHARSET') ?: 'utf8mb4');

if ($host === '' || $dbName === '' || $user === '') {
    fwrite(STDERR, "BMS_DB_HOST, BMS_DB_NAME, and BMS_DB_USER are required.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/_bonumark_stream/app/database.php';

$migrationDir = $root . '/_bonumark_stream/migrations';
$migrationFiles = glob($migrationDir . '/*.php') ?: [];
sort($migrationFiles);
$migrationNames = array_values(array_map(
    static fn(string $file): string => basename($file, '.php'),
    $migrationFiles
));
if ($migrationNames === [] || $migrationNames[0] !== '0001_initial_schema') {
    fwrite(STDERR, "Expected bundled migrations beginning with 0001_initial_schema.\n");
    exit(1);
}

$pdo = new PDO("mysql:host={$host};dbname={$dbName};charset={$charset}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$databaseInfo = bms_database_require_supported($pdo);
fwrite(STDOUT, 'Database target: ' . (string)($databaseInfo['display'] ?? 'unknown') . ' (compatibility floor ' . (string)($databaseInfo['minimum'] ?? 'unknown') . "+)\n");

$freshPrefix = 'bms_ci_fresh_' . strtolower(bin2hex(random_bytes(4))) . '_';
$upgradePrefix = 'bms_ci_upgrade_' . strtolower(bin2hex(random_bytes(4))) . '_';

try {
    bms_install_schema($pdo, $freshPrefix);
    bms_database_smoke_verify_schema($pdo, $freshPrefix, $migrationNames, 'fresh install');
    bms_database_smoke_verify_activitypub_default_off($pdo, $freshPrefix, 'fresh install');
    fwrite(STDOUT, "Fresh-install schema smoke test passed with prefix {$freshPrefix}.\n");

    $fixturePath = $root . '/scripts/fixtures/v0.4.x-initial-schema.php';
    if (!is_file($fixturePath)) {
        throw new RuntimeException('Historical v0.4.x database smoke fixture is missing.');
    }
    $baselineStatements = require $fixturePath;
    if (!is_array($baselineStatements) || $baselineStatements === []) {
        throw new RuntimeException('Historical v0.4.x database smoke fixture did not return a statement list.');
    }
    foreach ($baselineStatements as $index => $statement) {
        if (!is_int($index) || !is_string($statement) || trim($statement) === '') {
            throw new RuntimeException('Historical v0.4.x database smoke fixture must contain a numeric list of SQL statement strings.');
        }
        bms_exec_migration_statement($pdo, $statement, $upgradePrefix);
    }
    bms_database_smoke_seed_owner_data($pdo, $upgradePrefix);

    $ledger = $pdo->prepare("INSERT INTO `{$upgradePrefix}migrations` (`migration`, `ran_at`) VALUES (:migration, NOW()) ON DUPLICATE KEY UPDATE ran_at = ran_at");
    $ledger->execute(['migration' => '0001_initial_schema']);

    foreach ($migrationFiles as $file) {
        $migration = basename($file, '.php');
        if ($migration === '0001_initial_schema') {
            continue;
        }
        if ($migration === '0024_activitypub_publication_generations') {
            bms_database_smoke_seed_legacy_generation_reuse($pdo, $upgradePrefix);
        }
        $statements = require $file;
        if (!is_array($statements)) {
            throw new RuntimeException("Migration did not return an array: {$migration}");
        }
        foreach ($statements as $index => $statement) {
            if (!is_int($index) || !is_string($statement) || trim($statement) === '') {
                throw new RuntimeException("Migration must return a numeric list of SQL statement strings: {$migration}");
            }
            bms_exec_migration_statement($pdo, $statement, $upgradePrefix);
        }
        $ledger->execute(['migration' => $migration]);
    }

    bms_database_smoke_verify_schema($pdo, $upgradePrefix, $migrationNames, 'v0.4.x upgrade');
    bms_database_smoke_verify_owner_data($pdo, $upgradePrefix);
    bms_database_smoke_verify_legacy_generation_repair($pdo, $upgradePrefix);
    bms_database_smoke_verify_activitypub_enabled_state($pdo, $upgradePrefix);
    fwrite(STDOUT, "Supported-upgrade schema smoke test passed with prefix {$upgradePrefix}. Migrations verified: " . count($migrationNames) . "\n");
} finally {
    bms_database_smoke_drop_tables($pdo, $freshPrefix);
    bms_database_smoke_drop_tables($pdo, $upgradePrefix);
}

function bms_database_smoke_seed_owner_data(PDO $pdo, string $prefix): void
{
    $timestamp = '2026-07-01 12:00:00';
    $user = $pdo->prepare("INSERT INTO `{$prefix}users` (`id`, `username`, `display_name`, `email`, `bio`, `website`, `social_links`, `password_hash`, `created_at`, `updated_at`) VALUES (700, 'owner', 'Upgrade Owner', 'owner@example.test', 'Owner biography survives upgrade.', 'https://owner.example', :social_links, :password_hash, :created_at, :updated_at)");
    $user->execute([
        'social_links' => json_encode([['label' => 'Owner site', 'url' => 'https://owner.example']], JSON_UNESCAPED_SLASHES),
        'password_hash' => password_hash('not-a-real-password', PASSWORD_DEFAULT),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    $setting = $pdo->prepare("INSERT INTO `{$prefix}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('upgrade_owner_marker', 'preserve-me', :updated_at)");
    $setting->execute(['updated_at' => $timestamp]);

    $post = $pdo->prepare("INSERT INTO `{$prefix}posts` (`id`, `author_id`, `title`, `slug`, `status`, `post_type`, `description`, `content_body`, `category`, `category_slug`, `date_published`, `created_at`, `updated_at`, `published_at`) VALUES (701, 700, 'Upgrade preservation post', 'upgrade-preservation-post', 'published', 'stream', 'Historical owner post.', 'Owner publication survives upgrade.', 'Stream', 'stream', '2026-07-01', :created_at, :updated_at, :published_at)");
    $post->execute(['created_at' => $timestamp, 'updated_at' => $timestamp, 'published_at' => $timestamp]);

    $media = $pdo->prepare("INSERT INTO `{$prefix}media` (`id`, `filename`, `original_filename`, `public_path`, `mime_type`, `file_size`, `alt_text`, `caption`, `uploaded_by`, `file_hash`, `created_at`, `updated_at`) VALUES (702, 'upgrade-preservation.jpg', 'Upgrade Preservation.jpg', 'media/upgrade-preservation.jpg', 'image/jpeg', 1234, 'Historical owner media', 'Owner media survives upgrade.', 700, :file_hash, :created_at, :updated_at)");
    $media->execute(['file_hash' => hash('sha256', 'upgrade-preservation-media'), 'created_at' => $timestamp, 'updated_at' => $timestamp]);

    $comment = $pdo->prepare("INSERT INTO `{$prefix}comments` (`id`, `post_slug`, `post_id`, `user_id`, `body`, `status`, `created_at`, `updated_at`, `approved_at`) VALUES (703, 'upgrade-preservation-post', 701, 700, 'Owner comment survives upgrade.', 'approved', :created_at, :updated_at, :approved_at)");
    $comment->execute(['created_at' => $timestamp, 'updated_at' => $timestamp, 'approved_at' => $timestamp]);

    $like = $pdo->prepare("INSERT INTO `{$prefix}stream_likes` (`id`, `post_id`, `post_slug`, `visitor_hash`, `created_at`) VALUES (704, 701, 'upgrade-preservation-post', :visitor_hash, :created_at)");
    $like->execute(['visitor_hash' => hash('sha256', 'upgrade-preservation-like'), 'created_at' => $timestamp]);
}

function bms_database_smoke_verify_owner_data(PDO $pdo, string $prefix): void
{
    $owner = $pdo->query("SELECT `display_name`, `bio`, `website`, `social_links` FROM `{$prefix}users` WHERE `id` = 700")->fetch();
    $profile = $pdo->query("SELECT `links_json` FROM `{$prefix}user_profiles` WHERE `user_id` = 700")->fetch();
    $post = $pdo->query("SELECT `author_id`, `title`, `content_body`, `status` FROM `{$prefix}posts` WHERE `id` = 701")->fetch();
    $media = $pdo->query("SELECT `uploaded_by`, `public_path`, `alt_text`, `caption` FROM `{$prefix}media` WHERE `id` = 702")->fetch();
    $comment = $pdo->query("SELECT `post_id`, `user_id`, `body`, `status` FROM `{$prefix}comments` WHERE `id` = 703")->fetch();
    $like = $pdo->query("SELECT `post_id`, `post_slug`, `visitor_hash` FROM `{$prefix}stream_likes` WHERE `id` = 704")->fetch();
    $setting = $pdo->query("SELECT `setting_value` FROM `{$prefix}settings` WHERE `setting_key` = 'upgrade_owner_marker'")->fetchColumn();

    if (!is_array($owner)
        || (string)$owner['display_name'] !== 'Upgrade Owner'
        || (string)$owner['bio'] !== 'Owner biography survives upgrade.'
        || (string)$owner['website'] !== 'https://owner.example'
        || !is_array(json_decode((string)$owner['social_links'], true))
        || !is_array($profile)
        || json_decode((string)$profile['links_json'], true) !== json_decode((string)$owner['social_links'], true)
        || !is_array($post)
        || (int)$post['author_id'] !== 700
        || (string)$post['title'] !== 'Upgrade preservation post'
        || (string)$post['content_body'] !== 'Owner publication survives upgrade.'
        || (string)$post['status'] !== 'published'
        || !is_array($media)
        || (int)$media['uploaded_by'] !== 700
        || (string)$media['public_path'] !== 'media/upgrade-preservation.jpg'
        || (string)$media['alt_text'] !== 'Historical owner media'
        || (string)$media['caption'] !== 'Owner media survives upgrade.'
        || !is_array($comment)
        || (int)$comment['post_id'] !== 701
        || (int)$comment['user_id'] !== 700
        || (string)$comment['body'] !== 'Owner comment survives upgrade.'
        || (string)$comment['status'] !== 'approved'
        || !is_array($like)
        || (int)$like['post_id'] !== 701
        || (string)$like['post_slug'] !== 'upgrade-preservation-post'
        || (string)$like['visitor_hash'] !== hash('sha256', 'upgrade-preservation-like')
        || (string)$setting !== 'preserve-me') {
        throw new RuntimeException('The supported upgrade did not preserve representative owner, profile, post, media, comment, like, or setting data.');
    }
}

function bms_database_smoke_verify_activitypub_default_off(PDO $pdo, string $prefix, string $label): void
{
    $setting = $pdo->query("SELECT `setting_value` FROM `{$prefix}settings` WHERE `setting_key` = 'activitypub_enabled'")->fetchColumn();
    if ((string)$setting === '1') {
        throw new RuntimeException("{$label} unexpectedly enabled ActivityPub.");
    }
}

function bms_database_smoke_verify_activitypub_enabled_state(PDO $pdo, string $prefix): void
{
    $setting = $pdo->query("SELECT `setting_value` FROM `{$prefix}settings` WHERE `setting_key` = 'activitypub_enabled'")->fetchColumn();
    if ((string)$setting !== '1') {
        throw new RuntimeException('The supported upgrade did not preserve a pre-existing enabled ActivityPub setting through later migrations.');
    }
}

function bms_database_smoke_seed_legacy_generation_reuse(PDO $pdo, string $prefix): void
{
    $activityPubSetting = $pdo->prepare("INSERT INTO `{$prefix}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('activitypub_enabled', '1', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = VALUES(`updated_at`)");
    $activityPubSetting->execute();

    $objectUri = 'https://example.test/activitypub/objects/900';
    $object = ['id' => $objectUri, 'type' => 'Note', 'content' => '<p>Legacy generation reuse fixture.</p>'];
    $objectJson = json_encode($object, JSON_UNESCAPED_SLASHES);
    $local = $pdo->prepare("INSERT INTO `{$prefix}activitypub_local_objects` (`post_id`, `object_uri`, `object_type`, `content_hash`, `last_object_json`, `last_human_url`, `publication_generation`, `transition_sequence`, `published_at`, `updated_at`, `deleted_at`, `created_at`) VALUES (900, :object_uri, 'Note', :content_hash, :object_json, 'https://example.test/stream/legacy-generation/', 2, 3, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP())");
    $local->execute(['object_uri' => $objectUri, 'content_hash' => hash('sha256', 'legacy-generation'), 'object_json' => $objectJson]);

    $event = $pdo->prepare("INSERT INTO `{$prefix}activitypub_publication_events` (`post_id`, `event_type`, `activity_uri`, `source`, `content_hash`, `state_json`, `payload_json`, `transition_fingerprint`, `status`, `created_at`, `processed_at`) VALUES (900, :event_type, :activity_uri, 'migration_fixture', :content_hash, '{}', :payload_json, :fingerprint, 'completed', :created_at, :processed_at)");
    $eventIds = [];
    foreach ([
        [1, 'published', 'Create'],
        [2, 'unpublished', 'Delete'],
        [3, 'published', 'Create'],
    ] as [$sequence, $eventType, $activityType]) {
        $payload = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => 'https://example.test/activitypub/activities/events/' . $sequence,
            'type' => $activityType,
            'actor' => 'https://example.test/activitypub/actor',
            'object' => $activityType === 'Delete'
                ? ['id' => $objectUri, 'type' => 'Tombstone', 'formerType' => 'Note']
                : $object,
        ];
        $eventTime = sprintf('2026-08-29 00:%02d:00', $sequence);
        $event->execute([
            'event_type' => $eventType,
            'activity_uri' => (string)$payload['id'],
            'content_hash' => hash('sha256', 'legacy-generation-' . $sequence),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'fingerprint' => hash('sha256', 'legacy-generation-event-' . $sequence),
            'created_at' => $eventTime,
            'processed_at' => $eventTime,
        ]);
        $eventIds[] = (int)$pdo->lastInsertId();
    }

    $delivery = $pdo->prepare("INSERT INTO `{$prefix}activitypub_deliveries` (`delivery_type`, `event_id`, `activity_uri`, `payload_json`, `dedupe_key`, `inbox_url`, `recipient_actor_ids_json`, `signature_mode`, `status`, `attempt_count`, `available_at`, `last_attempt_at`, `delivered_at`, `http_status`, `last_error`, `created_at`, `updated_at`) SELECT 'publication', `id`, `activity_uri`, `payload_json`, :dedupe_key, 'https://remote.example/inbox', '[]', 'legacy', 'pending', 0, UTC_TIMESTAMP(), NULL, NULL, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM `{$prefix}activitypub_publication_events` WHERE `id` = :event_id");
    $delivery->execute(['dedupe_key' => hash('sha256', 'legacy-generation-delivery'), 'event_id' => $eventIds[2]]);
}

function bms_database_smoke_verify_legacy_generation_repair(PDO $pdo, string $prefix): void
{
    $local = $pdo->query("SELECT `publication_generation`, `deleted_at` FROM `{$prefix}activitypub_local_objects` WHERE `post_id` = 900")->fetch();
    $generations = $pdo->query("SELECT `publication_generation` FROM `{$prefix}activitypub_publication_events` WHERE `post_id` = 900 ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
    $delivery = $pdo->query("SELECT `publication_generation`, `object_uri`, `status`, `last_error` FROM `{$prefix}activitypub_deliveries` WHERE `event_id` IN (SELECT `id` FROM `{$prefix}activitypub_publication_events` WHERE `post_id` = 900) LIMIT 1")->fetch();
    if (!is_array($local) || (int)$local['publication_generation'] !== 1 || trim((string)$local['deleted_at']) === ''
        || array_map('intval', $generations) !== [1, 1, 2]
        || !is_array($delivery) || (int)$delivery['publication_generation'] !== 2
        || (string)$delivery['object_uri'] !== 'https://example.test/activitypub/objects/900'
        || (string)$delivery['status'] !== 'retired'
        || !str_contains((string)$delivery['last_error'], 'already been deleted')) {
        throw new RuntimeException('The ActivityPub generation migration did not retire a reused legacy object while preserving immutable event and delivery history.');
    }
}

/**
 * @param list<string> $expectedMigrations
 */
function bms_database_smoke_verify_schema(PDO $pdo, string $prefix, array $expectedMigrations, string $label): void
{
    $migrationRows = $pdo->query("SELECT `migration` FROM `{$prefix}migrations` ORDER BY `migration`");
    $actualMigrations = $migrationRows ? array_values($migrationRows->fetchAll(PDO::FETCH_COLUMN)) : [];
    $expected = $expectedMigrations;
    sort($expected);
    if ($actualMigrations !== $expected) {
        throw new RuntimeException("{$label} migration ledger does not match the bundled migration set.");
    }

    $requiredTables = [
        'users',
        'settings',
        'posts',
        'migrations',
        'media',
        'comments',
        'upgrade_history',
        'api_tokens',
        'api_audit_log',
        'api_rate_limit_attempts',
        'api_idempotency_keys',
        'remember_tokens',
        'scheduled_task_runs',
        'analytics_daily',
        'places',
        'user_profiles',
        'activitypub_keys',
        'activitypub_local_objects',
        'activitypub_publication_events',
        'activitypub_deliveries',
        'activitypub_remote_actors',
        'activitypub_inbox_receipts',
        'activitypub_signature_replays',
        'activitypub_followers',
        'activitypub_following',
        'activitypub_blocks',
        'activitypub_remote_replies',
        'activitypub_remote_interactions',
        'activitypub_interaction_log',
        'activitypub_local_actor_lifecycle',
    ];
    foreach ($requiredTables as $table) {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($prefix . $table));
        if ($stmt === false || !$stmt->fetchColumn()) {
            throw new RuntimeException("{$label} expected table was not created: {$prefix}{$table}");
        }
    }

    $requiredColumns = [
        'posts' => ['scheduled_at', 'is_pinned', 'pinned_at'],
        'trash' => ['post_id', 'original_status', 'content_body', 'content_front_matter'],
        'media' => ['privacy_status', 'privacy_note', 'privacy_checked_at'],
        'user_profiles' => ['featured_items_json', 'profile_photos_json'],
        'activitypub_keys' => ['key_token', 'public_key_pem', 'private_key_encrypted', 'status'],
        'activitypub_local_objects' => ['post_id', 'object_uri', 'content_hash', 'last_object_json', 'publication_generation', 'transition_sequence', 'deleted_at'],
        'activitypub_publication_events' => ['post_id', 'publication_generation', 'object_uri', 'event_type', 'activity_uri', 'state_json', 'payload_json', 'transition_fingerprint', 'status'],
        'activitypub_deliveries' => ['delivery_type', 'event_id', 'publication_generation', 'object_uri', 'activity_uri', 'payload_json', 'dedupe_key', 'inbox_url', 'recipient_actor_ids_json', 'signature_mode', 'attempt_count', 'available_at'],
        'activitypub_remote_actors' => ['actor_uri', 'lifecycle_state', 'inbox_url', 'public_key_id', 'public_key_pem', 'expires_at', 'last_fetch_status', 'last_fetch_error', 'failure_count', 'last_failed_at', 'deleted_at'],
        'activitypub_inbox_receipts' => ['activity_uri', 'activity_type', 'actor_uri', 'body_hash', 'status'],
        'activitypub_signature_replays' => ['fingerprint', 'key_id', 'expires_at'],
        'activitypub_followers' => ['actor_uri', 'follow_activity_uri', 'follow_receipt_id', 'state'],
        'activitypub_following' => ['actor_uri', 'follow_activity_uri', 'state'],
        'activitypub_blocks' => ['block_type', 'block_value', 'reason'],
        'activitypub_remote_replies' => ['remote_actor_id', 'remote_object_uri', 'target_post_id', 'target_publication_generation', 'target_object_uri', 'content_html', 'moderation_state', 'lifecycle_state', 'deleted_at'],
        'activitypub_remote_interactions' => ['semantic_key', 'interaction_type', 'remote_actor_id', 'target_publication_generation', 'target_object_uri', 'current_activity_uri', 'state'],
        'activitypub_interaction_log' => ['interaction_id', 'activity_uri', 'activity_type', 'receipt_id', 'state', 'undo_activity_uri'],
        'activitypub_local_actor_lifecycle' => ['actor_uri', 'lifecycle_state', 'delete_activity_uri', 'delete_payload_json', 'retired_at', 'delivery_completed_at'],
    ];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM `{$prefix}{$table}` LIKE " . $pdo->quote($column));
            if ($columnStmt === false || !$columnStmt->fetch()) {
                throw new RuntimeException("{$label} expected column was not created: {$table}.{$column}");
            }
        }
    }

    $publicationEventStatus = $pdo->query("SHOW COLUMNS FROM `{$prefix}activitypub_publication_events` LIKE 'status'");
    $publicationEventStatus = $publicationEventStatus ? $publicationEventStatus->fetch() : false;
    if (!is_array($publicationEventStatus) || (string)($publicationEventStatus['Default'] ?? '') !== 'observed') {
        throw new RuntimeException("{$label} ActivityPub publication observations must default to the completed observed state.");
    }

    $deliveryEventId = $pdo->query("SHOW COLUMNS FROM `{$prefix}activitypub_deliveries` LIKE 'event_id'");
    $deliveryEventId = $deliveryEventId ? $deliveryEventId->fetch() : false;
    if (!is_array($deliveryEventId) || (string)($deliveryEventId['Null'] ?? '') !== 'YES') {
        throw new RuntimeException("{$label} follower response deliveries must remain structurally independent of publication events.");
    }

    foreach ([
        'status_scheduled_at',
        'post_type_status_pinned_at',
    ] as $index) {
        $indexStmt = $pdo->query("SHOW INDEX FROM `{$prefix}posts` WHERE Key_name = " . $pdo->quote($index));
        if ($indexStmt === false || !$indexStmt->fetch()) {
            throw new RuntimeException("{$label} expected posts index was not created: {$index}");
        }
    }

    $generationIndex = $pdo->query("SHOW INDEX FROM `{$prefix}activitypub_local_objects` WHERE Key_name = 'post_generation'");
    if ($generationIndex === false || !$generationIndex->fetch()) {
        throw new RuntimeException("{$label} ActivityPub publication generations are not uniquely indexed by post and generation.");
    }
}

function bms_database_smoke_drop_tables(PDO $pdo, string $prefix): void
{
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($prefix . '%'));
    $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    foreach ($tables as $table) {
        if (is_string($table) && str_starts_with($table, $prefix)) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
    }
}
