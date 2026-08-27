<?php

/**
 * ActivityPub foundation.
 *
 * Stage 1 exposes no protocol routes and performs no network activity. These
 * helpers provide a default-off capability boundary, encrypted signing-key
 * storage, and a durable record of completed local publication transitions.
 */

function bms_activitypub_enabled(): bool
{
    return (string)bms_setting_or_config('activitypub_enabled', '0') === '1';
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
    $durableSource = in_array($lastSource, ['server_cron', 'web_cron'], true);
    if (!empty($status['web_cron_enabled']) || (($status['status'] ?? '') === 'healthy' && $durableSource)) {
        return ['ok' => true, 'message' => 'A dependable scheduled-task runner is configured or has run recently.'];
    }
    return ['ok' => false, 'message' => 'Configure server cron or authenticated web cron before enabling federation delivery. Browser and public-traffic fallbacks are not dependable delivery workers.'];
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

    return [
        ['label' => 'ActivityPub canonical URL', 'status' => !empty($url['ok']) ? 'pass' : 'fail', 'message' => (string)$url['message']],
        ['label' => 'ActivityPub WebFinger routing', 'status' => !empty($routing['ok']) ? 'pass' : 'fail', 'message' => (string)$routing['message']],
        ['label' => 'ActivityPub cryptography', 'status' => $openssl && strlen($salt) >= 32 ? 'pass' : 'fail', 'message' => $openssl && strlen($salt) >= 32 ? 'OpenSSL and the installation secret are available for protected signing keys.' : 'ActivityPub requires OpenSSL and a high-entropy installation security salt.'],
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

function bms_activitypub_record_publication_transition(array $transition): void
{
    if (!bms_activitypub_enabled() || (string)($transition['post_type'] ?? '') !== 'stream') {
        return;
    }
    $state = json_encode(['before' => $transition['before'] ?? null, 'after' => $transition['after'] ?? null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_publication_events') . ' (post_id, event_type, source, content_hash, state_json, status, created_at, processed_at) VALUES (:post_id, :event_type, :source, :content_hash, :state_json, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $stmt->execute([
        'post_id' => (int)($transition['post_id'] ?? 0) > 0 ? (int)$transition['post_id'] : null,
        'event_type' => (string)($transition['event_type'] ?? ''),
        'source' => (string)($transition['source'] ?? 'application'),
        'content_hash' => (string)($transition['content_hash'] ?? ''),
        'state_json' => is_string($state) ? $state : '{}',
        // Stage 1 and Stage 2 only observe completed local publications. A
        // future delivery worker must never interpret these historical rows
        // as unsent federation work.
        'status' => 'observed',
    ]);
}

bms_register_publication_transition_handler('activitypub', 'bms_activitypub_record_publication_transition');
