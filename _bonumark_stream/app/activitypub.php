<?php

require_once __DIR__ . '/activitypub-security.php';
require_once __DIR__ . '/activitypub-serialization.php';
require_once __DIR__ . '/activitypub-delivery.php';
require_once __DIR__ . '/activitypub-interactions.php';
require_once __DIR__ . '/activitypub-owner.php';

/**
 * ActivityPub foundation.
 *
 * These helpers provide the default-off capability boundary, encrypted
 * signing-key storage, and durable local federation state. Remote publication
 * delivery remains isolated in the scheduled worker.
 */

function bms_activitypub_enabled(): bool
{
    return (string)bms_setting_or_config('activitypub_enabled', '0') === '1';
}

function bms_activitypub_operational_state(): string
{
    if (!bms_activitypub_enabled()) {
        return (string)bms_setting_or_config('activitypub_deactivated', '0') === '1' ? 'deactivated' : 'disabled';
    }
    return (string)bms_setting_or_config('activitypub_paused', '0') === '1' ? 'paused' : 'active';
}

function bms_activitypub_delivery_suspended(): bool
{
    return (string)bms_setting_or_config('activitypub_delivery_suspended', '0') === '1';
}

function bms_activitypub_accepts_inbox(): bool
{
    return bms_activitypub_enabled() && bms_activitypub_operational_state() === 'active';
}

function bms_activitypub_records_publications(): bool
{
    return bms_activitypub_enabled() && bms_activitypub_operational_state() === 'active';
}

function bms_activitypub_runs_deliveries(): bool
{
    return bms_activitypub_enabled()
        && bms_activitypub_operational_state() === 'active'
        && !bms_activitypub_delivery_suspended();
}

function bms_activitypub_has_federation_history(): bool
{
    foreach (['activitypub_publication_events', 'activitypub_followers', 'activitypub_following', 'activitypub_inbox_receipts'] as $table) {
        $stmt = bms_db()->query('SELECT 1 FROM ' . bms_table($table) . ' LIMIT 1');
        if ($stmt && $stmt->fetchColumn() !== false) {
            return true;
        }
    }
    return false;
}

function bms_activitypub_configured_base_url(?string $baseUrl = null): array
{
    $baseUrl = trim($baseUrl ?? (string)bms_setting_or_config('base_url', ''));
    if ($baseUrl === '') {
        return ['ok' => false, 'message' => 'A canonical base URL must be configured before ActivityPub can be enabled.'];
    }
    $parts = parse_url($baseUrl);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        return ['ok' => false, 'message' => 'ActivityPub requires an absolute canonical HTTPS URL without credentials, a query string, or a fragment.'];
    }
    return ['ok' => true, 'message' => 'The configured canonical site URL is suitable for ActivityPub identity.'];
}

function bms_activitypub_webfinger_routing_capability(?string $baseUrl = null, ?string $basePath = null): array
{
    $url = bms_activitypub_configured_base_url($baseUrl);
    if (empty($url['ok'])) {
        return $url;
    }
    $basePath = trim($basePath ?? (string)bms_setting_or_config('base_path', ''));
    $urlPath = trim((string)(parse_url(trim($baseUrl ?? (string)bms_setting_or_config('base_url', '')), PHP_URL_PATH) ?? ''), '/');
    if (trim($basePath, '/') !== '' || $urlPath !== '') {
        return [
            'ok' => false,
            'message' => 'This installation uses a subdirectory. WebFinger must still be mapped at the domain root before ActivityPub can be enabled.',
        ];
    }
    return ['ok' => true, 'message' => 'The installation can serve WebFinger discovery from the domain root.'];
}

function bms_activitypub_scheduled_runner_capability(): array
{
    if (!function_exists('bms_scheduled_tasks_status')) {
        return ['ok' => false, 'message' => 'The scheduled-task runner is unavailable.'];
    }
    $status = bms_scheduled_tasks_status();
    $lastSource = (string)($status['last_source'] ?? '');
    $lastStatus = (string)($status['last_status'] ?? '');
    $durableSource = in_array($lastSource, ['server_cron', 'web_cron'], true);
    $expectedMinutes = max(1, (int)($status['expected_interval_minutes'] ?? 5));
    $thresholdSeconds = max(900, $expectedMinutes * 60 * 3);
    $history = function_exists('bms_scheduled_tasks_history') ? bms_scheduled_tasks_history(100) : [];
    $recentDurableHistory = bms_activitypub_recent_durable_task_history($history, $thresholdSeconds);
    if (!empty($status['web_cron_enabled'])
        || (($status['status'] ?? '') === 'healthy' && $durableSource && $lastStatus === 'completed')
        || $recentDurableHistory) {
        return ['ok' => true, 'message' => 'A dependable scheduled-task runner is configured or has run recently.'];
    }
    return ['ok' => false, 'message' => 'Configure server cron or authenticated web cron before enabling federation delivery. Browser and public-traffic fallbacks are not dependable delivery workers.'];
}

function bms_activitypub_recent_durable_task_history(array $history, int $thresholdSeconds, ?int $now = null): bool
{
    $now = $now ?? time();
    $thresholdSeconds = max(1, $thresholdSeconds);
    foreach ($history as $run) {
        if (!is_array($run)
            || !in_array((string)($run['source'] ?? ''), ['server_cron', 'web_cron'], true)
            || (string)($run['status'] ?? '') !== 'completed') {
            continue;
        }
        $completedAt = trim((string)($run['completed_at'] ?? ''));
        if ($completedAt === '') {
            continue;
        }
        try {
            $completed = new DateTimeImmutable($completedAt, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            continue;
        }
        $timestamp = $completed->getTimestamp();
        if ($timestamp <= $now + 300 && max(0, $now - $timestamp) <= $thresholdSeconds) {
            return true;
        }
    }
    return false;
}

function bms_activitypub_system_check_items(): array
{
    if (!bms_activitypub_enabled()) {
        return [[
            'label' => 'ActivityPub',
            'status' => 'pass',
            'message' => 'ActivityPub is disabled. Normal Bonumark publishing is unaffected.',
        ]];
    }

    $url = bms_activitypub_configured_base_url();
    $routing = bms_activitypub_webfinger_routing_capability();
    $openssl = function_exists('openssl_pkey_new')
        && function_exists('openssl_pkey_export')
        && function_exists('openssl_encrypt')
        && function_exists('openssl_decrypt');
    $salt = trim((string)(bms_config()['security_salt'] ?? ''));
    $runner = bms_activitypub_scheduled_runner_capability();
    try {
        $owner = bms_activitypub_public_owner_user();
    } catch (Throwable $e) {
        $owner = null;
    }
    $keyHealth = bms_activitypub_signing_key_health();
    $operationalState = bms_activitypub_operational_state();
    $deliverySuspended = bms_activitypub_delivery_suspended();

    return [
        ['label' => 'ActivityPub canonical URL', 'status' => !empty($url['ok']) ? 'pass' : 'fail', 'message' => (string)$url['message']],
        ['label' => 'ActivityPub operational state', 'status' => $operationalState === 'active' && !$deliverySuspended ? 'pass' : 'warn', 'message' => $operationalState === 'paused' ? 'Federation is paused. Identity remains discoverable, but inbox processing, publication recording, and delivery are stopped.' : ($deliverySuspended ? 'Outbound federation delivery is suspended. Discovery, inbox processing, and durable local activity recording continue.' : 'Federation is active.')],
        ['label' => 'ActivityPub WebFinger routing', 'status' => !empty($routing['ok']) ? 'pass' : 'fail', 'message' => (string)$routing['message']],
        ['label' => 'ActivityPub owner identity', 'status' => is_array($owner) ? 'pass' : 'fail', 'message' => is_array($owner) ? 'The public Admin profile is available as the stable site actor.' : 'ActivityPub requires an active Admin account with a public Profile.'],
        ['label' => 'ActivityPub cryptography', 'status' => $openssl && strlen($salt) >= 32 ? 'pass' : 'fail', 'message' => $openssl && strlen($salt) >= 32 ? 'OpenSSL and the installation secret are available for protected signing keys.' : 'ActivityPub requires OpenSSL and a high-entropy installation security salt.'],
        ['label' => 'ActivityPub signing identity', 'status' => !empty($keyHealth['ok']) ? 'pass' : 'warn', 'message' => (string)$keyHealth['message']],
        ['label' => 'ActivityPub outbound HTTP', 'status' => function_exists('curl_init') ? 'pass' : 'fail', 'message' => function_exists('curl_init') ? 'PHP cURL is available for signed federation requests.' : 'ActivityPub requires PHP cURL for outbound federation requests.'],
        ['label' => 'ActivityPub scheduled delivery', 'status' => !empty($runner['ok']) ? 'pass' : 'warn', 'message' => (string)$runner['message']],
    ];
}

function bms_activitypub_key_encryption_key(): string
{
    $salt = trim((string)(bms_config()['security_salt'] ?? ''));
    if (strlen($salt) < 32) {
        throw new RuntimeException('A high-entropy installation security salt is required to protect ActivityPub signing keys.');
    }
    return hash_hkdf('sha256', $salt, 32, 'bonumark-stream-activitypub-private-key-v1');
}

function bms_activitypub_encrypt_private_key_with_key(string $privateKey, string $encryptionKey): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL encryption is unavailable.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $aad = 'bonumark-stream-activitypub-key-v1';
    if (strlen($encryptionKey) !== 32) {
        throw new InvalidArgumentException('ActivityPub private-key encryption requires a 32-byte key.');
    }
    $ciphertext = openssl_encrypt($privateKey, 'aes-256-gcm', $encryptionKey, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('The ActivityPub private key could not be encrypted.');
    }
    $payload = json_encode([
        'version' => 1,
        'cipher' => 'aes-256-gcm',
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('The encrypted ActivityPub private key could not be encoded.');
    }
    return $payload;
}

function bms_activitypub_encrypt_private_key(string $privateKey): string
{
    return bms_activitypub_encrypt_private_key_with_key($privateKey, bms_activitypub_key_encryption_key());
}

function bms_activitypub_decrypt_private_key_with_key(string $payload, string $encryptionKey): string
{
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL decryption is unavailable.');
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded) || (int)($decoded['version'] ?? 0) !== 1 || (string)($decoded['cipher'] ?? '') !== 'aes-256-gcm') {
        throw new RuntimeException('The encrypted ActivityPub private key format is invalid.');
    }
    $iv = base64_decode((string)($decoded['iv'] ?? ''), true);
    $tag = base64_decode((string)($decoded['tag'] ?? ''), true);
    $ciphertext = base64_decode((string)($decoded['ciphertext'] ?? ''), true);
    if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
        throw new RuntimeException('The encrypted ActivityPub private key payload is invalid.');
    }
    if (strlen($encryptionKey) !== 32) {
        throw new InvalidArgumentException('ActivityPub private-key decryption requires a 32-byte key.');
    }
    $privateKey = openssl_decrypt($ciphertext, 'aes-256-gcm', $encryptionKey, OPENSSL_RAW_DATA, $iv, $tag, 'bonumark-stream-activitypub-key-v1');
    if (!is_string($privateKey) || $privateKey === '') {
        throw new RuntimeException('The ActivityPub private key could not be decrypted.');
    }
    return $privateKey;
}

function bms_activitypub_decrypt_private_key(string $payload): string
{
    return bms_activitypub_decrypt_private_key_with_key($payload, bms_activitypub_key_encryption_key());
}

function bms_activitypub_generate_signing_key(): array
{
    if (!function_exists('openssl_pkey_new') || !function_exists('openssl_pkey_export')) {
        throw new RuntimeException('OpenSSL key generation is unavailable.');
    }
    $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    if ($resource === false) {
        throw new RuntimeException('The ActivityPub signing key could not be generated.');
    }
    $privateKey = '';
    if (!openssl_pkey_export($resource, $privateKey)) {
        throw new RuntimeException('The ActivityPub private key could not be exported.');
    }
    $details = openssl_pkey_get_details($resource);
    $publicKey = is_array($details) ? trim((string)($details['key'] ?? '')) : '';
    if ($publicKey === '') {
        throw new RuntimeException('The ActivityPub public key could not be exported.');
    }
    $probe = random_bytes(32);
    $signature = '';
    if (!openssl_sign($probe, $signature, $privateKey, OPENSSL_ALGO_SHA256)
        || openssl_verify($probe, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('The generated ActivityPub signing key did not pass its cryptographic self-test.');
    }
    return ['algorithm' => 'rsa-sha256', 'public_key_pem' => $publicKey, 'private_key_pem' => $privateKey];
}

function bms_activitypub_create_signing_key(): array
{
    $key = bms_activitypub_generate_signing_key();
    $token = bin2hex(random_bytes(16));
    $pdo = bms_db();
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE " . bms_table('activitypub_keys') . " SET status = 'retired', retired_at = UTC_TIMESTAMP() WHERE status = 'active'");
        $stmt = $pdo->prepare('INSERT INTO ' . bms_table('activitypub_keys') . ' (key_token, algorithm, public_key_pem, private_key_encrypted, status, created_at, retired_at) VALUES (:key_token, :algorithm, :public_key_pem, :private_key_encrypted, :status, UTC_TIMESTAMP(), NULL)');
        $stmt->execute([
            'key_token' => $token,
            'algorithm' => (string)$key['algorithm'],
            'public_key_pem' => (string)$key['public_key_pem'],
            'private_key_encrypted' => bms_activitypub_encrypt_private_key((string)$key['private_key_pem']),
            'status' => 'active',
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['id' => $id, 'key_token' => $token, 'algorithm' => (string)$key['algorithm'], 'public_key_pem' => (string)$key['public_key_pem'], 'status' => 'active'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_activitypub_active_signing_key(bool $includePrivate = false): ?array
{
    $stmt = bms_db()->query("SELECT * FROM " . bms_table('activitypub_keys') . " WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $key = $stmt ? $stmt->fetch() : false;
    if (!is_array($key)) {
        return null;
    }
    if ($includePrivate) {
        $key['private_key_pem'] = bms_activitypub_decrypt_private_key((string)($key['private_key_encrypted'] ?? ''));
    }
    unset($key['private_key_encrypted']);
    return $key;
}

function bms_activitypub_signing_key_health(): array
{
    try {
        $key = bms_activitypub_active_signing_key(true);
        if (!is_array($key)) {
            return ['ok' => false, 'code' => 'missing', 'message' => 'No active ActivityPub signing key exists.'];
        }
        $privateKey = trim((string)($key['private_key_pem'] ?? ''));
        $publicKey = trim((string)($key['public_key_pem'] ?? ''));
        $probe = random_bytes(32);
        $signature = '';
        if ($privateKey === '' || $publicKey === ''
            || !openssl_sign($probe, $signature, $privateKey, OPENSSL_ALGO_SHA256)
            || openssl_verify($probe, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return ['ok' => false, 'code' => 'unusable', 'message' => 'The active ActivityPub signing key is unusable or its key pair does not match. Rotate it to recover delivery.'];
        }
        return ['ok' => true, 'code' => 'healthy', 'message' => 'The active ActivityPub signing key decrypted successfully and passed a sign/verify self-test.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => 'unusable', 'message' => 'The active ActivityPub signing key cannot be decrypted or used. Rotate it to recover delivery.'];
    }
}

function bms_activitypub_signing_key_rows(int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = bms_db()->query('SELECT id, key_token, algorithm, status, created_at, retired_at FROM ' . bms_table('activitypub_keys') . ' ORDER BY id DESC LIMIT ' . $limit);
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

function bms_activitypub_public_owner_user(): ?array
{
    if (!bms_is_installed()) {
        return null;
    }
    $stmt = bms_db()->query('SELECT id, username, display_name, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . bms_table('users') . " WHERE role = 'admin' AND status = 'active' AND profile_visibility <> 'private' ORDER BY id ASC LIMIT 1");
    $owner = $stmt ? $stmt->fetch() : false;
    return is_array($owner) ? $owner : null;
}

function bms_activitypub_published_stream_count(): int
{
    $stmt = bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('posts') . " p WHERE p.post_type = 'stream' AND p.status = 'published' AND NOT EXISTS (SELECT 1 FROM " . bms_table('activitypub_local_objects') . " lo WHERE lo.post_id = p.id AND lo.deleted_at IS NOT NULL AND lo.publication_generation = (SELECT MAX(current_lo.publication_generation) FROM " . bms_table('activitypub_local_objects') . ' current_lo WHERE current_lo.post_id = p.id))');
    return $stmt ? max(0, (int)$stmt->fetchColumn()) : 0;
}

function bms_activitypub_published_stream_posts(int $page = 1, int $perPage = 20): array
{
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;
    $stmt = bms_db()->query('SELECT p.* FROM ' . bms_table('posts') . " p WHERE p.post_type = 'stream' AND p.status = 'published' AND NOT EXISTS (SELECT 1 FROM " . bms_table('activitypub_local_objects') . " lo WHERE lo.post_id = p.id AND lo.deleted_at IS NOT NULL AND lo.publication_generation = (SELECT MAX(current_lo.publication_generation) FROM " . bms_table('activitypub_local_objects') . " current_lo WHERE current_lo.post_id = p.id)) ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC LIMIT {$perPage} OFFSET {$offset}");
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    $pages = [];
    foreach (array_filter($rows, 'is_array') as $row) {
        $page = bms_database_row_to_content_page($row);
        $postId = (int)($row['id'] ?? 0);
        $localObject = bms_activitypub_local_object($postId);
        if (is_array($localObject) && trim((string)($localObject['deleted_at'] ?? '')) === '') {
            $generation = max(1, (int)($localObject['publication_generation'] ?? 1));
            $page['activitypub_object_uri'] = (string)$localObject['object_uri'];
            $page['activitypub_publication_generation'] = $generation;
            $event = bms_db()->prepare("SELECT activity_uri FROM " . bms_table('activitypub_publication_events') . " WHERE post_id = :post_id AND publication_generation = :generation AND event_type = 'published' AND status <> 'observed' ORDER BY id ASC LIMIT 1");
            $event->execute(['post_id' => $postId, 'generation' => $generation]);
            $activityUri = trim((string)($event->fetchColumn() ?: ''));
            if ($activityUri !== '') {
                $page['activitypub_create_activity_uri'] = $activityUri;
            }
        }
        $pages[] = $page;
    }
    return $pages;
}

function bms_activitypub_find_published_stream_post(int $postId): ?array
{
    if ($postId < 1) {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE id = :id AND post_type = :post_type AND status = :status LIMIT 1');
    $stmt->execute([
        'id' => $postId,
        'post_type' => 'stream',
        'status' => 'published',
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? bms_database_row_to_content_page($row) : null;
}

function bms_activitypub_record_publication_transition(array $transition): void
{
    if (!bms_activitypub_records_publications() || (string)($transition['post_type'] ?? '') !== 'stream') {
        return;
    }
    bms_activitypub_record_actionable_publication_transition($transition);
}

bms_register_publication_transition_handler('activitypub', 'bms_activitypub_record_publication_transition');
