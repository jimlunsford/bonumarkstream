<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

final class BMS_Connect_Error extends RuntimeException
{
    public function __construct(public readonly string $resultCode, public readonly int $httpStatus = 400)
    {
        parent::__construct($resultCode);
    }
}

function bms_connect_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 15) | 64);
    $b[8] = chr((ord($b[8]) & 63) | 128);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

function bms_connect_is_uuid(string $value): bool
{
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
}

function bms_connect_request_id(): string
{
    static $id;
    return $id ??= bms_connect_uuid();
}

function bms_connect_hash(string $purpose, string $value): string
{
    $salt = (string)(bms_config()['security_salt'] ?? '');
    if (strlen($salt) < 32) {
        throw new BMS_Connect_Error('connect_unavailable', 503);
    }
    return hash_hmac('sha256', 'bonumark-connect:v1:' . $purpose . "\0" . $value, $salt);
}

function bms_connect_query(string $sql, array $values = []): PDOStatement
{
    $stmt = bms_db()->prepare($sql);
    $stmt->execute($values);
    return $stmt;
}

// This lock is also held through future adapter mutations. A caller-owned outer
// transaction retains the lock. Revocation and scope reduction use the same lock.
function bms_connect_transaction(callable $work): mixed
{
    $pdo = bms_db();
    $outer = $pdo->inTransaction();
    $savepoint = 'bms_connect_' . bin2hex(random_bytes(6));
    if ($outer) { $pdo->exec('SAVEPOINT ' . $savepoint); } else { $pdo->beginTransaction(); }
    try {
        if (!bms_connect_query('SELECT id FROM ' . bms_table('connect_control') . ' WHERE id = 1 FOR UPDATE')->fetchColumn()) {
            throw new BMS_Connect_Error('connect_unavailable', 503);
        }
        $result = $work();
        if ($outer) { $pdo->exec('RELEASE SAVEPOINT ' . $savepoint); } else { $pdo->commit(); }
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            if ($outer) { $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint); }
            else { $pdo->rollBack(); }
        }
        throw $e;
    }
}

function bms_connect_owner(int $ownerId): array
{
    $owner = bms_connect_query('SELECT id, role, status FROM ' . bms_table('users') . ' WHERE id = ? FOR UPDATE', [$ownerId])->fetch();
    if (!$owner || $owner['role'] !== 'admin' || $owner['status'] !== 'active') {
        throw new BMS_Connect_Error('access_denied', 403);
    }
    return $owner;
}

function bms_connect_audit(string $operation, string $outcome = 'success', string $code = 'ok', ?string $grantId = null): void
{
    // Callers use fixed internal strings. No request body, URL, exception or secret.
    bms_connect_query('INSERT INTO ' . bms_table('connect_audit') . ' (request_id, grant_id, operation, outcome, result_code, network_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        bms_connect_request_id(), $grantId, $operation, $outcome, $code,
        bms_connect_hash('network', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')), time(),
    ]);
}

function bms_connect_scopes(mixed $scopes): array
{
    $allowed = ['status:read', 'stream:read', 'stream:draft', 'media:upload'];
    if (!is_array($scopes) || !array_is_list($scopes) || count($scopes) > 4) {
        throw new BMS_Connect_Error('invalid_scope');
    }
    foreach ($scopes as $scope) {
        if (!is_string($scope) || !in_array($scope, $allowed, true)) { throw new BMS_Connect_Error('invalid_scope'); }
    }
    $scopes = array_values(array_unique($scopes));
    sort($scopes, SORT_STRING);
    return $scopes;
}

// Strict URL profile shared by site bindings and provisioned callbacks. DNS
// safety is checked by the relay immediately before every outbound connection.
function bms_connect_address(string $input): array
{
    if (strlen($input) > 768 || preg_match('/[\x00-\x20\x7f\\\\%?#]/', $input) || !preg_match('~^https://([^/]+)(/.*)?$~D', $input, $m)) {
        throw new BMS_Connect_Error('invalid_authorization_request');
    }
    $host = $m[1];
    if (str_ends_with($host, ':443')) { $host = substr($host, 0, -4); }
    if (preg_match('/[:@\[\]]/', $host)) { throw new BMS_Connect_Error('invalid_authorization_request'); }
    if (str_ends_with($host, '.')) { $host = substr($host, 0, -1); }
    if (preg_match('/[^\x00-\x7f]/', $host)) {
        if (!function_exists('idn_to_ascii')) { throw new BMS_Connect_Error('connect_unavailable', 503); }
        $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
    }
    $host = strtolower($host);
    if (strlen($host) > 253 || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)
        || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)
        || preg_match('/\.(?:localhost|local|internal|home|lan|onion)$/D', $host)) {
        throw new BMS_Connect_Error('invalid_authorization_request');
    }
    $path = $m[2] ?? '';
    if ($path === '/') { $path = ''; }
    elseif (str_ends_with($path, '/')) { $path = substr($path, 0, -1); }
    if (strlen($path) > 512 || ($path !== '' && !preg_match('~^(?:/[A-Za-z0-9_\~.!$&\x27()*+,;=:@-]+)+$~D', $path))
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
        throw new BMS_Connect_Error('invalid_authorization_request');
    }
    return ['canonical_origin' => 'https://' . $host, 'base_path' => $path];
}

function bms_connect_identity(bool $requireEnabled = true): array
{
    $id = bms_connect_setting('connect_site_id', '');
    if (!bms_connect_is_uuid($id) || ($requireEnabled && bms_connect_setting('connect_enabled', '0') !== '1')) {
        throw new BMS_Connect_Error('connect_unavailable', 503);
    }
    $address = bms_connect_address(rtrim(bms_connect_setting('base_url', (string)(bms_config()['base_url'] ?? '')), '/') . bms_connect_setting('base_path', (string)(bms_config()['base_path'] ?? '')));
    return ['site_id' => $id] + $address;
}

function bms_connect_setting(string $key, string $default): string
{
    // Do not use the ordinary per-request settings cache for authorization state.
    $value = bms_connect_query('SELECT setting_value FROM ' . bms_table('settings') . ' WHERE setting_key = ? FOR UPDATE', [$key])->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function bms_connect_same_binding(array $a, array $b): bool
{
    foreach (['site_id', 'canonical_origin', 'base_path'] as $key) {
        if (!isset($a[$key], $b[$key]) || !is_string($a[$key]) || $a[$key] !== $b[$key]) { return false; }
    }
    return true;
}

function bms_connect_initialize(int $ownerId): array
{
    return bms_connect_transaction(static function () use ($ownerId): array {
        bms_connect_owner($ownerId);
        bms_connect_address(bms_site_url());
        bms_connect_hash('setup', '');
        bms_connect_query('INSERT IGNORE INTO ' . bms_table('settings') . ' (setting_key, setting_value, updated_at) VALUES (?, ?, UTC_TIMESTAMP())', ['connect_site_id', bms_connect_uuid()]);
        bms_set_setting('connect_enabled', '1');
        bms_connect_audit('initialize');
        return bms_connect_identity();
    });
}

function bms_connect_provision_client(int $ownerId, array $client): void
{
    if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', (string)($client['client_id'] ?? ''))
        || !is_string($client['display_name'] ?? null) || strlen($client['display_name']) < 1 || strlen($client['display_name']) > 120
        || preg_match('/[\x00-\x1f\x7f]/', $client['display_name'])
        || !is_array($client['callbacks'] ?? null) || count($client['callbacks']) < 1 || count($client['callbacks']) > 5) {
        throw new BMS_Connect_Error('invalid_client');
    }
    foreach ($client['callbacks'] as $callback) {
        if (!is_string($callback)) { throw new BMS_Connect_Error('invalid_redirect_uri'); }
        $address = bms_connect_address($callback);
        if (str_contains($callback, '*') || $callback !== $address['canonical_origin'] . $address['base_path']) {
            throw new BMS_Connect_Error('invalid_redirect_uri');
        }
    }
    bms_connect_transaction(static function () use ($ownerId, $client): void {
        bms_connect_owner($ownerId);
        // Registration is immutable. A changed deployment identity is a new client.
        bms_connect_query('INSERT INTO ' . bms_table('connect_clients') . ' (client_id, display_name, callbacks, status, created_at) VALUES (?, ?, ?, ?, ?)', [$client['client_id'], $client['display_name'], json_encode($client['callbacks'], JSON_THROW_ON_ERROR), 'active', time()]);
        bms_connect_audit('client_provisioned');
    });
}

function bms_connect_client(string $id, string $callback = ''): array
{
    $client = bms_connect_query('SELECT * FROM ' . bms_table('connect_clients') . ' WHERE client_id = ? FOR UPDATE', [$id])->fetch();
    if (!$client || $client['status'] !== 'active') { throw new BMS_Connect_Error('invalid_client'); }
    if ($callback !== '' && !in_array($callback, json_decode($client['callbacks'], true, 8, JSON_THROW_ON_ERROR), true)) {
        throw new BMS_Connect_Error('invalid_redirect_uri');
    }
    return $client;
}

function bms_connect_create_request(int $ownerId, array $input): array
{
    $required = ['client_id', 'redirect_uri', 'site_id', 'canonical_origin', 'base_path', 'state', 'code_challenge', 'code_challenge_method'];
    foreach ($required as $key) {
        if (!is_string($input[$key] ?? null) || strlen($input[$key]) > 768) { throw new BMS_Connect_Error('invalid_authorization_request'); }
    }
    if ($input['code_challenge_method'] !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $input['code_challenge'])
        || !preg_match('/^[a-f0-9]{64}$/D', $input['state'])) { throw new BMS_Connect_Error('invalid_authorization_request'); }
    $request = array_intersect_key($input, array_flip($required));
    $request['scopes'] = bms_connect_scopes($input['scopes'] ?? null);
    return bms_connect_transaction(static function () use ($ownerId, $request): array {
        bms_connect_owner($ownerId);
        if (!bms_connect_same_binding($request, bms_connect_identity())) { throw new BMS_Connect_Error('invalid_authorization_request'); }
        bms_connect_client($request['client_id'], $request['redirect_uri']);
        if ((int)bms_connect_query('SELECT COUNT(*) FROM ' . bms_table('connect_sessions') . ' WHERE expires_at > ? AND status = ?', [time(), 'pending'])->fetchColumn() >= 1000) {
            throw new BMS_Connect_Error('rate_limited', 429);
        }
        $id = bms_connect_uuid();
        bms_connect_query('INSERT INTO ' . bms_table('connect_sessions') . ' (session_id, owner_id, request_data, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)', [$id, $ownerId, json_encode($request, JSON_THROW_ON_ERROR), 'pending', time(), time() + 600]);
        bms_connect_audit('request_created');
        return bms_connect_request($id, $ownerId);
    });
}

function bms_connect_request(string $sessionId, int $ownerId): array
{
    $row = bms_connect_query('SELECT * FROM ' . bms_table('connect_sessions') . ' WHERE session_id = ? FOR UPDATE', [$sessionId])->fetch();
    if (!$row || (int)$row['owner_id'] !== $ownerId) { throw new BMS_Connect_Error('access_denied', 403); }
    if ($row['status'] !== 'pending' || (int)$row['expires_at'] <= time()) { throw new BMS_Connect_Error('authorization_expired'); }
    $row['request'] = json_decode($row['request_data'], true, 16, JSON_THROW_ON_ERROR);
    if (!bms_connect_same_binding($row['request'], bms_connect_identity())) { throw new BMS_Connect_Error('invalid_authorization_request'); }
    $row['client'] = bms_connect_client($row['request']['client_id'], $row['request']['redirect_uri']);
    return $row;
}

function bms_connect_decide(string $sessionId, int $ownerId, bool $approve, array $scopes, int $lifetime): array
{
    $scopes = bms_connect_scopes($scopes);
    if ($lifetime < 3600 || $lifetime > 90 * 86400) { throw new BMS_Connect_Error('invalid_authorization_request'); }
    return bms_connect_transaction(static function () use ($sessionId, $ownerId, $approve, $scopes, $lifetime): array {
        bms_connect_owner($ownerId);
        $row = bms_connect_request($sessionId, $ownerId);
        $r = $row['request'];
        if (array_diff($scopes, $r['scopes'])) { throw new BMS_Connect_Error('invalid_scope'); }
        bms_connect_query('UPDATE ' . bms_table('connect_sessions') . ' SET status = ? WHERE session_id = ?', [$approve ? 'approved' : 'denied', $sessionId]);
        $result = ['redirect_uri' => $r['redirect_uri'], 'state' => $r['state'], 'iss' => $r['canonical_origin'] . $r['base_path'], 'site_id' => $r['site_id']];
        if (!$approve) {
            bms_connect_audit('denial');
            return $result + ['error' => 'access_denied'];
        }
        $grantId = bms_connect_uuid();
        $code = bin2hex(random_bytes(32));
        bms_connect_query('INSERT INTO ' . bms_table('connect_grants') . ' (grant_id, session_id, client_id, display_name, owner_id, site_id, canonical_origin, base_path, requested_scopes, scopes, state, created_at, approved_at, updated_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $grantId, $sessionId, $r['client_id'], $row['client']['display_name'], $ownerId, $r['site_id'], $r['canonical_origin'], $r['base_path'], json_encode($r['scopes']), json_encode($scopes), 'pending', time(), time(), time(), time() + $lifetime,
        ]);
        bms_connect_query('INSERT INTO ' . bms_table('connect_codes') . ' (code_hash, session_id, grant_id, expires_at) VALUES (?, ?, ?, ?)', [bms_connect_hash('code', $code), $sessionId, $grantId, min(time() + 60, (int)$row['expires_at'])]);
        bms_connect_audit('approval', 'success', 'ok', $grantId);
        return $result + ['code' => $code];
    });
}

function bms_connect_exchange(array $input): array
{
    try {
        return bms_connect_transaction(static function () use ($input): array {
            foreach (['code', 'code_verifier', 'client_id', 'redirect_uri', 'site_id', 'canonical_origin', 'base_path'] as $key) {
                if (!is_string($input[$key] ?? null)) { throw new BMS_Connect_Error('invalid_grant'); }
            }
            if (!preg_match('/^[a-f0-9]{64}$/D', $input['code']) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $input['code_verifier'])) { throw new BMS_Connect_Error('invalid_grant'); }
            $code = bms_connect_query('SELECT * FROM ' . bms_table('connect_codes') . ' WHERE code_hash = ? FOR UPDATE', [bms_connect_hash('code', $input['code'])])->fetch();
            if (!$code || $code['consumed_at'] !== null || (int)$code['expires_at'] <= time()) { throw new BMS_Connect_Error('invalid_grant'); }
            $session = bms_connect_query('SELECT * FROM ' . bms_table('connect_sessions') . ' WHERE session_id = ? FOR UPDATE', [$code['session_id']])->fetch();
            $grant = bms_connect_query('SELECT * FROM ' . bms_table('connect_grants') . ' WHERE grant_id = ? FOR UPDATE', [$code['grant_id']])->fetch();
            if (!$session || !$grant || $session['status'] !== 'approved' || $grant['state'] !== 'pending' || (int)$grant['expires_at'] <= time()) { throw new BMS_Connect_Error('invalid_grant'); }
            $r = json_decode($session['request_data'], true, 16, JSON_THROW_ON_ERROR);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $input['code_verifier'], true)), '+/', '-_'), '=');
            if (!hash_equals($r['code_challenge'], $challenge) || $input['client_id'] !== $r['client_id'] || $input['redirect_uri'] !== $r['redirect_uri']
                || !bms_connect_same_binding($input, $r) || !bms_connect_same_binding($r, bms_connect_identity())) { throw new BMS_Connect_Error('invalid_grant'); }
            bms_connect_owner((int)$grant['owner_id']);
            bms_connect_client($r['client_id'], $r['redirect_uri']);
            $credential = 'bmsc_' . bin2hex(random_bytes(32));
            bms_connect_query('UPDATE ' . bms_table('connect_codes') . ' SET consumed_at = ? WHERE code_hash = ?', [time(), $code['code_hash']]);
            bms_connect_query('UPDATE ' . bms_table('connect_sessions') . ' SET status = ? WHERE session_id = ?', ['exchanged', $code['session_id']]);
            bms_connect_query('UPDATE ' . bms_table('connect_grants') . ' SET state = ?, updated_at = ? WHERE grant_id = ?', ['active', time(), $grant['grant_id']]);
            bms_connect_query('INSERT INTO ' . bms_table('connect_credentials') . ' (credential_id, credential_hash, grant_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?)', [bms_connect_uuid(), bms_connect_hash('access', $credential), $grant['grant_id'], time(), $grant['expires_at']]);
            bms_connect_audit('exchange', 'success', 'ok', $grant['grant_id']);
            return bms_connect_grant_payload($grant) + ['access_token' => $credential, 'token_type' => 'Bearer'];
        });
    } catch (BMS_Connect_Error $e) {
        try { bms_connect_audit('exchange', 'failure', 'invalid_grant'); } catch (Throwable $ignored) {}
        throw new BMS_Connect_Error('invalid_grant');
    }
}

function bms_connect_grant_payload(array $grant): array
{
    return ['site_id' => $grant['site_id'], 'canonical_origin' => $grant['canonical_origin'], 'base_path' => $grant['base_path'],
        'client_id' => $grant['client_id'], 'grant_id' => $grant['grant_id'], 'scopes' => bms_connect_scopes(json_decode($grant['scopes'], true)),
        'expires_at' => (int)$grant['expires_at']];
}

function bms_connect_authorized(string $bearer, string $clientId, array $requiredScopes, callable $operation): mixed
{
    if (!preg_match('/^bmsc_[a-f0-9]{64}$/D', $bearer)) { throw new BMS_Connect_Error('invalid_bearer_token', 401); }
    $requiredScopes = bms_connect_scopes($requiredScopes);
    $result = bms_connect_transaction(static function () use ($bearer, $clientId, $requiredScopes, $operation): mixed {
        $credential = bms_connect_query('SELECT * FROM ' . bms_table('connect_credentials') . ' WHERE credential_hash = ? FOR UPDATE', [bms_connect_hash('access', $bearer)])->fetch();
        if (!$credential || $credential['revoked_at'] !== null || (int)$credential['expires_at'] <= time()) { throw new BMS_Connect_Error('invalid_bearer_token', 401); }
        $grant = bms_connect_query('SELECT * FROM ' . bms_table('connect_grants') . ' WHERE grant_id = ? FOR UPDATE', [$credential['grant_id']])->fetch();
        if (!$grant || $grant['state'] !== 'active' || $grant['client_id'] !== $clientId || (int)$grant['expires_at'] <= time()) { throw new BMS_Connect_Error('invalid_bearer_token', 401); }
        if (!bms_connect_same_binding($grant, bms_connect_identity())) {
            bms_connect_query('UPDATE ' . bms_table('connect_grants') . ' SET state = ?, updated_at = ? WHERE grant_id = ?', ['suspended', time(), $grant['grant_id']]);
            bms_connect_audit('origin_changed', 'failure', 'site_origin_changed', $grant['grant_id']);
            return new BMS_Connect_Error('site_origin_changed', 409); // Commit suspension.
        }
        try { bms_connect_owner((int)$grant['owner_id']); bms_connect_client($clientId); }
        catch (BMS_Connect_Error $e) { throw new BMS_Connect_Error('invalid_bearer_token', 401); }
        $effective = bms_connect_scopes(json_decode($grant['scopes'], true));
        if (array_diff($requiredScopes, $effective)) { throw new BMS_Connect_Error('missing_scope', 403); }
        // The operation is inside the authority transaction, never after lock release.
        $value = $operation($grant);
        try {
            bms_connect_query('UPDATE ' . bms_table('connect_grants') . ' SET last_used_at = ?, last_network_hash = ? WHERE grant_id = ?', [time(), bms_connect_hash('network', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')), $grant['grant_id']]);
            bms_connect_audit('authorized_request', 'success', 'ok', $grant['grant_id']);
        } catch (Throwable $ignored) { /* Ordinary operation evidence is best effort. */ }
        return $value;
    });
    if ($result instanceof BMS_Connect_Error) { throw $result; }
    return $result;
}

function bms_connect_revoke_grant_locked(array $grant, string $reason): void
{
    if ($grant['state'] === 'revoked') { return; }
    bms_connect_query('UPDATE ' . bms_table('connect_grants') . ' SET state = ?, revoked_at = ?, updated_at = ?, revocation_reason = ? WHERE grant_id = ?', ['revoked', time(), time(), $reason, $grant['grant_id']]);
    bms_connect_query('UPDATE ' . bms_table('connect_credentials') . ' SET revoked_at = ? WHERE grant_id = ?', [time(), $grant['grant_id']]);
    bms_connect_query('UPDATE ' . bms_table('connect_codes') . ' SET consumed_at = COALESCE(consumed_at, ?) WHERE grant_id = ?', [time(), $grant['grant_id']]);
    bms_connect_query('UPDATE ' . bms_table('connect_sessions') . ' SET status = ? WHERE session_id = ?', ['revoked', $grant['session_id']]);
    bms_connect_audit('revocation', 'success', $reason, $grant['grant_id']);
}

function bms_connect_manage(int $ownerId, string $action, string $grantId = '', array $scopes = []): void
{
    if (!in_array($action, ['revoke', 'reduce', 'revoke_all', 'recovery', 'clone'], true)) { throw new BMS_Connect_Error('invalid_authorization_request'); }
    bms_connect_transaction(static function () use ($ownerId, $action, $grantId, $scopes): void {
        bms_connect_owner($ownerId);
        if (in_array($action, ['revoke', 'reduce'], true)) {
            $grant = bms_connect_query('SELECT * FROM ' . bms_table('connect_grants') . ' WHERE grant_id = ? AND owner_id = ? FOR UPDATE', [$grantId, $ownerId])->fetch();
            if (!$grant) { throw new BMS_Connect_Error('access_denied', 403); }
            if ($action === 'revoke') { bms_connect_revoke_grant_locked($grant, 'owner_revoked'); return; }
            $scopes = bms_connect_scopes($scopes);
            if ($grant['state'] !== 'active' || array_diff($scopes, json_decode($grant['scopes'], true))) { throw new BMS_Connect_Error('invalid_scope'); }
            bms_connect_query('UPDATE ' . bms_table('connect_grants') . ' SET scopes = ?, updated_at = ? WHERE grant_id = ?', [json_encode($scopes), time(), $grantId]);
            bms_connect_audit('scope_reduced', 'success', 'ok', $grantId);
            return;
        }
        $grants = bms_connect_query('SELECT * FROM ' . bms_table('connect_grants') . ' WHERE state <> ? FOR UPDATE', ['revoked'])->fetchAll();
        foreach ($grants as $grant) { bms_connect_revoke_grant_locked($grant, $action); }
        bms_connect_query('UPDATE ' . bms_table('connect_sessions') . ' SET status = ? WHERE status IN (?, ?)', ['revoked', 'pending', 'approved']);
        bms_connect_query('UPDATE ' . bms_table('connect_codes') . ' SET consumed_at = COALESCE(consumed_at, ?)', [time()]);
        bms_connect_query('UPDATE ' . bms_table('connect_credentials') . ' SET revoked_at = COALESCE(revoked_at, ?)', [time()]);
        if ($action === 'clone') { bms_set_setting('connect_site_id', bms_connect_uuid()); }
        if (in_array($action, ['recovery', 'clone'], true)) { bms_set_setting('connect_enabled', '0'); }
        bms_connect_audit($action === 'revoke_all' ? 'revoke_all' : 'recovery_revocation');
    });
}

function bms_connect_rate_limit(string $route): void
{
    bms_connect_transaction(static function () use ($route): void {
        $window = intdiv(time(), 60);
        bms_connect_query('DELETE FROM ' . bms_table('connect_limits') . ' WHERE window_id < ? LIMIT 500', [$window - 2]);
        foreach (['global' => 300, $route . ':' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') => 30] as $subject => $max) {
            $bucket = bms_connect_hash('limit', $subject);
            $row = bms_connect_query('SELECT * FROM ' . bms_table('connect_limits') . ' WHERE bucket = ? FOR UPDATE', [$bucket])->fetch();
            $attempts = $row && (int)$row['window_id'] === $window ? (int)$row['attempts'] : 0;
            if ($attempts >= $max) { throw new BMS_Connect_Error('rate_limited', 429); }
            bms_connect_query('INSERT INTO ' . bms_table('connect_limits') . ' (bucket, window_id, attempts) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE window_id = VALUES(window_id), attempts = VALUES(attempts)', [$bucket, $window, $attempts + 1]);
        }
    });
}

// Explicit bounded maintenance. No authorization decision depends on cleanup.
function bms_connect_maintenance(int $ownerId): void
{
    bms_connect_transaction(static function () use ($ownerId): void {
        bms_connect_owner($ownerId);
        bms_connect_query('DELETE FROM ' . bms_table('connect_audit') . ' WHERE created_at < ? LIMIT 1000', [time() - 90 * 86400]);
        bms_connect_query('DELETE FROM ' . bms_table('connect_codes') . ' WHERE expires_at < ? LIMIT 1000', [time() - 86400]);
        bms_connect_query('DELETE FROM ' . bms_table('connect_sessions') . ' WHERE expires_at < ? LIMIT 1000', [time() - 86400]);
        bms_connect_query('DELETE FROM ' . bms_table('connect_limits') . ' WHERE window_id < ? LIMIT 500', [intdiv(time(), 60) - 2]);
    });
}

function bms_connect_discovery(): array
{
    $identity = bms_connect_identity();
    $base = $identity['canonical_origin'] . $identity['base_path'];
    return $identity + ['protocol_versions' => [1], 'supported_scopes' => bms_connect_scopes(['status:read', 'stream:read', 'stream:draft', 'media:upload']),
        'endpoints' => ['authorize' => $base . '/admin/connect-authorize.php', 'token' => $base . '/api/connect/v1/token.php', 'status' => $base . '/api/connect/v1/status.php', 'revoke' => $base . '/api/connect/v1/revoke.php'],
        'capabilities' => ['connection_status' => ['implemented' => true, 'enabled' => true, 'required_scopes' => ['status:read']]],
        'authorization' => ['pkce_methods' => ['S256'], 'session_max_seconds' => 600, 'code_max_seconds' => 60, 'credential_max_seconds' => 7776000, 'refresh_supported' => false]];
}

function bms_connect_headers(): void
{
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
}

function bms_connect_http(string $route): never
{
    bms_connect_headers();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $expected = in_array($route, ['token', 'revoke'], true) ? 'POST' : 'GET';
        if ($method !== $expected) { throw new BMS_Connect_Error('method_not_allowed', 405); }
        bms_connect_rate_limit($route);
        $input = [];
        if ($method === 'POST') {
            if (strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') { throw new BMS_Connect_Error('invalid_json'); }
            $raw = file_get_contents('php://input', false, null, 0, 8193);
            if (!is_string($raw) || strlen($raw) > 8192) { throw new BMS_Connect_Error('request_too_large', 413); }
            $input = json_decode($raw, true, 16);
            if (!is_array($input) || !(json_decode($raw, false, 16) instanceof stdClass)) { throw new BMS_Connect_Error('invalid_json'); }
        }
        if ($route === 'discovery') { $data = bms_connect_discovery(); }
        elseif ($route === 'token') { $data = bms_connect_exchange($input); }
        else {
            $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (!preg_match('/^Bearer (bmsc_[a-f0-9]{64})$/D', $header, $match)) { throw new BMS_Connect_Error($header === '' ? 'missing_bearer_token' : 'invalid_bearer_token', 401); }
            $data = bms_connect_authorized($match[1], (string)($_SERVER['HTTP_X_BONUMARK_CLIENT'] ?? ''), $route === 'status' ? ['status:read'] : [], static function (array $grant) use ($route): array {
                if ($route === 'revoke') { bms_connect_revoke_grant_locked($grant, 'relay_disconnect'); return ['revoked' => true]; }
                return bms_connect_grant_payload($grant) + ['connected' => true];
            });
        }
        $output = ['ok' => true, 'request_id' => bms_connect_request_id()] + $data;
    } catch (Throwable $e) {
        $status = $e instanceof BMS_Connect_Error ? $e->httpStatus : 500;
        $code = $e instanceof BMS_Connect_Error ? $e->resultCode : 'server_error';
        http_response_code($status);
        $output = ['ok' => false, 'request_id' => bms_connect_request_id(), 'error' => ['code' => $code, 'message' => 'The Connect request could not be completed.']];
    }
    echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
