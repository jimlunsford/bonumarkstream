<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
if (getenv('BMS_DB_DANGER_RESET') !== '1') { exit("Disposable database opt-in required.\n"); }
$source = dirname(__DIR__);
$root = sys_get_temp_dir() . '/bms-connect-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_connect_ci_' . bin2hex(random_bytes(4)) . '_';
$count = 0;
$server = null;
function connect_assert(bool $ok, string $label): void { global $count; if (!$ok) { throw new RuntimeException($label); } $count++; }
function connect_reject(callable $operation, string $code): void {
    try { $operation(); } catch (BMS_Connect_Error $e) { connect_assert($e->resultCode === $code, 'Unexpected safe error classification'); return; }
    throw new RuntimeException('Expected rejection: ' . $code);
}
function connect_copy(string $from, string $to): void {
    mkdir($to, 0700, true);
    foreach (new DirectoryIterator($from) as $entry) {
        if ($entry->isDot() || in_array($entry->getFilename(), ['.git', 'services', 'config.php', 'installed.lock', 'tmp', 'backups'], true)) { continue; }
        if ($entry->isDir()) { connect_copy($entry->getPathname(), $to . '/' . $entry->getFilename()); }
        elseif ($entry->isFile()) { copy($entry->getPathname(), $to . '/' . $entry->getFilename()); }
    }
}
function connect_remove(string $root): void {
    if (!is_dir($root)) { return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
function connect_request_fixture(array $identity, array $changes = []): array {
    global $owner;
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $input = array_replace($identity + ['client_id' => 'bmc_test_client_0123456789', 'redirect_uri' => 'https://relay.example.com/callback', 'state' => bin2hex(random_bytes(32)), 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'scopes' => ['status:read', 'stream:read']], $changes);
    $request = bms_connect_create_request($owner, $input);
    return [$request, $verifier, $input];
}
function connect_code_fixture(array $identity, array $approved = ['status:read', 'stream:read']): array {
    global $owner;
    [$row, $verifier, $request] = connect_request_fixture($identity);
    $callback = bms_connect_decide($row['session_id'], $owner, true, $approved, 86400);
    return [$identity + ['code' => $callback['code'], 'code_verifier' => $verifier, 'client_id' => $request['client_id'], 'redirect_uri' => $request['redirect_uri']], $row];
}
function connect_http(string $url, string $method = 'GET', array $data = [], array $headers = [], bool $json = false): array {
    global $root;
    $h = curl_init($url); $received = [];
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5, CURLOPT_COOKIEFILE => $root . '/cookies', CURLOPT_COOKIEJAR => $root . '/cookies', CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$received) { $split = explode(':', $line, 2); if (count($split) === 2) { $received[strtolower(trim($split[0]))] = trim($split[1]); } return strlen($line); }]);
    if ($method !== 'GET') { curl_setopt($h, CURLOPT_CUSTOMREQUEST, $method); curl_setopt($h, CURLOPT_POSTFIELDS, $json ? json_encode($data) : http_build_query($data)); }
    $body = curl_exec($h); $status = curl_getinfo($h, CURLINFO_HTTP_CODE); curl_close($h);
    return ['status' => $status, 'headers' => $received, 'body' => (string)$body];
}
try {
    connect_copy($source, $root);
    $password = 'Test-' . bin2hex(random_bytes(18));
    $config = ['base_url' => 'https://example.test', 'base_path' => '', 'timezone' => 'UTC', 'security_salt' => bin2hex(random_bytes(32)), 'database' => ['host' => getenv('BMS_DB_HOST'), 'name' => getenv('BMS_DB_NAME'), 'user' => getenv('BMS_DB_USER'), 'password' => getenv('BMS_DB_PASS') ?: '', 'charset' => 'utf8mb4', 'prefix' => $prefix]];
    file_put_contents($root . '/_bonumark_stream/config.php', '<?php return ' . var_export($config, true) . ';');
    touch($root . '/_bonumark_stream/installed.lock');
    require_once $root . '/_bonumark_stream/app/connect.php';
    // Supported current pre-0030 baseline and a genuinely interrupted DDL retry.
    foreach (glob($root . '/_bonumark_stream/migrations/*.php') as $file) {
        if (str_contains($file, '/0030_')) { continue; }
        foreach (require $file as $sql) { bms_exec_migration_statement(bms_db(), $sql, $prefix); }
        bms_connect_query('INSERT IGNORE INTO ' . bms_table('migrations') . ' (migration, ran_at) VALUES (?, UTC_TIMESTAMP())', [basename($file, '.php')]);
    }
    bms_db_insert_initial_data(['site_name' => 'Connect test', 'site_tagline' => '', 'timezone' => 'UTC', 'base_url' => 'https://example.test', 'base_path' => ''], ['username' => 'connectowner', 'display_name' => 'Connect Owner', 'email' => 'owner@example.test', 'password' => $password]);
    $owner = (int)bms_connect_query('SELECT id FROM ' . bms_table('users') . ' WHERE username = ?', ['connectowner'])->fetchColumn();
    $ownerBefore = bms_connect_query('SELECT * FROM ' . bms_table('users') . ' WHERE id = ?', [$owner])->fetch();
    $migration = require $root . '/_bonumark_stream/migrations/0030_connect_authorization_foundation.php';
    foreach (array_slice($migration, 0, 4) as $sql) { bms_exec_migration_statement(bms_db(), $sql, $prefix); }
    $ran = bms_run_migrations('0.8.2');
    connect_assert($ran === ['0030_connect_authorization_foundation'], 'Interrupted upgrade resumes only 0030');
    connect_assert(bms_run_migrations('0.8.2') === [], 'Migration retry is idempotent');
    foreach ($migration as $sql) { bms_exec_migration_statement(bms_db(), $sql, $prefix); }
    connect_assert($ownerBefore === bms_connect_query('SELECT * FROM ' . bms_table('users') . ' WHERE id = ?', [$owner])->fetch(), 'Migration preserves exact owner row');
    connect_reject(fn() => bms_connect_discovery(), 'connect_unavailable');
    connect_assert(bms_connect_setting('connect_site_id', '') === '', 'Anonymous discovery never initializes identity');
    $identity = bms_connect_initialize($owner);
    connect_assert(bms_connect_is_uuid($identity['site_id']), 'Secure UUIDv4 identity');
    connect_assert(bms_connect_initialize($owner) === $identity, 'Explicit initialization preserves identity');
    bms_connect_provision_client($owner, ['client_id' => 'bmc_test_client_0123456789', 'display_name' => 'Trusted Connect', 'callbacks' => ['https://relay.example.com/callback']]);
    connect_reject(fn() => bms_connect_provision_client($owner, ['client_id' => 'wildcard_0123456789', 'display_name' => 'Untrusted', 'callbacks' => ['https://relay.example.com/*']]), 'invalid_redirect_uri');
    foreach (['http://example.com', 'https://user@example.com', 'https://127.0.0.1', 'https://[::1]', 'https://example.com:444', 'https://example.com/a/../b', 'https://example.com/%2f', 'https://example.com/a?b', 'https://example.com/a#b', 'https://example.com/a\\b', 'https://localhost', 'https://host.local', 'https://example.com//b'] as $url) { connect_reject(fn() => bms_connect_address($url), 'invalid_authorization_request'); }
    connect_assert(bms_connect_address('https://EXAMPLE.com.:443/Blog/') === ['canonical_origin' => 'https://example.com', 'base_path' => '/Blog'], 'Origin normalization');
    if (function_exists('idn_to_ascii')) { connect_assert(bms_connect_address('https://bücher.example')['canonical_origin'] === 'https://xn--bcher-kva.example', 'IDNA normalization'); }
    foreach ([['scopes' => ['stream:publish']], ['scopes' => ['media:read']], ['code_challenge_method' => 'plain'], ['client_id' => 'unknown_0123456789'], ['redirect_uri' => 'https://other.example/callback'], ['site_id' => bms_connect_uuid()]] as $change) {
        $error = isset($change['scopes']) ? 'invalid_scope' : (isset($change['client_id']) ? 'invalid_client' : (isset($change['redirect_uri']) ? 'invalid_redirect_uri' : 'invalid_authorization_request'));
        connect_reject(fn() => connect_request_fixture($identity, $change), $error);
    }
    [$pending] = connect_request_fixture($identity);
    connect_reject(fn() => bms_connect_decide($pending['session_id'], $owner + 99, true, [], 86400), 'access_denied');
    $denied = bms_connect_decide($pending['session_id'], $owner, false, [], 86400);
    connect_assert($denied['error'] === 'access_denied' && !isset($denied['code']), 'Denial issues no code');
    [$expired] = connect_request_fixture($identity);
    bms_connect_query('UPDATE ' . bms_table('connect_sessions') . ' SET expires_at = ? WHERE session_id = ?', [time() - 1, $expired['session_id']]);
    connect_reject(fn() => bms_connect_decide($expired['session_id'], $owner, true, [], 86400), 'authorization_expired');
    [$exchange] = connect_code_fixture($identity);
    foreach (['code' => bin2hex(random_bytes(32)), 'code_verifier' => str_repeat('x', 43), 'client_id' => 'wrong_0123456789', 'redirect_uri' => 'https://other.example/callback', 'site_id' => bms_connect_uuid(), 'base_path' => '/other'] as $key => $value) { connect_reject(fn() => bms_connect_exchange(array_replace($exchange, [$key => $value])), 'invalid_grant'); }
    // Issuance audit failure rolls back consumption, activation and credential insert.
    bms_connect_query('RENAME TABLE ' . bms_table('connect_audit') . ' TO ' . bms_table('connect_audit_hold'));
    try { bms_connect_exchange($exchange); throw new RuntimeException('Audit failure issued authority'); } catch (PDOException $e) { $count++; }
    bms_connect_query('RENAME TABLE ' . bms_table('connect_audit_hold') . ' TO ' . bms_table('connect_audit'));
    $issued = bms_connect_exchange($exchange);
    connect_assert(preg_match('/^bmsc_[a-f0-9]{64}$/D', $issued['access_token']) === 1, 'Credential format');
    connect_reject(fn() => bms_connect_exchange($exchange), 'invalid_grant');
    $stored = json_encode(bms_connect_query('SELECT * FROM ' . bms_table('connect_credentials'))->fetchAll());
    connect_assert(!str_contains($stored, $issued['access_token']), 'Site stores hash only');
    $health = fn(array $grant): array => bms_connect_grant_payload($grant);
    $authorize = fn(array $required = ['status:read']) => bms_connect_authorized($issued['access_token'], $issued['client_id'], $required, $health);
    connect_assert($authorize()['grant_id'] === $issued['grant_id'], 'Exact live grant health');
    bms_connect_query('UPDATE ' . bms_table('connect_credentials') . ' SET expires_at = ? WHERE grant_id = ?', [time() - 1, $issued['grant_id']]);
    connect_reject(fn() => $authorize(), 'invalid_bearer_token');
    bms_connect_query('UPDATE ' . bms_table('connect_credentials') . ' SET expires_at = ? WHERE grant_id = ?', [$issued['expires_at'], $issued['grant_id']]);
    // Future adapter mutations retain the authority lock and roll back together.
    try {
        bms_connect_authorized($issued['access_token'], $issued['client_id'], ['status:read'], static function (): void {
            bms_set_setting('connect_fixture_mutation', 'must_rollback');
            throw new RuntimeException('fixture rollback');
        });
    } catch (RuntimeException $e) { connect_assert($e->getMessage() === 'fixture rollback', 'Expected adapter rollback'); }
    connect_assert(bms_connect_setting('connect_fixture_mutation', '') === '', 'Failed operation rolls back its mutation');
    bms_db()->beginTransaction();
    $authorize();
    $p = proc_open([PHP_BINARY, $source . '/scripts/connect-test-worker.php', $root], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['operation' => 'revoke', 'owner' => $owner, 'grant_id' => $issued['grant_id']])); fclose($pipes[0]);
    $blocked = trim(stream_get_contents($pipes[1])); fclose($pipes[1]); fclose($pipes[2]);
    connect_assert(proc_close($p) === 0 && $blocked === 'lock_blocked', 'Concurrent revocation waits for caller-owned authority transaction');
    connect_assert(bms_db()->inTransaction(), 'Nested authorization retains caller transaction');
    bms_db()->commit();
    connect_reject(fn() => bms_connect_authorized('bmsrt_' . bin2hex(random_bytes(32)), $issued['client_id'], [], $health), 'invalid_bearer_token');
    connect_reject(fn() => bms_connect_authorized($issued['access_token'], 'wrong_client', [], $health), 'invalid_bearer_token');
    connect_reject(fn() => $authorize(['media:upload']), 'missing_scope');
    bms_connect_manage($owner, 'reduce', $issued['grant_id'], ['status:read']);
    connect_reject(fn() => $authorize(['stream:read']), 'missing_scope');
    bms_connect_query('INSERT INTO ' . bms_table('users') . ' (username, display_name, password_hash, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['otheradmin', 'Other Admin', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'admin', 'active']);
    bms_connect_query('UPDATE ' . bms_table('users') . ' SET status = ? WHERE id = ?', ['inactive', $owner]);
    connect_reject(fn() => $authorize(), 'invalid_bearer_token');
    connect_assert((int)bms_connect_query('SELECT COUNT(*) FROM ' . bms_table('users') . ' WHERE role = ? AND status = ?', ['admin', 'active'])->fetchColumn() > 0, 'Another active Admin never becomes the grant owner');
    bms_connect_query('UPDATE ' . bms_table('users') . ' SET status = ? WHERE id = ?', ['active', $owner]);
    bms_connect_manage($owner, 'reduce', $issued['grant_id'], []);
    connect_reject(fn() => $authorize(), 'missing_scope');
    bms_connect_manage($owner, 'revoke', $issued['grant_id']);
    connect_reject(fn() => $authorize(), 'invalid_bearer_token');
    [$empty] = connect_code_fixture($identity, []);
    $zero = bms_connect_exchange($empty);
    connect_reject(fn() => bms_connect_authorized($zero['access_token'], $zero['client_id'], ['status:read'], $health), 'missing_scope');
    [$codeExpired] = connect_code_fixture($identity);
    bms_connect_query('UPDATE ' . bms_table('connect_codes') . ' SET expires_at = ? WHERE code_hash = ?', [time() - 1, bms_connect_hash('code', $codeExpired['code'])]);
    connect_reject(fn() => bms_connect_exchange($codeExpired), 'invalid_grant');
    [$race] = connect_code_fixture($identity);
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $p = proc_open([PHP_BINARY, $source . '/scripts/connect-test-worker.php', $root], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode($race)); fclose($pipes[0]); $workers[] = [$p, $pipes];
    }
    $outcomes = [];
    foreach ($workers as [$p, $pipes]) { $outcomes[] = trim(stream_get_contents($pipes[1])); fclose($pipes[1]); fclose($pipes[2]); connect_assert(proc_close($p) === 0, 'Parallel exchange process'); }
    sort($outcomes); connect_assert($outcomes === ['invalid_grant', 'issued'], 'Parallel exchange issues exactly once');
    [$originCode] = connect_code_fixture($identity); $originToken = bms_connect_exchange($originCode);
    bms_set_setting('base_path', '/moved');
    connect_reject(fn() => bms_connect_authorized($originToken['access_token'], $originToken['client_id'], ['status:read'], $health), 'site_origin_changed');
    bms_set_setting('base_path', '');
    connect_reject(fn() => bms_connect_authorized($originToken['access_token'], $originToken['client_id'], ['status:read'], $health), 'invalid_bearer_token');
    [$recoveryCode] = connect_code_fixture($identity); [$pendingRecovery] = connect_request_fixture($identity);
    bms_connect_manage($owner, 'recovery');
    connect_assert(bms_connect_identity(false) === $identity, 'Recovery preserves logical identity');
    bms_connect_initialize($owner);
    connect_reject(fn() => bms_connect_exchange($recoveryCode), 'invalid_grant');
    connect_reject(fn() => bms_connect_decide($pendingRecovery['session_id'], $owner, true, [], 86400), 'authorization_expired');
    connect_reject(fn() => bms_connect_authorized($zero['access_token'], $zero['client_id'], [], $health), 'invalid_bearer_token');
    connect_assert($ownerBefore === bms_connect_query('SELECT * FROM ' . bms_table('users') . ' WHERE id = ?', [$owner])->fetch(), 'Recovery retains owner');
    // HTTP surfaces: direct site login, immutable GET, CSRF, exchange and revocation.
    $port = random_int(42000, 49000);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes);
    fclose($pipes[0]); $base = 'http://127.0.0.1:' . $port;
    for ($i = 0; $i < 50; $i++) { $login = connect_http($base . '/admin/login.php'); if ($login['status']) { break; } usleep(100000); }
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $match);
    connect_assert(isset($match[1]), 'Normal site login is available without relay');
    $login = connect_http($base . '/admin/login.php', 'POST', ['csrf_token' => $match[1], 'username' => 'connectowner', 'password' => $password]);
    connect_assert($login['status'] === 302, 'Normal owner login succeeds');
    [$httpRequest, $verifier, $r] = connect_request_fixture($identity);
    $approval = connect_http($base . '/admin/connect-authorize.php?request=' . $httpRequest['session_id'] . '&decision=approve');
    connect_assert($approval['status'] === 200 && str_contains($approval['body'], 'Trusted Connect'), 'Trusted identity on approval GET');
    connect_assert(($approval['headers']['cache-control'] ?? '') === 'no-store' && ($approval['headers']['referrer-policy'] ?? '') === 'no-referrer', 'Authorization privacy headers');
    $approvalPolicy = "default-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self' https://relay.example.com/callback; frame-ancestors 'none'; object-src 'none'";
    connect_assert(($approval['headers']['content-security-policy'] ?? '') === $approvalPolicy, 'Rendered approval form allows only its trusted callback alongside self');
    $injected = connect_http($base . '/admin/connect-authorize.php?request=' . $httpRequest['session_id'] . '&redirect_uri=https%3A%2F%2Fforeign.example%2Fcallback');
    connect_assert(($injected['headers']['content-security-policy'] ?? '') === $approvalPolicy, 'Browser callback parameter cannot alter stored request policy');
    connect_assert(bms_connect_query('SELECT status FROM ' . bms_table('connect_sessions') . ' WHERE session_id = ?', [$httpRequest['session_id']])->fetchColumn() === 'pending', 'GET never approves');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $approval['body'], $match);
    $csrf = $match[1] ?? '';
    $posted = ['request' => $httpRequest['session_id'], 'decision' => 'approve', 'scopes' => ['status:read'], 'lifetime' => 86400];
    connect_assert(connect_http($base . '/admin/connect-authorize.php', 'POST', $posted)['status'] === 403, 'Approval rejects missing CSRF');
    $approved = connect_http($base . '/admin/connect-authorize.php', 'POST', $posted + ['csrf_token' => $csrf]);
    connect_assert($approved['status'] === 303, 'Explicit approval redirects');
    $completed = connect_http($base . '/admin/connect-authorize.php?request=' . $httpRequest['session_id']);
    connect_assert($completed['status'] === 400 && ($completed['headers']['content-security-policy'] ?? '') === str_replace(' https://relay.example.com/callback', '', $approvalPolicy), 'Completed request fails closed with default CSP');
    parse_str(parse_url($approved['headers']['location'] ?? '', PHP_URL_QUERY) ?: '', $callback);
    $exchangeInput = $identity + ['code' => $callback['code'] ?? '', 'code_verifier' => $verifier, 'client_id' => $r['client_id'], 'redirect_uri' => $r['redirect_uri']];
    $tokenResponse = connect_http($base . '/api/connect/v1/token.php', 'POST', $exchangeInput, ['Content-Type: application/json'], true);
    $token = json_decode($tokenResponse['body'], true);
    connect_assert($tokenResponse['status'] === 200 && isset($token['access_token']), 'HTTP code exchange');
    $authHeaders = ['Authorization: Bearer ' . $token['access_token'], 'X-Bonumark-Client: ' . $token['client_id']];
    connect_assert(connect_http($base . '/api/connect/v1/status.php', 'GET', [], $authHeaders)['status'] === 200, 'Authenticated HTTP health');
    $manage = connect_http($base . '/admin/connected-applications.php');
    connect_assert($manage['status'] === 200 && !str_contains($manage['body'], $token['access_token']), 'Connected Applications hides bearer');
    connect_assert(connect_http($base . '/admin/connected-applications.php', 'POST', ['action' => 'revoke', 'grant_id' => $token['grant_id'], 'csrf_token' => $csrf])['status'] === 302, 'Local revoke works without relay');
    connect_assert(connect_http($base . '/api/connect/v1/status.php', 'GET', [], $authHeaders)['status'] === 401, 'Revocation immediately fails HTTP health');
    connect_assert(connect_http($base . '/index.php')['status'] === 200, 'Public site survives relay absence');
    connect_assert(connect_http($base . '/api/connect/v1/token.php')['status'] === 405, 'GET cannot exchange a code');
    $discovery = json_decode(connect_http($base . '/api/connect/v1/discovery.php')['body'], true);
    connect_assert(isset($discovery['site_id']) && !isset($discovery['owner_id'], $discovery['grants'], $discovery['access_token']), 'Discovery contains no owner or authority');
    $audit = json_encode(bms_connect_query('SELECT * FROM ' . bms_table('connect_audit'))->fetchAll());
    foreach ([$token['access_token'], $callback['code'], $verifier, $password] as $secret) { connect_assert(!str_contains($audit, $secret), 'Audit excludes secrets'); }
    bms_connect_manage($owner, 'clone');
    connect_assert(bms_connect_identity(false)['site_id'] !== $identity['site_id'], 'Independent clone receives new site identity');
    connect_assert(bms_connect_setting('connect_enabled', '1') === '0', 'Clone requires deliberate reactivation');
    echo 'Connect site authorization tests passed: ' . $count . " assertions.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (function_exists('bms_db')) {
        try { foreach (bms_db()->query('SHOW TABLES LIKE ' . bms_db()->quote($prefix . '%'))->fetchAll(PDO::FETCH_COLUMN) as $table) { if (str_starts_with($table, $prefix)) { bms_db()->exec('DROP TABLE `' . $table . '`'); } } } catch (Throwable $e) {}
    }
    connect_remove($root);
}
