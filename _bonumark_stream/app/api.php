<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/import-media.php';

class BMS_Api_Exception extends RuntimeException
{
    public int $statusCode;
    public string $apiCode;

    public function __construct(string $message, int $statusCode = 400, string $apiCode = 'api_error')
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->apiCode = $apiCode;
    }
}

function bms_api_enabled(): bool
{
    return (string)bms_setting_or_config('remote_posting_enabled', '0') === '1';
}

function bms_api_rate_limit_per_minute(): int
{
    $limit = (int)bms_setting_or_config('remote_posting_rate_limit_per_minute', '60');
    return max(5, min(600, $limit));
}

function bms_api_direct_publish_enabled(): bool
{
    return (string)bms_setting_or_config('remote_posting_direct_publish_enabled', '0') === '1';
}

function bms_api_default_status(): string
{
    $status = strtolower(trim((string)bms_setting_or_config('remote_posting_default_status', 'draft')));
    return $status === 'published' ? 'published' : 'draft';
}

function bms_api_publish_confirmation_required(): bool
{
    return (string)bms_setting_or_config('remote_posting_publish_confirmation_required', '1') !== '0';
}

function bms_api_remote_media_upload_enabled(): bool
{
    return (string)bms_setting_or_config('remote_media_upload_enabled', '0') === '1';
}

function bms_api_normalize_requested_status(array $payload): string
{
    $status = strtolower(trim((string)($payload['status'] ?? '')));
    if ($status === '') {
        $status = bms_api_default_status();
    }
    if (!in_array($status, ['draft', 'published'], true)) {
        throw new BMS_Api_Exception('Status must be draft or published.', 422, 'invalid_status');
    }
    return $status;
}

function bms_api_publish_confirmation_satisfied(array $payload): bool
{
    if (!bms_api_publish_confirmation_required()) {
        return true;
    }
    if (($payload['confirm_publish'] ?? false) === true) {
        return true;
    }
    $confirmation = strtolower(trim((string)($payload['confirmation'] ?? $payload['publish_confirmation'] ?? '')));
    return in_array($confirmation, ['publish', 'confirm_publish', 'yes_publish'], true);
}

function bms_api_token_scope_definitions(): array
{
    return [
        'status:read' => [
            'label' => 'Read API status',
            'description' => 'Allows a client to verify that the token works against the status endpoint.',
            'available' => true,
        ],
        'stream:draft' => [
            'label' => 'Create remote drafts',
            'description' => 'Allows a trusted client to create draft stream posts for admin review.',
            'available' => true,
        ],
        'stream:publish' => [
            'label' => 'Publish remotely',
            'description' => 'Allows a trusted client to publish stream posts when direct publishing is enabled.',
            'available' => true,
        ],
        'media:upload' => [
            'label' => 'Upload media remotely',
            'description' => 'Allows a trusted client to upload image media through the API when remote media uploads are enabled.',
            'available' => true,
        ],
    ];
}

function bms_api_normalize_scopes(mixed $scopes): array
{
    if (is_string($scopes)) {
        $decoded = json_decode($scopes, true);
        if (is_array($decoded)) {
            $scopes = $decoded;
        } else {
            $scopes = preg_split('/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    }
    if (!is_array($scopes)) {
        $scopes = [];
    }

    $definitions = bms_api_token_scope_definitions();
    $allowed = [];
    foreach ($definitions as $scope => $definition) {
        if (!empty($definition['available'])) {
            $allowed[] = (string)$scope;
        }
    }
    $clean = [];
    foreach ($scopes as $scope) {
        $scope = strtolower(trim((string)$scope));
        if ($scope !== '' && in_array($scope, $allowed, true)) {
            $clean[$scope] = true;
        }
    }
    if (!$clean) {
        $clean['status:read'] = true;
    }
    return array_keys($clean);
}

function bms_api_scope_labels(array|string|null $scopes): string
{
    $definitions = bms_api_token_scope_definitions();
    $labels = [];
    foreach (bms_api_normalize_scopes($scopes ?? []) as $scope) {
        $labels[] = (string)($definitions[$scope]['label'] ?? $scope);
    }
    return implode(', ', $labels);
}

function bms_api_token_hash(string $token): string
{
    $salt = (string)(bms_config()['security_salt'] ?? '');
    if ($salt === '') {
        $salt = 'bonumark-stream-api';
    }
    return hash_hmac('sha256', $token, $salt);
}

function bms_api_generate_plain_token(): string
{
    return 'bmsrt_' . bin2hex(random_bytes(32));
}

function bms_api_token_preview(string $token): array
{
    return [
        'prefix' => substr($token, 0, 12),
        'hint' => substr($token, -6),
    ];
}

function bms_api_hash_ip(string $value = ''): string
{
    $ip = $value !== '' ? $value : (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash_hmac('sha256', $ip, (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_api_hash_user_agent(): string
{
    return hash_hmac('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_api_request_route(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : '/api/v1/status';
}

function bms_api_request_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function bms_api_authorization_token(): string
{
    $header = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $key) {
        if (!empty($_SERVER[$key])) {
            $header = (string)$_SERVER[$key];
            break;
        }
    }
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'authorization') {
                $header = (string)$value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $match) !== 1) {
        return '';
    }
    return trim((string)$match[1]);
}

function bms_api_header_value(string $name): string
{
    $normalized = strtolower($name);
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$serverKey])) {
        return trim((string)$_SERVER[$serverKey]);
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $headerName => $value) {
            if (strtolower((string)$headerName) === $normalized) {
                return trim((string)$value);
            }
        }
    }
    return '';
}

function bms_api_json_response(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        bms_send_security_headers();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    if (bms_api_request_method() !== 'HEAD') {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo "\n";
    }
    exit;
}

function bms_api_error_response(Throwable $e): never
{
    $status = $e instanceof BMS_Api_Exception ? $e->statusCode : 500;
    $code = $e instanceof BMS_Api_Exception ? $e->apiCode : 'server_error';
    $message = $e instanceof BMS_Api_Exception ? $e->getMessage() : ($status >= 500 ? 'The API request could not be completed.' : $e->getMessage());
    bms_api_json_response([
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ], $status);
}

function bms_api_record_rate_attempt(string $identifierHash, string $route, bool $success): void
{
    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('api_rate_limit_attempts') . ' (identifier_hash, route, success, attempted_at) VALUES (:identifier_hash, :route, :success, NOW())');
        $stmt->execute([
            'identifier_hash' => $identifierHash,
            'route' => substr($route, 0, 120),
            'success' => $success ? 1 : 0,
        ]);
        if (random_int(1, 20) === 1) {
            bms_db()->exec('DELETE FROM ' . bms_table('api_rate_limit_attempts') . ' WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
        }
    } catch (Throwable $e) {
        // Rate-limit logging should not expose database details.
    }
}

function bms_api_rate_limited(string $identifierHash, string $route, ?int $limit = null, int $windowSeconds = 60): bool
{
    $limit = $limit ?? bms_api_rate_limit_per_minute();
    $limit = max(1, min(600, $limit));
    $windowSeconds = max(10, min(3600, $windowSeconds));
    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('api_rate_limit_attempts') . ' WHERE identifier_hash = :identifier_hash AND route = :route AND attempted_at > (NOW() - INTERVAL ' . $windowSeconds . ' SECOND)');
        $stmt->execute([
            'identifier_hash' => $identifierHash,
            'route' => substr($route, 0, 120),
        ]);
        return (int)$stmt->fetchColumn() >= $limit;
    } catch (Throwable $e) {
        return true;
    }
}

function bms_api_record_audit(?int $tokenId, string $event, bool $success, int $statusCode, string $message = '', string $requestId = ''): void
{
    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('api_audit_log') . ' (token_id, event, method, route, ip_hash, user_agent_hash, status_code, success, message, request_id, created_at) VALUES (:token_id, :event, :method, :route, :ip_hash, :user_agent_hash, :status_code, :success, :message, :request_id, NOW())');
        $stmt->execute([
            'token_id' => $tokenId !== null && $tokenId > 0 ? $tokenId : null,
            'event' => substr($event, 0, 80),
            'method' => substr(bms_api_request_method(), 0, 12),
            'route' => substr(bms_api_request_route(), 0, 255),
            'ip_hash' => bms_api_hash_ip(),
            'user_agent_hash' => bms_api_hash_user_agent(),
            'status_code' => max(0, min(599, $statusCode)),
            'success' => $success ? 1 : 0,
            'message' => substr($message, 0, 255),
            'request_id' => substr($requestId, 0, 80),
        ]);
    } catch (Throwable $e) {
        // API audit failure should not leak details to clients.
    }
}

function bms_api_find_token_by_plain_token(string $plainToken): ?array
{
    if ($plainToken === '') {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('api_tokens') . ' WHERE token_hash = :token_hash AND status = :status LIMIT 1');
    $stmt->execute([
        'token_hash' => bms_api_token_hash($plainToken),
        'status' => 'active',
    ]);
    $token = $stmt->fetch();
    if (!is_array($token)) {
        return null;
    }
    $expires = trim((string)($token['expires_at'] ?? ''));
    if ($expires !== '' && strtotime($expires) !== false && strtotime($expires) < time()) {
        return null;
    }
    $token['scopes'] = bms_api_normalize_scopes($token['scopes_json'] ?? '');
    return $token;
}

function bms_api_touch_token(array $token): void
{
    try {
        $stmt = bms_db()->prepare('UPDATE ' . bms_table('api_tokens') . ' SET last_used_at = NOW(), last_used_ip_hash = :last_used_ip_hash, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'last_used_ip_hash' => bms_api_hash_ip(),
            'id' => (int)($token['id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        // Last-used metadata should not block a valid request.
    }
}

function bms_api_authenticate(array $requiredScopes = ['status:read']): array
{
    if (!bms_api_enabled()) {
        throw new BMS_Api_Exception('Remote posting API is disabled.', 403, 'remote_posting_disabled');
    }

    $plainToken = bms_api_authorization_token();
    $route = bms_api_request_route();
    $ipKey = bms_api_hash_ip();

    if ($plainToken === '') {
        bms_api_record_rate_attempt($ipKey, $route, false);
        bms_api_record_audit(null, 'auth_missing', false, 401, 'Missing bearer token.');
        throw new BMS_Api_Exception('Missing bearer token.', 401, 'missing_bearer_token');
    }

    if (bms_api_rate_limited($ipKey, $route, bms_api_rate_limit_per_minute(), 60)) {
        bms_api_record_audit(null, 'rate_limited', false, 429, 'IP rate limit exceeded.');
        throw new BMS_Api_Exception('API rate limit exceeded.', 429, 'rate_limited');
    }

    $token = bms_api_find_token_by_plain_token($plainToken);
    if (!$token) {
        bms_api_record_rate_attempt($ipKey, $route, false);
        bms_api_record_audit(null, 'auth_failed', false, 401, 'Invalid bearer token.');
        throw new BMS_Api_Exception('Invalid bearer token.', 401, 'invalid_bearer_token');
    }

    $tokenKey = hash('sha256', 'token:' . (string)($token['id'] ?? 0));
    if (bms_api_rate_limited($tokenKey, $route, bms_api_rate_limit_per_minute(), 60)) {
        bms_api_record_rate_attempt($tokenKey, $route, false);
        bms_api_record_audit((int)$token['id'], 'rate_limited', false, 429, 'Token rate limit exceeded.');
        throw new BMS_Api_Exception('API rate limit exceeded.', 429, 'rate_limited');
    }

    $scopes = bms_api_normalize_scopes($token['scopes'] ?? $token['scopes_json'] ?? []);
    foreach ($requiredScopes as $scope) {
        if (!in_array($scope, $scopes, true)) {
            bms_api_record_rate_attempt($tokenKey, $route, false);
            bms_api_record_audit((int)$token['id'], 'scope_denied', false, 403, 'Missing scope: ' . $scope);
            throw new BMS_Api_Exception('Token does not have the required scope.', 403, 'missing_scope');
        }
    }

    bms_api_record_rate_attempt($tokenKey, $route, true);
    bms_api_touch_token($token);
    $token['scopes'] = $scopes;
    return $token;
}

function bms_api_create_token(string $name, array $scopes, ?string $expiresAt, ?int $createdBy = null): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        throw new RuntimeException('Token name is required.');
    }
    if (strlen($name) > 120) {
        throw new RuntimeException('Token name must be 120 characters or fewer.');
    }
    $scopes = bms_api_normalize_scopes($scopes);
    $expiresAt = trim((string)$expiresAt) !== '' ? $expiresAt : null;
    if ($expiresAt !== null && strtotime($expiresAt) === false) {
        throw new RuntimeException('Enter a valid expiration date or leave it blank.');
    }

    $plainToken = bms_api_generate_plain_token();
    $preview = bms_api_token_preview($plainToken);
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('api_tokens') . ' (token_name, token_prefix, token_hint, token_hash, scopes_json, status, created_by, created_at, updated_at, expires_at) VALUES (:token_name, :token_prefix, :token_hint, :token_hash, :scopes_json, :status, :created_by, NOW(), NOW(), :expires_at)');
    $stmt->execute([
        'token_name' => $name,
        'token_prefix' => $preview['prefix'],
        'token_hint' => $preview['hint'],
        'token_hash' => bms_api_token_hash($plainToken),
        'scopes_json' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
        'status' => 'active',
        'created_by' => $createdBy !== null && $createdBy > 0 ? $createdBy : null,
        'expires_at' => $expiresAt,
    ]);

    $id = (int)bms_db()->lastInsertId();
    bms_api_record_audit($id, 'token_created', true, 201, 'API token created from admin.');
    return [
        'plain_token' => $plainToken,
        'token' => bms_api_get_token($id),
    ];
}

function bms_api_get_token(int $id): ?array
{
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('api_tokens') . ' WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $token = $stmt->fetch();
    if (!is_array($token)) {
        return null;
    }
    $token['scopes'] = bms_api_normalize_scopes($token['scopes_json'] ?? '');
    return $token;
}

function bms_api_list_tokens(): array
{
    $stmt = bms_db()->query('SELECT * FROM ' . bms_table('api_tokens') . ' ORDER BY status ASC, created_at DESC, id DESC');
    $tokens = $stmt->fetchAll() ?: [];
    foreach ($tokens as &$token) {
        $token['scopes'] = bms_api_normalize_scopes($token['scopes_json'] ?? '');
    }
    unset($token);
    return $tokens;
}

function bms_api_revoke_token(int $id): void
{
    $token = bms_api_get_token($id);
    if (!$token) {
        throw new RuntimeException('API token was not found.');
    }
    if ((string)($token['status'] ?? '') !== 'active') {
        return;
    }
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('api_tokens') . " SET status = 'revoked', revoked_at = NOW(), updated_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $id]);
    bms_api_record_audit($id, 'token_revoked', true, 200, 'API token revoked from admin.');
}

function bms_api_recent_audit_log(int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    try {
        $stmt = bms_db()->query('SELECT l.*, t.token_name, t.token_prefix, t.token_hint FROM ' . bms_table('api_audit_log') . ' l LEFT JOIN ' . bms_table('api_tokens') . ' t ON t.id = l.token_id ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $limit);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}


function bms_api_idempotency_key_from_payload(array $payload): string
{
    $key = bms_api_header_value('Idempotency-Key');
    if ($key === '') {
        $key = (string)($payload['idempotency_key'] ?? $payload['client_request_id'] ?? $payload['request_id'] ?? '');
    }
    $key = preg_replace('/[^A-Za-z0-9._:-]/', '', trim($key)) ?? '';
    return substr($key, 0, 120);
}

function bms_api_normalize_for_hash(mixed $value): mixed
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = bms_api_normalize_for_hash($item);
        }
        if (array_keys($normalized) !== range(0, count($normalized) - 1)) {
            ksort($normalized);
        }
        return $normalized;
    }
    return $value;
}

function bms_api_request_hash(array $payload, string $method = '', string $route = ''): string
{
    $hashPayload = [
        'method' => $method !== '' ? strtoupper($method) : bms_api_request_method(),
        'route' => $route !== '' ? $route : bms_api_request_route(),
        'payload' => bms_api_normalize_for_hash($payload),
    ];
    return hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function bms_api_idempotency_begin(int $tokenId, string $key, string $requestHash): ?array
{
    if ($key === '') {
        return null;
    }
    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('api_idempotency_keys') . ' WHERE token_id = :token_id AND idempotency_key = :idempotency_key LIMIT 1');
        $stmt->execute([
            'token_id' => $tokenId,
            'idempotency_key' => $key,
        ]);
        $existing = $stmt->fetch();
        if (is_array($existing)) {
            if (!hash_equals((string)($existing['request_hash'] ?? ''), $requestHash)) {
                throw new BMS_Api_Exception('Idempotency key was already used for a different request.', 409, 'idempotency_key_conflict');
            }
            $responseJson = (string)($existing['response_json'] ?? '');
            if ($responseJson === '') {
                throw new BMS_Api_Exception('Idempotency key is already processing. Retry after the first request completes.', 409, 'idempotency_key_processing');
            }
            $payload = json_decode($responseJson, true);
            if (!is_array($payload)) {
                throw new BMS_Api_Exception('Stored idempotency response could not be read.', 500, 'idempotency_response_invalid');
            }
            return [
                'status' => max(200, min(599, (int)($existing['response_status'] ?? 200))),
                'payload' => $payload,
            ];
        }

        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('api_idempotency_keys') . ' (token_id, idempotency_key, request_hash, response_status, response_json, created_at, last_used_at, expires_at) VALUES (:token_id, :idempotency_key, :request_hash, 0, \'\', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))');
        $stmt->execute([
            'token_id' => $tokenId,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
        ]);
        if (random_int(1, 20) === 1) {
            bms_db()->exec('DELETE FROM ' . bms_table('api_idempotency_keys') . ' WHERE expires_at < NOW()');
        }
        return null;
    } catch (BMS_Api_Exception $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new BMS_Api_Exception('Could not process the idempotency key.', 500, 'idempotency_failed');
    }
}

function bms_api_idempotency_store(int $tokenId, string $key, string $requestHash, array $payload, int $status): void
{
    if ($key === '') {
        return;
    }
    try {
        $stmt = bms_db()->prepare('UPDATE ' . bms_table('api_idempotency_keys') . ' SET response_status = :response_status, response_json = :response_json, last_used_at = NOW() WHERE token_id = :token_id AND idempotency_key = :idempotency_key AND request_hash = :request_hash');
        $stmt->execute([
            'response_status' => max(200, min(599, $status)),
            'response_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'token_id' => $tokenId,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
        ]);
    } catch (Throwable $e) {
        // A successful post should not fail because the idempotency cache could not be updated.
    }
}

function bms_api_idempotency_release(int $tokenId, string $key, string $requestHash): void
{
    if ($key === '') {
        return;
    }
    try {
        $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('api_idempotency_keys') . ' WHERE token_id = :token_id AND idempotency_key = :idempotency_key AND request_hash = :request_hash AND response_json = \'\'');
        $stmt->execute([
            'token_id' => $tokenId,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
        ]);
    } catch (Throwable $e) {
        // Ignore cleanup failures.
    }
}

function bms_api_read_json_body(int $maxBytes = 2097152): array
{
    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > $maxBytes) {
        throw new BMS_Api_Exception('Request body is too large.', 413, 'payload_too_large');
    }
    if (trim($raw) === '') {
        throw new BMS_Api_Exception('JSON body is required.', 400, 'missing_json_body');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new BMS_Api_Exception('Request body must be valid JSON.', 400, 'invalid_json');
    }
    return $decoded;
}

function bms_api_request_id_from_payload(array $payload): string
{
    $id = trim((string)($payload['client_request_id'] ?? $payload['request_id'] ?? ''));
    if ($id === '') {
        return '';
    }
    $id = preg_replace('/[^A-Za-z0-9._:-]/', '', $id) ?? '';
    return substr($id, 0, 80);
}

function bms_api_token_author_id(array $token): ?int
{
    $createdBy = (int)($token['created_by'] ?? 0);
    if ($createdBy > 0) {
        try {
            $stmt = bms_db()->prepare('SELECT id FROM ' . bms_table('users') . " WHERE id = :id AND role = 'admin' AND status = 'active' LIMIT 1");
            $stmt->execute(['id' => $createdBy]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        } catch (Throwable $e) {
            return null;
        }
    }

    try {
        $stmt = bms_db()->query('SELECT id FROM ' . bms_table('users') . " WHERE role = 'admin' AND status = 'active' ORDER BY id ASC LIMIT 1");
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function bms_api_string_field(array $payload, array $keys, int $limit = 255): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $payload)) {
            $value = trim((string)$payload[$key]);
            if ($limit > 0 && strlen($value) > $limit) {
                $value = substr($value, 0, $limit);
            }
            return $value;
        }
    }
    return '';
}

function bms_api_markdown_alt_text(string $text): string
{
    $text = trim(str_replace(["\r", "\n"], ' ', $text));
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    $text = str_replace([']', '['], ['\\]', '\\['], $text);
    return $text !== '' ? $text : 'Uploaded image';
}

function bms_api_media_response(array $media): array
{
    $publicPath = trim((string)($media['public_path'] ?? ''));
    $url = $publicPath !== '' ? bms_site_url($publicPath) : '';
    $altText = trim((string)($media['alt_text'] ?? ''));
    $markdownAlt = bms_api_markdown_alt_text($altText);
    return [
        'media_id' => (int)($media['id'] ?? 0),
        'url' => $url,
        'public_path' => $publicPath,
        'filename' => (string)($media['filename'] ?? ''),
        'original_filename' => (string)($media['original_filename'] ?? ''),
        'mime_type' => (string)($media['mime_type'] ?? ''),
        'file_size' => (int)($media['file_size'] ?? 0),
        'width' => isset($media['width']) ? (int)$media['width'] : null,
        'height' => isset($media['height']) ? (int)$media['height'] : null,
        'alt_text' => $altText,
        'caption' => (string)($media['caption'] ?? ''),
        'markdown' => '![' . $markdownAlt . '](' . $url . ')',
        'edit_url' => bms_site_url('admin/media-edit.php?id=' . urlencode((string)($media['id'] ?? ''))),
    ];
}

function bms_api_uploaded_file_from_json_payload(array $payload): array
{
    $filename = bms_api_string_field($payload, ['filename', 'name', 'original_filename'], 255);
    if ($filename === '') {
        throw new BMS_Api_Exception('Media filename is required for JSON uploads.', 422, 'media_filename_required');
    }
    $base64 = trim((string)($payload['content_base64'] ?? $payload['file_base64'] ?? $payload['data_base64'] ?? $payload['data'] ?? ''));
    if ($base64 === '') {
        throw new BMS_Api_Exception('Base64 image content is required for JSON uploads.', 422, 'media_content_required');
    }
    if (preg_match('#^data:([^;,]+);base64,(.+)$#s', $base64, $match) === 1) {
        $base64 = trim((string)$match[2]);
    }
    $base64 = preg_replace('/\s+/', '', $base64) ?? '';
    $limitBytes = bms_current_media_upload_limit_bytes();
    if (strlen($base64) > (int)ceil($limitBytes * 1.5) + 1024) {
        throw new BMS_Api_Exception('Media file is too large. Keep uploads under ' . bms_media_human_size($limitBytes) . '.', 413, 'media_too_large');
    }
    $binary = base64_decode($base64, true);
    if ($binary === false || $binary === '') {
        throw new BMS_Api_Exception('Base64 image content could not be decoded.', 400, 'invalid_media_base64');
    }
    if (strlen($binary) > $limitBytes) {
        throw new BMS_Api_Exception('Media file is too large. Keep uploads under ' . bms_media_human_size($limitBytes) . '.', 413, 'media_too_large');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'bms-api-media-');
    if ($tmp === false || file_put_contents($tmp, $binary) === false) {
        throw new BMS_Api_Exception('Could not prepare the media upload.', 500, 'media_temp_failed');
    }
    return [
        'name' => $filename,
        'type' => '',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($binary),
        '_bms_api_tmp' => true,
    ];
}

function bms_api_uploaded_file_from_request(?array $payload = null): array
{
    foreach (['media_file', 'file', 'media', 'image'] as $key) {
        if (isset($_FILES[$key]) && is_array($_FILES[$key])) {
            return $_FILES[$key];
        }
    }
    if (is_array($payload)) {
        return bms_api_uploaded_file_from_json_payload($payload);
    }
    throw new BMS_Api_Exception('Image upload file is required.', 422, 'media_file_required');
}

function bms_api_placeholder_guard_text(array $payload, array $file): string
{
    $parts = [
        (string)($file['name'] ?? ''),
        bms_api_string_field($payload, ['filename', 'name', 'original_filename'], 255),
        bms_api_string_field($payload, ['alt_text', 'alt', 'description'], 255),
        bms_api_string_field($payload, ['caption'], 500),
    ];
    return strtolower(implode(' ', array_filter($parts, static fn($value) => trim((string)$value) !== '')));
}

function bms_api_reject_placeholder_media_upload(array $payload, array $file): void
{
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return;
    }

    $size = (int)($file['size'] ?? filesize($tmp) ?: 0);
    $hash = hash_file('sha256', $tmp) ?: '';
    $text = bms_api_placeholder_guard_text($payload, $file);
    $looksNamedPlaceholder = preg_match('/\b(placeholder|sample|dummy|fake)[\w.-]*\.(png|jpe?g|gif|webp)\b/i', $text) === 1
        || preg_match('/\bplaceholder image\b/i', $text) === 1
        || preg_match('/\bplaceholder media upload\b/i', $text) === 1;
    $isKnownOnePixelPlaceholder = hash_equals('4b5c5c92cec3b23e6a294fc0eea43234ef5126c5a64f4c6c531ac8430ab0b844', $hash);
    $isTinyImage = false;
    if ($size > 0 && $size <= 128) {
        $dimensions = @getimagesize($tmp);
        if (is_array($dimensions)) {
            $width = (int)($dimensions[0] ?? 0);
            $height = (int)($dimensions[1] ?? 0);
            $isTinyImage = $width <= 1 && $height <= 1;
        }
    }

    if ($isKnownOnePixelPlaceholder || ($looksNamedPlaceholder && $isTinyImage)) {
        throw new BMS_Api_Exception('Placeholder media was rejected. Upload a real image, import a public image URL, or reference an existing Bonumark media ID.', 422, 'placeholder_media_rejected');
    }
}

function bms_api_create_remote_media(array $payload, array $token, array $file): array
{
    if (!bms_api_remote_media_upload_enabled()) {
        throw new BMS_Api_Exception('Remote media uploads are disabled.', 403, 'remote_media_upload_disabled');
    }
    bms_api_reject_placeholder_media_upload($payload, $file);
    $altText = bms_api_string_field($payload, ['alt_text', 'alt', 'description'], 255);
    $caption = bms_api_string_field($payload, ['caption'], 500);
    $uploadedBy = bms_api_token_author_id($token);
    try {
        $media = bms_media_upload($file, $altText, $caption, [
            'image_only' => true,
            'uploaded_by' => $uploadedBy,
        ]);
    } catch (RuntimeException $e) {
        throw new BMS_Api_Exception($e->getMessage(), 422, 'media_upload_invalid');
    }
    return bms_api_media_response($media);
}

function bms_api_remote_media_import_url(array $payload): string
{
    $url = bms_api_string_field($payload, ['image_url', 'media_import_url', 'remote_image_url', 'source_url', 'url'], 2048);
    if ($url === '') {
        throw new BMS_Api_Exception('Public image URL is required for media import.', 422, 'media_import_url_required');
    }
    if (!bms_import_is_remote_http_url($url)) {
        throw new BMS_Api_Exception('Media import URL must be an HTTP or HTTPS image URL.', 422, 'invalid_media_import_url');
    }
    if (!bms_import_remote_image_url_is_safe($url)) {
        throw new BMS_Api_Exception('Media import URL was rejected for safety.', 422, 'unsafe_media_import_url');
    }
    return $url;
}

function bms_api_import_remote_media(array $payload, array $token): array
{
    if (!bms_api_remote_media_upload_enabled()) {
        throw new BMS_Api_Exception('Remote media imports are disabled.', 403, 'remote_media_upload_disabled');
    }
    $url = bms_api_remote_media_import_url($payload);
    $download = null;
    try {
        $download = bms_import_download_remote_image($url);
        $file = [
            'name' => (string)($payload['filename'] ?? $download['filename'] ?? 'imported-image'),
            'type' => (string)($download['mime'] ?? ''),
            'tmp_name' => (string)($download['path'] ?? ''),
            'error' => UPLOAD_ERR_OK,
            'size' => (int)($download['size'] ?? 0),
            '_bms_api_tmp' => true,
        ];
        $media = bms_api_create_remote_media($payload, $token, $file);
        $media['source_url'] = $url;
        return $media;
    } catch (BMS_Api_Exception $e) {
        throw $e;
    } catch (RuntimeException $e) {
        throw new BMS_Api_Exception($e->getMessage(), 422, 'media_import_failed');
    } finally {
        if (is_array($download) && !empty($download['path']) && is_file((string)$download['path'])) {
            @unlink((string)$download['path']);
        }
    }
}


function bms_api_payload_media_reference_items(array $payload): array
{
    $items = [];

    $pushItem = static function (array $item) use (&$items): void {
        $items[] = $item;
    };

    if (isset($payload['media_items']) && is_array($payload['media_items'])) {
        foreach ($payload['media_items'] as $item) {
            if (is_array($item)) {
                $pushItem($item);
            }
        }
    }

    $singleId = (int)($payload['media_id'] ?? 0);
    if ($singleId > 0) {
        $pushItem(['media_id' => $singleId]);
    }
    if (isset($payload['media_ids']) && is_array($payload['media_ids'])) {
        foreach ($payload['media_ids'] as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $pushItem(['media_id' => $id]);
            }
        }
    }

    $singleUrl = trim((string)($payload['media_url'] ?? $payload['public_path'] ?? ''));
    if ($singleUrl !== '') {
        $pushItem(['media_url' => $singleUrl]);
    }
    foreach (['media_urls', 'public_paths'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            foreach ($payload[$key] as $url) {
                $url = trim((string)$url);
                if ($url !== '') {
                    $pushItem(['media_url' => $url]);
                }
            }
        }
    }

    return $items;
}

function bms_api_payload_media_uploads(array $payload): array
{
    $uploads = [];
    if (isset($payload['media_upload']) && is_array($payload['media_upload'])) {
        $uploads[] = $payload['media_upload'];
    }
    if (isset($payload['media_uploads']) && is_array($payload['media_uploads'])) {
        foreach ($payload['media_uploads'] as $entry) {
            if (is_array($entry)) {
                $uploads[] = $entry;
            }
        }
    }
    return $uploads;
}

function bms_api_payload_media_imports(array $payload): array
{
    $imports = [];
    if (isset($payload['media_import']) && is_array($payload['media_import'])) {
        $imports[] = $payload['media_import'];
    }
    if (isset($payload['media_imports']) && is_array($payload['media_imports'])) {
        foreach ($payload['media_imports'] as $entry) {
            if (is_array($entry)) {
                $imports[] = $entry;
            } elseif (is_string($entry) && trim($entry) !== '') {
                $imports[] = ['image_url' => trim($entry)];
            }
        }
    }
    foreach (['image_url', 'media_import_url', 'remote_image_url'] as $key) {
        if (isset($payload[$key]) && trim((string)$payload[$key]) !== '') {
            $imports[] = [
                'image_url' => trim((string)$payload[$key]),
                'alt_text' => bms_api_string_field($payload, ['alt_text', 'alt', 'description'], 255),
                'caption' => bms_api_string_field($payload, ['caption'], 500),
            ];
            break;
        }
    }
    return $imports;
}

function bms_api_payload_has_media_uploads(array $payload): bool
{
    return bms_api_payload_media_uploads($payload) !== [] || bms_api_payload_media_imports($payload) !== [];
}

function bms_api_media_embed_position(array $payload): string
{
    $position = strtolower(trim((string)($payload['media_position'] ?? $payload['embed_position'] ?? 'after')));
    return $position === 'before' ? 'before' : 'after';
}

function bms_api_media_reference_record(array $item): array
{
    $mediaId = (int)($item['media_id'] ?? 0);
    if ($mediaId > 0) {
        $media = bms_media_find($mediaId);
        if (!is_array($media) || bms_media_is_trashed($media)) {
            throw new BMS_Api_Exception('Referenced media ID was not found.', 422, 'media_reference_not_found');
        }
        return $media;
    }

    $value = trim((string)($item['media_url'] ?? $item['url'] ?? $item['public_path'] ?? ''));
    if ($value === '') {
        throw new BMS_Api_Exception('Media reference must include a media_id or media_url.', 422, 'invalid_media_reference');
    }

    $publicPath = str_starts_with($value, 'media/') ? $value : bms_media_public_relative_from_url($value);
    if ($publicPath === '') {
        throw new BMS_Api_Exception('Media URL must point to an existing Bonumark media item.', 422, 'invalid_media_reference');
    }

    $media = bms_media_find_by_public_path($publicPath);
    if (!is_array($media) || bms_media_is_trashed($media)) {
        throw new BMS_Api_Exception('Referenced media URL was not found.', 422, 'media_reference_not_found');
    }
    return $media;
}

function bms_api_media_embed_item_from_record(array $media, string $source = 'library', string $altOverride = '', string $captionOverride = ''): array
{
    $item = bms_api_media_response($media);
    if ($altOverride !== '') {
        $item['alt_text'] = $altOverride;
        $item['markdown'] = '![' . bms_api_markdown_alt_text($altOverride) . '](' . $item['url'] . ')';
    }
    if ($captionOverride !== '') {
        $item['caption'] = $captionOverride;
    }
    $item['source'] = $source;
    return $item;
}

function bms_api_embedded_media(array $payload, array $token): array
{
    $embedded = [];
    $seen = [];

    foreach (bms_api_payload_media_reference_items($payload) as $item) {
        $media = bms_api_media_reference_record($item);
        $embed = bms_api_media_embed_item_from_record(
            $media,
            'library',
            bms_api_string_field($item, ['alt_text', 'alt'], 255),
            bms_api_string_field($item, ['caption'], 500)
        );
        $key = 'id:' . (string)$embed['media_id'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $embedded[] = $embed;
        }
    }

    foreach (bms_api_payload_media_uploads($payload) as $uploadPayload) {
        $file = bms_api_uploaded_file_from_json_payload($uploadPayload);
        $tmp = !empty($file['_bms_api_tmp']) ? (string)($file['tmp_name'] ?? '') : '';
        try {
            $media = bms_api_create_remote_media($uploadPayload, $token, $file);
            $key = 'id:' . (string)($media['media_id'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $media['source'] = 'uploaded';
                $embedded[] = $media;
            }
        } finally {
            if ($tmp !== '' && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    foreach (bms_api_payload_media_imports($payload) as $importPayload) {
        $media = bms_api_import_remote_media($importPayload, $token);
        $key = 'id:' . (string)($media['media_id'] ?? '');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $media['source'] = 'imported';
            $embedded[] = $media;
        }
    }

    $markdownList = [];
    foreach ($embedded as $item) {
        $markdown = trim((string)($item['markdown'] ?? ''));
        if ($markdown !== '') {
            $caption = trim((string)($item['caption'] ?? ''));
            if ($caption !== '') {
                $markdown .= "\n\n" . $caption;
            }
            $markdownList[] = $markdown;
        }
    }

    return [
        'items' => $embedded,
        'markdown' => implode("\n\n", $markdownList),
        'position' => bms_api_media_embed_position($payload),
    ];
}

function bms_api_body_with_embedded_media(string $body, string $mediaMarkdown, string $position = 'after'): string
{
    $body = trim($body);
    $mediaMarkdown = trim($mediaMarkdown);
    if ($mediaMarkdown === '') {
        return $body;
    }
    if ($body === '') {
        return $mediaMarkdown;
    }
    return $position === 'before'
        ? $mediaMarkdown . "\n\n" . $body
        : $body . "\n\n" . $mediaMarkdown;
}

function bms_api_create_remote_stream_post(array $payload, array $token, string $targetStatus): array
{
    $targetStatus = $targetStatus === 'published' ? 'published' : 'draft';
    if ($targetStatus === 'published') {
        if (!bms_api_direct_publish_enabled()) {
            throw new BMS_Api_Exception('Remote direct publishing is disabled.', 403, 'remote_publish_disabled');
        }
        if (!bms_api_publish_confirmation_satisfied($payload)) {
            throw new BMS_Api_Exception('Remote publish confirmation is required.', 428, 'publish_confirmation_required');
        }
    }

    $body = bms_api_string_field($payload, ['content', 'body', 'body_markdown'], 0);
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = trim($body);

    $embeddedMedia = bms_api_embedded_media($payload, $token);
    $body = bms_api_body_with_embedded_media($body, (string)($embeddedMedia['markdown'] ?? ''), (string)($embeddedMedia['position'] ?? 'after'));
    if ($body === '') {
        throw new BMS_Api_Exception('Content or embedded media is required.', 422, 'content_required');
    }
    $bodyLength = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
    if ($bodyLength > 5000) {
        throw new BMS_Api_Exception('Remote posts with embedded media must be 5,000 characters or fewer.', 413, 'content_too_large');
    }

    $now = date('Y-m-d H:i:s');
    $date = bms_api_string_field($payload, ['date'], 20);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $title = bms_api_string_field($payload, ['title'], 190);
    $slugInput = bms_api_string_field($payload, ['slug'], 190);
    $description = bms_api_string_field($payload, ['description', 'excerpt'], 500);
    $seoTitle = bms_api_string_field($payload, ['seo_title'], 190);
    $robots = bms_api_string_field($payload, ['robots'], 80);

    $fields = bms_stream_prepare_metadata_fields([
        'title' => $title,
        'slug' => $slugInput,
        'status' => $targetStatus,
        'content_type' => 'stream',
        'date' => $date,
        'description' => $description,
        'category' => 'Stream',
        'tags' => [],
        'featured_media' => '',
        'stream_created_at' => $now,
        'seo_title' => $seoTitle,
        'robots' => $robots,
    ], $body, '');

    if ($slugInput !== '') {
        $fields['slug'] = bms_stream_unique_slug((string)$fields['slug']);
    }

    $raw = bms_build_markdown_document($fields, $body);
    if (strlen($raw) > 1024 * 1024 * 2) {
        throw new BMS_Api_Exception('Remote post is too large.', 413, 'post_too_large');
    }

    $page = bms_parse_markdown_string($raw);
    $filename = (string)$page['slug'] . '.md';
    $authorId = bms_api_token_author_id($token);
    $postId = 0;

    $section = $targetStatus === 'published' ? 'published' : 'drafts';

    if (function_exists('bms_upsert_database_content') && bms_database_content_columns_ready()) {
        $postId = bms_upsert_database_content($page, $section, $filename, $authorId);
    } elseif (function_exists('bms_sync_stream_metadata')) {
        bms_sync_stream_metadata($page, $section, $filename, $authorId);
        $found = bms_find_database_content_by_slug_status((string)$page['slug'], $targetStatus, 'stream');
        $postId = is_array($found) ? (int)($found['id'] ?? 0) : 0;
    }

    if ($postId < 1) {
        $found = function_exists('bms_find_database_content_by_slug_status') ? bms_find_database_content_by_slug_status((string)$page['slug'], $targetStatus, 'stream') : null;
        $postId = is_array($found) ? (int)($found['id'] ?? 0) : 0;
    }

    $editType = $targetStatus === 'published' ? 'published' : 'draft';
    $editUrl = bms_site_url('admin/edit.php?type=' . $editType . '&file=' . urlencode($filename));
    $publicUrl = $targetStatus === 'published' ? bms_site_url(bms_stream_relative_directory_for_post($page) . '/') : null;
    return [
        'post_id' => $postId,
        'status' => $targetStatus,
        'slug' => (string)$page['slug'],
        'title' => (string)$page['title'],
        'filename' => $filename,
        'edit_url' => $editUrl,
        'public_url' => $publicUrl,
        'embedded_media' => $embeddedMedia['items'] ?? [],
        'media_position' => (string)($embeddedMedia['position'] ?? 'after'),
    ];
}

function bms_api_create_remote_draft(array $payload, array $token): array
{
    return bms_api_create_remote_stream_post($payload, $token, 'draft');
}


function bms_api_handle_media_endpoint(): never
{
    $token = null;
    $payload = [];
    $requestId = '';
    $tmpFile = '';

    try {
        if (!bms_is_installed()) {
            throw new BMS_Api_Exception('Bonumark Stream is not installed.', 503, 'not_installed');
        }

        $method = bms_api_request_method();
        if ($method !== 'POST') {
            if (!headers_sent()) {
                header('Allow: POST');
            }
            throw new BMS_Api_Exception('Method not allowed.', 405, 'method_not_allowed');
        }

        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $payload = bms_api_read_json_body(max(2097152, (int)ceil(bms_current_media_upload_limit_bytes() * 1.6)));
        } else {
            $payload = [
                'alt_text' => (string)($_POST['alt_text'] ?? $_POST['alt'] ?? $_POST['description'] ?? ''),
                'caption' => (string)($_POST['caption'] ?? ''),
                'client_request_id' => (string)($_POST['client_request_id'] ?? $_POST['request_id'] ?? ''),
            ];
        }
        $requestId = bms_api_request_id_from_payload($payload);
        $token = bms_api_authenticate(['media:upload']);
        $file = bms_api_uploaded_file_from_request($payload);
        $tmpFile = !empty($file['_bms_api_tmp']) ? (string)($file['tmp_name'] ?? '') : '';
        $media = bms_api_create_remote_media($payload, $token, $file);
        $response = [
            'ok' => true,
            'media' => $media,
        ];
        bms_api_record_audit((int)($token['id'] ?? 0), 'remote_media_uploaded', true, 201, 'Remote media uploaded: ' . (string)($media['filename'] ?? ''), $requestId);
        bms_api_json_response($response, 201);
    } catch (Throwable $e) {
        $tokenId = is_array($token) ? (int)($token['id'] ?? 0) : 0;
        if ($e instanceof BMS_Api_Exception && !in_array($e->apiCode, ['missing_bearer_token', 'invalid_bearer_token', 'remote_posting_disabled', 'missing_scope', 'rate_limited'], true)) {
            bms_api_record_audit($tokenId > 0 ? $tokenId : null, 'remote_media_error', false, $e->statusCode, $e->apiCode, $requestId);
        }
        bms_api_error_response($e);
    } finally {
        if ($tmpFile !== '' && is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }
}


function bms_api_handle_media_import_endpoint(): never
{
    $token = null;
    $payload = [];
    $requestId = '';

    try {
        if (!bms_is_installed()) {
            throw new BMS_Api_Exception('Bonumark Stream is not installed.', 503, 'not_installed');
        }

        $method = bms_api_request_method();
        if ($method !== 'POST') {
            if (!headers_sent()) {
                header('Allow: POST');
            }
            throw new BMS_Api_Exception('Method not allowed.', 405, 'method_not_allowed');
        }

        $payload = bms_api_read_json_body(32768);
        $requestId = bms_api_request_id_from_payload($payload);
        $token = bms_api_authenticate(['media:upload']);
        $media = bms_api_import_remote_media($payload, $token);
        $response = [
            'ok' => true,
            'media' => $media,
        ];
        bms_api_record_audit((int)($token['id'] ?? 0), 'remote_media_imported', true, 201, 'Remote media imported: ' . (string)($media['filename'] ?? ''), $requestId);
        bms_api_json_response($response, 201);
    } catch (Throwable $e) {
        $tokenId = is_array($token) ? (int)($token['id'] ?? 0) : 0;
        if ($e instanceof BMS_Api_Exception && !in_array($e->apiCode, ['missing_bearer_token', 'invalid_bearer_token', 'remote_posting_disabled', 'missing_scope', 'rate_limited'], true)) {
            bms_api_record_audit($tokenId > 0 ? $tokenId : null, 'remote_media_import_error', false, $e->statusCode, $e->apiCode, $requestId);
        }
        bms_api_error_response($e);
    }
}

function bms_api_handle_status_endpoint(): never
{
    try {
        if (!bms_is_installed()) {
            throw new BMS_Api_Exception('Bonumark Stream is not installed.', 503, 'not_installed');
        }

        $method = bms_api_request_method();
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            if (!headers_sent()) {
                header('Allow: GET, HEAD');
            }
            throw new BMS_Api_Exception('Method not allowed.', 405, 'method_not_allowed');
        }

        $token = null;
        if (bms_api_authorization_token() !== '') {
            $token = bms_api_authenticate(['status:read']);
            bms_api_record_audit((int)($token['id'] ?? 0), 'status_check', true, 200, 'Authenticated status check.');
        }

        bms_api_json_response(bms_api_status_payload($token), 200);
    } catch (Throwable $e) {
        if ($e instanceof BMS_Api_Exception) {
            bms_api_record_audit(null, 'status_error', false, $e->statusCode, $e->apiCode);
        }
        bms_api_error_response($e);
    }
}

function bms_api_handle_stream_posts_endpoint(): never
{
    $token = null;
    $payload = [];
    $requestId = '';
    $idempotencyKey = '';
    $requestHash = '';
    $targetStatus = 'draft';

    try {
        if (!bms_is_installed()) {
            throw new BMS_Api_Exception('Bonumark Stream is not installed.', 503, 'not_installed');
        }

        $method = bms_api_request_method();
        if ($method !== 'POST') {
            if (!headers_sent()) {
                header('Allow: POST');
            }
            throw new BMS_Api_Exception('Method not allowed.', 405, 'method_not_allowed');
        }

        $payload = bms_api_read_json_body(max(2097152, (int)ceil(bms_current_media_upload_limit_bytes() * 1.6) + 524288));
        $requestId = bms_api_request_id_from_payload($payload);
        $targetStatus = bms_api_normalize_requested_status($payload);
        $requiredScopes = ['stream:draft'];
        if ($targetStatus === 'published') {
            $requiredScopes[] = 'stream:publish';
        }
        if (bms_api_payload_has_media_uploads($payload)) {
            $requiredScopes[] = 'media:upload';
        }

        $token = bms_api_authenticate($requiredScopes);
        $tokenId = (int)($token['id'] ?? 0);
        $idempotencyKey = bms_api_idempotency_key_from_payload($payload);
        $requestHash = bms_api_request_hash($payload);

        $stored = bms_api_idempotency_begin($tokenId, $idempotencyKey, $requestHash);
        if ($stored !== null) {
            bms_api_record_audit($tokenId, 'idempotency_replay', true, (int)$stored['status'], 'Idempotency replay returned stored response.', $requestId);
            bms_api_json_response($stored['payload'], (int)$stored['status']);
        }

        $post = bms_api_create_remote_stream_post($payload, $token, $targetStatus);
        $statusCode = 201;
        $event = $targetStatus === 'published' ? 'remote_post_published' : 'remote_draft_created';
        $message = $targetStatus === 'published'
            ? 'Remote post published: ' . (string)($post['slug'] ?? '')
            : 'Remote draft created: ' . (string)($post['slug'] ?? '');
        $response = [
            'ok' => true,
            'post' => $post,
        ];

        bms_api_idempotency_store($tokenId, $idempotencyKey, $requestHash, $response, $statusCode);
        bms_api_record_audit($tokenId, $event, true, $statusCode, $message, $requestId);

        bms_api_json_response($response, $statusCode);
    } catch (Throwable $e) {
        $tokenId = is_array($token) ? (int)($token['id'] ?? 0) : 0;
        if ($tokenId > 0 && $idempotencyKey !== '' && $requestHash !== '') {
            bms_api_idempotency_release($tokenId, $idempotencyKey, $requestHash);
        }
        if ($e instanceof BMS_Api_Exception) {
            $event = $targetStatus === 'published' ? 'remote_publish_error' : 'remote_draft_error';
            if (!in_array($e->apiCode, ['missing_bearer_token', 'invalid_bearer_token', 'remote_posting_disabled', 'missing_scope', 'rate_limited'], true)) {
                bms_api_record_audit($tokenId > 0 ? $tokenId : null, $event, false, $e->statusCode, $e->apiCode, $requestId);
            }
        }
        bms_api_error_response($e);
    }
}

function bms_api_status_payload(?array $token = null): array
{
    $payload = [
        'ok' => true,
        'api' => 'bonumark-stream',
        'version' => bms_version(),
        'remote_posting_enabled' => bms_api_enabled(),
        'authenticated' => false,
        'direct_publish_enabled' => bms_api_direct_publish_enabled(),
        'default_status' => bms_api_default_status(),
        'publish_confirmation_required' => bms_api_publish_confirmation_required(),
        'remote_media_upload_enabled' => bms_api_remote_media_upload_enabled(),
        'idempotency' => [
            'supported' => true,
            'header' => 'Idempotency-Key',
            'payload_fields' => ['idempotency_key', 'client_request_id'],
        ],
        'endpoints' => [
            'status' => bms_site_url('api/v1/status'),
            'stream_posts' => bms_site_url('api/v1/stream/posts'),
            'media' => bms_site_url('api/v1/media'),
            'media_import' => bms_site_url('api/v1/media/import'),
        ],
    ];
    if ($token) {
        $payload['authenticated'] = true;
        $payload['token'] = [
            'id' => (int)($token['id'] ?? 0),
            'name' => (string)($token['token_name'] ?? ''),
            'scopes' => bms_api_normalize_scopes($token['scopes'] ?? $token['scopes_json'] ?? []),
            'expires_at' => (string)($token['expires_at'] ?? ''),
        ];
    }
    return $payload;
}
