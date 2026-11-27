<?php
require_once __DIR__ . '/database.php';

if (!defined('BMS_STATELESS_PROTOCOL_REQUEST') || BMS_STATELESS_PROTOCOL_REQUEST !== true) {
    bms_start_secure_session();
}
bms_send_security_headers();

function bms_find_user_by_username(string $username): ?array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE username = :username AND status = :status LIMIT 1');
    $stmt->execute([
        'username' => bms_normalize_username($username),
        'status' => 'active',
    ]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function bms_find_user_by_username_any(string $username): ?array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => bms_normalize_username($username)]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function bms_find_user_by_id(int|string $id): ?array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE id = :id AND status = :status LIMIT 1');
    $stmt->execute(['id' => (int)$id, 'status' => 'active']);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function bms_find_user_by_id_any(int|string $id): ?array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int)$id]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

/**
 * Columns of the users table, read once per request. Code that writes a column
 * added by a later migration checks here first, so sign-in and the upgrade
 * screens keep working while an interrupted upgrade waits to be resumed.
 *
 * @return array<string,true>
 */
function bms_users_columns(): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }
    try {
        $found = [];
        foreach (bms_db()->query('SHOW COLUMNS FROM ' . bms_table('users'))->fetchAll() as $row) {
            $found[(string)($row['Field'] ?? '')] = true;
        }
        $columns = $found;
        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function bms_users_has_column(string $column): bool
{
    return isset(bms_users_columns()[$column]);
}

function bms_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * True when another account already uses this address: either its unique
 * claim, or an unclaimed address that earlier versions allowed to be shared.
 */
function bms_email_in_use(string $email, int $exceptUserId = 0): bool
{
    $email = bms_normalize_email($email);
    if ($email === '') {
        return false;
    }
    $sql = 'SELECT id FROM ' . bms_table('users') . ' WHERE id <> :id AND (email = :email';
    $params = ['id' => $exceptUserId, 'email' => $email];
    if (bms_users_has_column('email_normalized')) {
        $sql .= ' OR email_normalized = :claim';
        $params['claim'] = $email;
    }
    $stmt = bms_db()->prepare($sql . ') LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetchColumn() !== false;
}

function bms_email_in_use_message(): string
{
    return 'That email address is already in use by another account.';
}

function bms_is_duplicate_email_claim_error(Throwable $e): bool
{
    return $e instanceof PDOException
        && (string)$e->getCode() === '23000'
        && str_contains((string)$e->getMessage(), 'email_normalized');
}

/**
 * Claim every address that exactly one account holds and nobody has claimed
 * yet. Addresses shared by several accounts stay unclaimed until the owner
 * resolves them; System Check lists those accounts.
 */
function bms_users_claim_unique_emails(): void
{
    if (!bms_users_has_column('email_normalized')) {
        return;
    }
    $table = bms_table('users');
    try {
        bms_db()->exec('UPDATE ' . $table . ' u INNER JOIN (SELECT LOWER(TRIM(email)) AS normalized_email FROM ' . $table . " WHERE TRIM(email) <> '' GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) = 1) single ON single.normalized_email = LOWER(TRIM(u.email)) LEFT JOIN (SELECT email_normalized AS claimed_email FROM " . $table . ' WHERE email_normalized IS NOT NULL GROUP BY email_normalized) claimed ON claimed.claimed_email = single.normalized_email SET u.email_normalized = single.normalized_email WHERE u.email_normalized IS NULL AND claimed.claimed_email IS NULL');
    } catch (Throwable $e) {
        // Claims are an index over the stored addresses; the next account
        // change retries. The email check above still blocks new duplicates.
        error_log('Bonumark Stream email claim refresh failed: ' . $e->getMessage());
    }
}

/**
 * Groups of accounts that share one email address, for System Check.
 *
 * @return list<list<string>> usernames per shared address
 */
function bms_users_shared_email_groups(): array
{
    $table = bms_table('users');
    $stmt = bms_db()->query('SELECT LOWER(TRIM(email)) AS normalized_email, username FROM ' . $table . " WHERE TRIM(email) <> '' AND LOWER(TRIM(email)) IN (SELECT shared.normalized_email FROM (SELECT LOWER(TRIM(email)) AS normalized_email FROM " . $table . " WHERE TRIM(email) <> '' GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1) shared) ORDER BY normalized_email ASC, id ASC");
    $groups = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $groups[(string)$row['normalized_email']][] = (string)$row['username'];
    }
    return array_values($groups);
}

/** SQL assignments that cancel a pending email change, when supported. */
function bms_users_pending_email_clear_sql(): string
{
    return bms_users_has_column('pending_email')
        ? ', pending_email = NULL, pending_email_token_hash = NULL, pending_email_expires_at = NULL'
        : '';
}

function bms_guest_user(): array
{
    return [
        'id' => 0,
        'username' => '',
        'display_name' => 'Guest',
        'email' => '',
        'role' => 'guest',
        'status' => 'guest',
    ];
}

function bms_remember_login_enabled(): bool
{
    return (string)bms_setting_or_config('remember_login_enabled', '1') === '1';
}

function bms_remember_login_days(): int
{
    $days = (int)bms_setting_or_config('remember_login_days', '30');
    if ($days < 1) {
        return 1;
    }
    if ($days > 90) {
        return 90;
    }
    return $days;
}

function bms_remember_cookie_name(): string
{
    return 'bms_remember';
}

function bms_remember_cookie_path(): string
{
    $base = function_exists('bms_base_path') ? bms_base_path() : '';
    return $base !== '' ? rtrim($base, '/') . '/' : '/';
}

function bms_remember_user_agent_hash(): string
{
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    return hash('sha256', $agent . '|' . (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_set_remember_cookie(string $selector, string $validator, int $expires): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(bms_remember_cookie_name(), $selector . ':' . $validator, [
        'expires' => $expires,
        'path' => bms_remember_cookie_path(),
        'secure' => bms_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[bms_remember_cookie_name()] = $selector . ':' . $validator;
}

function bms_clear_remember_cookie(): void
{
    if (!headers_sent()) {
        setcookie(bms_remember_cookie_name(), '', [
            'expires' => time() - 3600,
            'path' => bms_remember_cookie_path(),
            'secure' => bms_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE[bms_remember_cookie_name()]);
}

function bms_delete_expired_remember_tokens(): void
{
    try {
        $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('remember_tokens') . ' WHERE expires_at < NOW()');
        $stmt->execute();
    } catch (Throwable $e) {
        // Persistent-login cleanup should not block normal requests.
    }
}

function bms_revoke_current_remember_token(): void
{
    $cookie = (string)($_COOKIE[bms_remember_cookie_name()] ?? '');
    if ($cookie !== '') {
        $parts = explode(':', $cookie, 2);
        $selector = $parts[0] ?? '';
        if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
            try {
                $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('remember_tokens') . ' WHERE selector = :selector');
                $stmt->execute(['selector' => $selector]);
            } catch (Throwable $e) {
                // Token cleanup should not block logout.
            }
        }
    }
    bms_clear_remember_cookie();
}

function bms_revoke_user_remember_tokens(int $userId): void
{
    if ($userId < 1) {
        return;
    }
    try {
        $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('remember_tokens') . ' WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    } catch (Throwable $e) {
        // Token cleanup should not block the account action that requested it.
    }
    if ((int)($_SESSION['bms_user_id'] ?? 0) === $userId) {
        bms_clear_remember_cookie();
    }
}

function bms_remember_expires_at_utc(int $unixTimestamp): string
{
    return gmdate('Y-m-d H:i:s', $unixTimestamp);
}

function bms_create_remember_token(int $userId): void
{
    if ($userId < 1 || !bms_remember_login_enabled()) {
        bms_clear_remember_cookie();
        return;
    }

    bms_delete_expired_remember_tokens();

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + (bms_remember_login_days() * 86400);

    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('remember_tokens') . ' (user_id, selector, token_hash, user_agent_hash, expires_at, created_at) VALUES (:user_id, :selector, :token_hash, :user_agent_hash, :expires_at, NOW())');
    $stmt->execute([
        'user_id' => $userId,
        'selector' => $selector,
        'token_hash' => hash('sha256', $validator),
        'user_agent_hash' => bms_remember_user_agent_hash(),
        'expires_at' => bms_remember_expires_at_utc($expires),
    ]);

    bms_set_remember_cookie($selector, $validator, $expires);
}

function bms_restore_remembered_login(): bool
{
    if (!empty($_SESSION['bms_logged_in']) && !empty($_SESSION['bms_user_id'])) {
        return true;
    }
    if (!bms_remember_login_enabled()) {
        bms_clear_remember_cookie();
        return false;
    }

    $cookie = (string)($_COOKIE[bms_remember_cookie_name()] ?? '');
    if ($cookie === '') {
        return false;
    }

    $parts = explode(':', $cookie, 2);
    $selector = $parts[0] ?? '';
    $validator = $parts[1] ?? '';
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        bms_clear_remember_cookie();
        return false;
    }

    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('remember_tokens') . ' WHERE selector = :selector AND expires_at >= NOW() LIMIT 1');
        $stmt->execute(['selector' => $selector]);
        $row = $stmt->fetch();
        if (!is_array($row) || !hash_equals((string)($row['token_hash'] ?? ''), hash('sha256', $validator))) {
            bms_revoke_current_remember_token();
            return false;
        }
        if ((string)($row['user_agent_hash'] ?? '') !== bms_remember_user_agent_hash()) {
            bms_revoke_current_remember_token();
            return false;
        }

        $user = bms_find_user_by_id((int)($row['user_id'] ?? 0));
        if (!$user) {
            bms_revoke_current_remember_token();
            return false;
        }

        session_regenerate_id(true);
        bms_bind_login_session($user);

        $newValidator = bin2hex(random_bytes(32));
        $expires = time() + (bms_remember_login_days() * 86400);
        $update = bms_db()->prepare('UPDATE ' . bms_table('remember_tokens') . ' SET token_hash = :token_hash, expires_at = :expires_at, last_used_at = NOW() WHERE selector = :selector');
        $update->execute([
            'token_hash' => hash('sha256', $newValidator),
            'expires_at' => bms_remember_expires_at_utc($expires),
            'selector' => $selector,
        ]);
        bms_set_remember_cookie($selector, $newValidator, $expires);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function bms_clear_login_session(): void
{
    unset($_SESSION['bms_logged_in'], $_SESSION['bms_user_id'], $_SESSION['bms_session_version']);
}

/**
 * The account's credential generation. It starts at 1 and changes whenever a
 * password is changed or reset, or the owner signs out other devices. Before
 * the migration that adds it, every account reads as 1.
 */
function bms_user_session_version(array $user): int
{
    return max(1, (int)($user['session_version'] ?? 1));
}

function bms_bind_login_session(array $user): void
{
    $_SESSION['bms_logged_in'] = true;
    $_SESSION['bms_user_id'] = (int)$user['id'];
    $_SESSION['bms_session_version'] = bms_user_session_version($user);
}

function bms_session_version_matches(array $user): bool
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return false;
    }
    $expected = bms_user_session_version($user);
    if (!array_key_exists('bms_session_version', $_SESSION)) {
        // A session signed in before sessions carried a version stays valid
        // only while the account is still on its first credential generation.
        if ($expected !== 1) {
            return false;
        }
        $_SESSION['bms_session_version'] = 1;
        return true;
    }
    return (int)$_SESSION['bms_session_version'] === $expected;
}

/**
 * The signed-in account for this session, or null. A session whose account
 * is no longer active, or whose credentials changed after it signed in, is
 * signed out here and its remembered-device token is revoked.
 */
function bms_session_user(): ?array
{
    bms_restore_remembered_login();
    $sessionId = $_SESSION['bms_user_id'] ?? null;
    if (empty($_SESSION['bms_logged_in']) || !is_numeric($sessionId) || (int)$sessionId < 1) {
        return null;
    }
    $user = bms_find_user_by_id((int)$sessionId);
    if ($user && bms_session_version_matches($user)) {
        return $user;
    }
    bms_clear_login_session();
    bms_revoke_current_remember_token();
    return null;
}

function bms_current_user(): array
{
    return bms_session_user() ?? bms_guest_user();
}

/**
 * Move an account to a new credential generation. Every session signed in
 * under the old generation is rejected on its next request. Returns the new
 * value, or null when the database has not been migrated yet.
 */
function bms_bump_user_session_version(int $userId): ?int
{
    if ($userId < 1 || !bms_users_has_column('session_version')) {
        return null;
    }
    $pdo = bms_db();
    $stmt = $pdo->prepare('UPDATE ' . bms_table('users') . ' SET session_version = session_version + 1 WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $stmt = $pdo->prepare('SELECT session_version FROM ' . bms_table('users') . ' WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $version = $stmt->fetchColumn();
    return $version === false ? null : max(1, (int)$version);
}

/**
 * Keep the session that made a credential change signed in under the new
 * generation. Its session ID is replaced, so an older copy of this session's
 * cookie is signed out as well.
 */
function bms_rebind_current_session(int $userId, ?int $version): void
{
    if ($version === null || empty($_SESSION['bms_logged_in']) || (int)($_SESSION['bms_user_id'] ?? 0) !== $userId) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['bms_session_version'] = $version;
}

/** The selector of this browser's remembered-device cookie, or ''. */
function bms_current_remember_selector(): string
{
    $parts = explode(':', (string)($_COOKIE[bms_remember_cookie_name()] ?? ''), 2);
    $selector = $parts[0] ?? '';
    return preg_match('/^[a-f0-9]{24}$/', $selector) ? $selector : '';
}

/**
 * Sign out every other session and remembered device of the current account.
 * This browser stays signed in and keeps its own remembered-device token.
 */
function bms_sign_out_other_devices(): void
{
    $user = bms_current_user();
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        throw new RuntimeException('Sign in before signing out other devices.');
    }
    if (!bms_users_has_column('session_version')) {
        throw new RuntimeException('Signing out other devices is available after the pending database upgrade completes.');
    }

    $pdo = bms_db();
    $startedTransaction = !$pdo->inTransaction() && $pdo->beginTransaction();
    try {
        $sql = 'DELETE FROM ' . bms_table('remember_tokens') . ' WHERE user_id = :user_id';
        $params = ['user_id' => $userId];
        $selector = bms_current_remember_selector();
        if ($selector !== '') {
            $sql .= ' AND selector <> :selector';
            $params['selector'] = $selector;
        }
        $pdo->prepare($sql)->execute($params);
        $version = bms_bump_user_session_version($userId);
        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    bms_rebind_current_session($userId, $version);
}

/**
 * The network a sign-in attempt comes from. IPv4 addresses are used as they
 * are. An IPv6 address counts as its /64 network, because one subscriber
 * usually controls a whole /64.
 */
function bms_login_network_address(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $packed = $ip !== '' ? @inet_pton($ip) : false;
    if (!is_string($packed)) {
        return $ip !== '' ? $ip : 'unknown';
    }
    if (strlen($packed) === 16) {
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string)inet_ntop(substr($packed, 12));
        }
        return (string)inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return (string)inet_ntop($packed);
}

function bms_login_ip_hash(): string
{
    return hash('sha256', bms_login_network_address() . '|' . (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

/**
 * Sign-in throttling. Failures are counted per username and network, and per
 * network across all usernames, over a sliding window. Past the free
 * failures, each further attempt waits a delay that doubles with every
 * failure, up to the cap. Nothing ever locks a username by itself, so
 * failures from one network never block the same account elsewhere.
 */
function bms_login_throttle_policy(): array
{
    return [
        'window_seconds' => 3600,
        'pair_free_failures' => 5,
        'network_free_failures' => 20,
        'base_delay_seconds' => 1,
        'max_delay_seconds' => 900,
    ];
}

function bms_login_throttle_delay(int $failures, int $freeFailures, array $policy): int
{
    if ($failures < $freeFailures) {
        return 0;
    }
    $steps = min(30, $failures - $freeFailures);
    return (int)min((int)$policy['max_delay_seconds'], (int)$policy['base_delay_seconds'] * (2 ** $steps));
}

/**
 * Evaluate the throttle from the recorded attempts of one network. Each
 * attempt is ['username' => string, 'success' => bool, 'time' => int], in
 * the order recorded. A success resets that username's failures from this
 * network, never the network's own count.
 *
 * @param list<array{username:string,success:bool,time:int}> $attempts
 * @return array{limited:bool,retry_after:int,pair_failures:int,network_failures:int}
 */
function bms_login_throttle_evaluate(array $attempts, string $username, int $now, ?array $policy = null): array
{
    $policy = $policy ?? bms_login_throttle_policy();
    $cutoff = $now - (int)$policy['window_seconds'];
    $pairFailures = 0;
    $pairLast = 0;
    $networkFailures = 0;
    $networkLast = 0;
    foreach ($attempts as $attempt) {
        $time = (int)($attempt['time'] ?? 0);
        if ($time <= $cutoff) {
            continue;
        }
        $success = !empty($attempt['success']);
        if (!$success) {
            $networkFailures++;
            $networkLast = max($networkLast, $time);
        }
        if ((string)($attempt['username'] ?? '') === $username) {
            if ($success) {
                $pairFailures = 0;
                $pairLast = 0;
            } else {
                $pairFailures++;
                $pairLast = max($pairLast, $time);
            }
        }
    }

    $retryAfter = 0;
    foreach ([
        [$pairFailures, $pairLast, (int)$policy['pair_free_failures']],
        [$networkFailures, $networkLast, (int)$policy['network_free_failures']],
    ] as [$failures, $last, $free]) {
        $delay = bms_login_throttle_delay($failures, $free, $policy);
        $elapsed = $now - $last;
        if ($delay > 0 && $elapsed < $delay) {
            $retryAfter = max($retryAfter, $delay - $elapsed);
        }
    }

    return [
        'limited' => $retryAfter > 0,
        'retry_after' => $retryAfter,
        'pair_failures' => $pairFailures,
        'network_failures' => $networkFailures,
    ];
}

function bms_login_fallback_path(): string
{
    return bms_root_path('tmp/login-attempts.json');
}

function bms_load_login_fallback_attempts(): array
{
    $path = bms_login_fallback_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function bms_save_login_fallback_attempts(array $attempts): void
{
    $cutoff = time() - (int)bms_login_throttle_policy()['window_seconds'];
    $attempts = array_values(array_filter($attempts, function ($attempt) use ($cutoff) {
        return is_array($attempt) && (int)($attempt['time'] ?? 0) >= $cutoff;
    }));
    bms_write_file(bms_login_fallback_path(), json_encode($attempts, JSON_PRETTY_PRINT));
}

function bms_record_login_attempt_fallback(string $username, bool $success): void
{
    try {
        $attempts = bms_load_login_fallback_attempts();
        $attempts[] = [
            'username' => bms_normalize_username($username),
            'ip_hash' => bms_login_ip_hash(),
            'success' => $success,
            'time' => time(),
        ];
        bms_save_login_fallback_attempts($attempts);
    } catch (Throwable $e) {
        // Last-resort fallback logging should not reveal errors to attackers.
    }
}

function bms_login_throttle_state_fallback(string $username): array
{
    try {
        $ipHash = bms_login_ip_hash();
        $attempts = [];
        foreach (bms_load_login_fallback_attempts() as $attempt) {
            if (is_array($attempt) && (string)($attempt['ip_hash'] ?? '') === $ipHash) {
                $attempts[] = [
                    'username' => (string)($attempt['username'] ?? ''),
                    'success' => !empty($attempt['success']),
                    'time' => (int)($attempt['time'] ?? 0),
                ];
            }
        }
        return bms_login_throttle_evaluate($attempts, bms_normalize_username($username), time());
    } catch (Throwable $e) {
        return ['limited' => true, 'retry_after' => 60, 'pair_failures' => 0, 'network_failures' => 0];
    }
}

function bms_record_login_attempt(string $username, bool $success): void
{
    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('login_attempts') . ' (username, ip_hash, success, attempted_at) VALUES (:username, :ip_hash, :success, NOW())');
        $stmt->execute([
            'username' => bms_normalize_username($username),
            'ip_hash' => bms_login_ip_hash(),
            'success' => $success ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        bms_record_login_attempt_fallback($username, $success);
    }
}

/** @return array{limited:bool,retry_after:int,pair_failures:int,network_failures:int} */
function bms_login_throttle_state(string $username): array
{
    try {
        $pdo = bms_db();
        $window = (int)bms_login_throttle_policy()['window_seconds'];
        $stmt = $pdo->prepare('SELECT username, success, UNIX_TIMESTAMP(attempted_at) AS attempted FROM ' . bms_table('login_attempts') . ' WHERE ip_hash = :ip_hash AND attempted_at > (NOW() - INTERVAL ' . $window . ' SECOND) ORDER BY attempted_at ASC, id ASC');
        $stmt->execute(['ip_hash' => bms_login_ip_hash()]);
        $attempts = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $attempts[] = [
                'username' => (string)($row['username'] ?? ''),
                'success' => (int)($row['success'] ?? 0) === 1,
                'time' => (int)($row['attempted'] ?? 0),
            ];
        }
        $now = (int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        return bms_login_throttle_evaluate($attempts, bms_normalize_username($username), $now);
    } catch (Throwable $e) {
        return bms_login_throttle_state_fallback($username);
    }
}

function bms_login_rate_limited(string $username): bool
{
    return bms_login_throttle_state($username)['limited'];
}

function bms_roles(): array
{
    return [
        'admin' => 'Admin',
        'commenter' => 'Commenter',
    ];
}

function bms_normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    return $role === 'admin' ? 'admin' : 'commenter';
}

function bms_role_label(string $role): string
{
    $roles = bms_roles();
    $role = bms_normalize_role($role);
    return $roles[$role] ?? 'Commenter';
}

function bms_user_status_options(): array
{
    return [
        'active' => 'Active',
        'pending' => 'Pending',
        'inactive' => 'Inactive',
    ];
}

function bms_normalize_user_status(string $status): string
{
    $status = strtolower(trim($status));
    return array_key_exists($status, bms_user_status_options()) ? $status : 'active';
}

function bms_user_status_label(string $status): string
{
    $options = bms_user_status_options();
    $status = bms_normalize_user_status($status);
    return $options[$status] ?? 'Active';
}

function bms_user_pending_counts(): array
{
    try {
        $sql = "SELECT
            SUM(CASE WHEN status = 'pending' AND (email_verified_at IS NULL OR email_verified_at = '') THEN 1 ELSE 0 END) AS pending_verification,
            SUM(CASE WHEN status = 'pending' AND email_verified_at IS NOT NULL AND email_verified_at <> '' THEN 1 ELSE 0 END) AS pending_approval
            FROM " . bms_table('users');
        $stmt = bms_db()->query($sql);
        $row = $stmt->fetch();
        return [
            'pending_verification' => (int)($row['pending_verification'] ?? 0),
            'pending_approval' => (int)($row['pending_approval'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['pending_verification' => 0, 'pending_approval' => 0];
    }
}

function bms_user_pending_reason(array $user): string
{
    if ((string)($user['status'] ?? 'active') !== 'pending') {
        return '';
    }
    return trim((string)($user['email_verified_at'] ?? '')) === '' ? 'Email verification' : 'Admin approval';
}

function bms_current_user_can(string $capability, ?array $subject = null): bool
{
    $user = bms_current_user();
    if ((int)($user['id'] ?? 0) < 1 || (string)($user['status'] ?? '') !== 'active') {
        return false;
    }

    $role = bms_normalize_role((string)($user['role'] ?? 'commenter'));
    if ($role === 'admin') {
        return true;
    }

    if ($role === 'commenter') {
        return in_array($capability, ['comment', 'edit_profile'], true);
    }

    return false;
}

function bms_admin_route_capability(string $script): ?string
{
    return match ($script) {
        'index.php', 'welcome.php', 'help.php', 'user.php' => 'view_admin',
        'content.php', 'new.php', 'edit.php', 'preview.php', 'preview-current.php', 'quick-edit.php', 'stream-quick-edit.php', 'stream-trash.php', 'delete.php', 'restore.php', 'delete-permanent.php' => 'edit_content',
        'share-target.php' => 'publish_content',
        'publish.php', 'unpublish.php', 'pin.php' => 'publish_content',
        'pages.php', 'page-new.php', 'page-edit.php', 'page-delete.php', 'page-publish.php', 'page-unpublish.php', 'page-restore.php', 'page-delete-permanent.php' => 'manage_pages',
        'quick-post.php' => 'edit_content',
        'places.php', 'place-edit.php', 'place-delete.php', 'places-nearby.php', 'places-save.php' => 'edit_content',
        'scheduled-runner.php' => 'publish_content',
        'link-preview.php' => 'edit_content',
        'autosave.php' => 'edit_content',
        'media.php', 'media-upload.php', 'media-edit.php', 'media-picker.php', 'media-regenerate.php' => 'manage_media',
        'comments.php' => 'manage_comments',
        'revisions.php', 'compare-revision.php', 'restore-revision.php' => 'restore_revisions',
        'appearance.php', 'theme.php', 'theme-details.php', 'theme-settings.php', 'theme-install.php', 'theme-delete.php', 'navigation.php', 'site-identity.php' => 'manage_appearance',
        'settings-index.php', 'settings.php', 'settings-writing.php', 'settings-reading.php', 'security.php', 'registration.php', 'mail.php', 'remote-posting.php', 'scheduled-tasks.php', 'activitypub.php' => 'manage_settings',
        'users.php', 'user-new.php', 'user-edit.php' => 'manage_users',
        'tools.php', 'analytics.php', 'upgrade.php', 'export.php', 'import.php', 'import-markdown.php', 'system-check.php' => 'view_system',
        default => null,
    };
}

function bms_enforce_admin_route_capability(): void
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $capability = bms_admin_route_capability($script);
    if ($capability !== null) {
        bms_require_capability($capability);
    }
}

function bms_filter_content_items_for_current_user(array $items): array
{
    return $items;
}

function bms_require_trash_item_access(int $id): array
{
    $item = function_exists('bms_get_trash_item') ? bms_get_trash_item($id) : null;
    if (!$item) {
        bms_abort_request('Trash item not found.', 404);
    }
    $authorId = (int)($item['original_author_id'] ?? $item['author_id'] ?? $item['deleted_by'] ?? 0);
    bms_require_capability('restore_trash', ['author_id' => $authorId]);
    return $item;
}

function bms_require_revision_access(array $revision): void
{
    $authorId = function_exists('bms_revision_original_author_id') ? bms_revision_original_author_id($revision) : (int)($revision['author_id'] ?? 0);
    bms_require_capability('restore_revisions', ['author_id' => (int)$authorId]);
}

function bms_require_content_file_access(string $section, string $filename, string $capability = 'edit_content', array $page = []): void
{
    $subject = function_exists('bms_content_subject_for_file') ? bms_content_subject_for_file($section, $filename, $page) : $page;
    bms_require_capability($capability, $subject);
}

function bms_require_capability(string $capability, ?array $subject = null): void
{
    if (!bms_current_user_can($capability, $subject)) {
        bms_abort_request('You do not have permission to access this area.', 403);
    }
}

function bms_list_users(): array
{
    bms_require_installed();
    $stmt = bms_db()->query('SELECT id, username, display_name, email, email_verified_at, role, status, profile_visibility, avatar_path, created_at, updated_at FROM ' . bms_table('users') . ' ORDER BY display_name ASC, username ASC');
    return $stmt->fetchAll() ?: [];
}

function bms_create_user(string $username, string $displayName, string $email, string $role, string $password, string $status = 'active', bool $markEmailVerified = true): array
{
    $username = bms_normalize_username($username);
    $displayName = trim($displayName);
    $email = strtolower(trim($email));
    $role = bms_normalize_role($role);
    $status = bms_normalize_user_status($status);
    if ($role === 'admin') {
        throw new RuntimeException('The installer creates the only admin account. Additional accounts must be commenters.');
    }
    $role = 'commenter';
    if (strlen($username) < 3) { throw new RuntimeException('Username must be at least 3 characters.'); }
    if ($displayName === '') { throw new RuntimeException('Display name cannot be empty.'); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Enter a valid email address or leave it blank.'); }
    bms_validate_password_policy($password, $username, $email);
    $existing = bms_find_user_by_username_any($username);
    if ($existing) { throw new RuntimeException('That username is already taken.'); }
    if (bms_email_in_use($email)) { throw new RuntimeException(bms_email_in_use_message()); }
    $claim = bms_users_has_column('email_normalized');
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('users') . ' (username, display_name, email, ' . ($claim ? 'email_normalized, ' : '') . 'email_verified_at, password_hash, role, status, created_at, updated_at) VALUES (:username, :display_name, :email, ' . ($claim ? ':email_normalized, ' : '') . ':email_verified_at, :password_hash, :role, :status, NOW(), NOW())');
    $params = [
        'username' => $username,
        'display_name' => $displayName,
        'email' => $email,
        'email_verified_at' => $markEmailVerified ? gmdate('Y-m-d H:i:s') : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'status' => $status,
    ];
    if ($claim) {
        $params['email_normalized'] = $email !== '' ? $email : null;
    }
    try {
        $stmt->execute($params);
    } catch (PDOException $e) {
        if (bms_is_duplicate_email_claim_error($e)) {
            throw new RuntimeException(bms_email_in_use_message());
        }
        throw $e;
    }
    return bms_find_user_by_id_any((int)bms_db()->lastInsertId()) ?? [];
}

function bms_update_user_role_status(int $id, string $role, string $status): void
{
    $currentId = (int)(bms_current_user()['id'] ?? 0);
    $existing = bms_find_user_by_id_any($id);
    if (!$existing) {
        throw new RuntimeException('Account was not found.');
    }

    $existingRole = bms_normalize_role((string)($existing['role'] ?? 'commenter'));
    $role = $existingRole === 'admin' ? 'admin' : 'commenter';
    $status = bms_normalize_user_status($status);

    if ($id === $currentId && ($role !== 'admin' || $status !== 'active')) {
        throw new RuntimeException('You cannot remove your own active admin access.');
    }
    if ($existingRole === 'admin' && $status !== 'active' && bms_active_admin_count($id) < 1) {
        throw new RuntimeException('The site must keep one active admin account.');
    }

    $emailVerifiedSql = '';
    if ($status === 'active') {
        $emailVerifiedSql = ', email_verified_at = COALESCE(email_verified_at, NOW()), verification_token_hash = NULL, verification_token_expires_at = NULL';
    } elseif ($status === 'inactive') {
        // A deactivated account keeps no live verification or email-change link.
        $emailVerifiedSql = ', verification_token_hash = NULL, verification_token_expires_at = NULL' . bms_users_pending_email_clear_sql();
    }
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET role = :role, status = :status' . $emailVerifiedSql . ', updated_at = NOW() WHERE id = :id');
    $stmt->execute(['role' => $role, 'status' => $status, 'id' => $id]);
}


function bms_active_admin_count(?int $excludeUserId = null): int
{
    $sql = 'SELECT COUNT(*) FROM ' . bms_table('users') . " WHERE role = 'admin' AND status = 'active'";
    $params = [];
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeUserId;
    }
    $stmt = bms_db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function bms_user_delete_reassign_targets(int $excludeUserId): array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT id, username, display_name, role, status FROM ' . bms_table('users') . ' WHERE id <> :id AND status = :status ORDER BY display_name ASC, username ASC');
    $stmt->execute(['id' => $excludeUserId, 'status' => 'active']);
    return $stmt->fetchAll() ?: [];
}

function bms_admin_update_user_account(int $id, string $username, string $displayName, string $email, string $role, string $status, string $profileVisibility, bool $emailVerified, string $currentPassword = ''): array
{
    bms_require_capability('manage_users');
    $existing = bms_find_user_by_id_any($id);
    if (!$existing) {
        throw new RuntimeException('User was not found.');
    }

    $currentId = (int)(bms_current_user()['id'] ?? 0);
    $username = bms_normalize_username($username);
    $displayName = trim($displayName);
    $email = strtolower(trim($email));
    $existingRole = bms_normalize_role((string)($existing['role'] ?? 'commenter'));
    $role = $existingRole === 'admin' ? 'admin' : 'commenter';
    $status = bms_normalize_user_status($status);
    $profileVisibility = $profileVisibility === 'private' ? 'private' : 'public';

    if (strlen($username) < 3) {
        throw new RuntimeException('Username must be at least 3 characters.');
    }
    if ($displayName === '') {
        throw new RuntimeException('Display name cannot be empty.');
    }
    if (strlen($displayName) > 120) {
        throw new RuntimeException('Display name is too long. Keep it under 120 characters.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address or leave it blank.');
    }

    $stmt = bms_db()->prepare('SELECT id FROM ' . bms_table('users') . ' WHERE username = :username AND id <> :id LIMIT 1');
    $stmt->execute(['username' => $username, 'id' => $id]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('That username is already taken.');
    }

    $wasActiveAdmin = $existingRole === 'admin' && (string)($existing['status'] ?? '') === 'active';
    $willRemainActiveAdmin = $role === 'admin' && $status === 'active';
    if ($wasActiveAdmin && !$willRemainActiveAdmin && bms_active_admin_count($id) < 1) {
        throw new RuntimeException('The site must keep one active admin account.');
    }
    if ($id === $currentId && ($role !== 'admin' || $status !== 'active')) {
        throw new RuntimeException('You cannot remove your own active admin access.');
    }

    $emailChanged = bms_normalize_email((string)($existing['email'] ?? '')) !== $email;
    if ($emailChanged && $id === $currentId) {
        // Changing your own recovery address needs your password, wherever
        // the change is made.
        $hash = (string)($existing['password_hash'] ?? '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            throw new RuntimeException('Enter your current password to change your own email address.');
        }
    }
    if ($emailChanged && bms_email_in_use($email, $id)) {
        throw new RuntimeException(bms_email_in_use_message());
    }

    $claimSql = '';
    $params = [
        'username' => $username,
        'display_name' => $displayName,
        'email' => $email,
        'role' => $role,
        'status' => $status,
        'profile_visibility' => $profileVisibility,
        'email_verified_at' => $emailVerified ? gmdate('Y-m-d H:i:s') : null,
        'id' => $id,
    ];
    if ($emailChanged && bms_users_has_column('email_normalized')) {
        $claimSql = ', email_normalized = :email_normalized' . bms_users_pending_email_clear_sql();
        $params['email_normalized'] = $email !== '' ? $email : null;
    }
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET username = :username, display_name = :display_name, email = :email' . $claimSql . ', role = :role, status = :status, profile_visibility = :profile_visibility, email_verified_at = :email_verified_at, verification_token_hash = NULL, verification_token_expires_at = NULL, updated_at = NOW() WHERE id = :id');
    try {
        $stmt->execute($params);
    } catch (PDOException $e) {
        if (bms_is_duplicate_email_claim_error($e)) {
            throw new RuntimeException(bms_email_in_use_message());
        }
        throw $e;
    }
    if ($emailChanged) {
        bms_users_claim_unique_emails();
    }

    $current = bms_current_user();
    if ($id === (int)($current['id'] ?? 0) && $role === 'admin') {
        bms_set_setting('author_name', $displayName);
    }

    return bms_find_user_by_id_any($id) ?? [];
}

function bms_admin_reset_user_password(int $id, string $password, string $confirmPassword, string $currentPassword = ''): void
{
    bms_require_capability('manage_users');
    $user = bms_find_user_by_id_any($id);
    if (!$user) {
        throw new RuntimeException('User was not found.');
    }
    // Resetting your own password needs your current password, as changing
    // it from Account does. Resetting another account's password does not.
    if ($id === (int)(bms_current_user()['id'] ?? 0)) {
        $hash = (string)($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            throw new RuntimeException('Enter your current password to reset your own password.');
        }
    }
    if ($password !== $confirmPassword) {
        throw new RuntimeException('Password and confirmation do not match.');
    }
    bms_validate_password_policy($password, (string)($user['username'] ?? ''), (string)($user['email'] ?? ''));

    $pdo = bms_db();
    $version = bms_store_user_password($id, $password);
    bms_revoke_user_remember_tokens($id);

    try {
        $stmt = $pdo->prepare('UPDATE ' . bms_table('password_reset_tokens') . ' SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL');
        $stmt->execute(['user_id' => $id]);
    } catch (Throwable $e) {
        // Password reset token cleanup should not block an admin password reset.
    }

    // Resetting your own password from Admin keeps this session signed in.
    bms_rebind_current_session($id, $version);
}

/**
 * Store a new password and move the account to a new credential generation
 * in one transaction, so every other session signed in with the old password
 * is signed out on its next request. Returns the new generation, or null
 * before the session-binding migration has run.
 */
function bms_store_user_password(int $userId, string $password): ?int
{
    $pdo = bms_db();
    $startedTransaction = !$pdo->inTransaction() && $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE ' . bms_table('users') . ' SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
        $version = bms_bump_user_session_version($userId);
        if ($startedTransaction) {
            $pdo->commit();
        }
        return $version;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_admin_delete_user(int $id, int $reassignToUserId, string $confirmation): void
{
    bms_require_capability('manage_users');
    $user = bms_find_user_by_id_any($id);
    if (!$user) {
        throw new RuntimeException('User was not found.');
    }

    $currentId = (int)(bms_current_user()['id'] ?? 0);
    if ($id === $currentId) {
        throw new RuntimeException('You cannot delete your own account while signed in.');
    }

    $username = (string)($user['username'] ?? '');
    if (bms_normalize_username($confirmation) !== bms_normalize_username($username)) {
        throw new RuntimeException('Type the username exactly to confirm account deletion.');
    }

    $isActiveAdmin = bms_normalize_role((string)($user['role'] ?? 'commenter')) === 'admin' && (string)($user['status'] ?? '') === 'active';
    if ($isActiveAdmin && bms_active_admin_count($id) < 1) {
        throw new RuntimeException('The site must keep one active admin account.');
    }

    $reassignToUserId = max(0, $reassignToUserId);
    if ($reassignToUserId === $id) {
        throw new RuntimeException('Choose a different account for reassigned content.');
    }
    $reassignTargetId = $currentId > 0 ? $currentId : null;
    if ($reassignToUserId > 0) {
        $target = bms_find_user_by_id_any($reassignToUserId);
        if (!$target || (string)($target['status'] ?? '') !== 'active') {
            throw new RuntimeException('Choose an active account for reassigned content.');
        }
        $reassignTargetId = (int)$target['id'];
    }

    $pdo = bms_db();
    $profileCoverPath = '';
    $profilePhotoPaths = [];
    try {
        $profileStmt = $pdo->prepare('SELECT cover_image_path, profile_photos_json FROM ' . bms_table('user_profiles') . ' WHERE user_id = :id LIMIT 1');
        $profileStmt->execute(['id' => $id]);
        $profileRow = $profileStmt->fetch();
        if (is_array($profileRow)) {
            $profileCoverPath = (string)($profileRow['cover_image_path'] ?? '');
            $profilePhotos = json_decode((string)($profileRow['profile_photos_json'] ?? '[]'), true);
            if (is_array($profilePhotos)) {
                foreach ($profilePhotos as $photo) {
                    if (is_array($photo) && trim((string)($photo['path'] ?? '')) !== '') {
                        $profilePhotoPaths[] = (string)$photo['path'];
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $profilePhotoPaths = [];
        try {
            $profileStmt = $pdo->prepare('SELECT cover_image_path FROM ' . bms_table('user_profiles') . ' WHERE user_id = :id LIMIT 1');
            $profileStmt->execute(['id' => $id]);
            $profileCoverPath = (string)($profileStmt->fetchColumn() ?: '');
        } catch (Throwable $fallbackError) {
            $profileCoverPath = '';
        }
    }

    $pdo->beginTransaction();
    try {
        $updates = [
            ['posts', 'author_id'],
            ['revisions', 'author_id'],
            ['media', 'uploaded_by'],
            ['comments', 'user_id'],
            ['trash', 'original_author_id'],
            ['trash', 'deleted_by'],
        ];
        foreach ($updates as [$table, $column]) {
            try {
                $stmt = $pdo->prepare('UPDATE ' . bms_table($table) . ' SET ' . $column . ' = :target WHERE ' . $column . ' = :id');
                $stmt->execute(['target' => $reassignTargetId, 'id' => $id]);
            } catch (Throwable $e) {
                // Optional/legacy tables or columns should not block deletion cleanup.
            }
        }

        $nullUpdates = [
            ['autosaves', 'user_id'],
            ['registration_invites', 'created_by'],
            ['password_reset_tokens', 'user_id'],
            ['api_tokens', 'created_by'],
        ];
        foreach ($nullUpdates as [$table, $column]) {
            try {
                $stmt = $pdo->prepare('UPDATE ' . bms_table($table) . ' SET ' . $column . ' = NULL WHERE ' . $column . ' = :id');
                $stmt->execute(['id' => $id]);
            } catch (Throwable $e) {
                // Optional/legacy tables or NOT NULL columns should not block core account deletion.
            }
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM ' . bms_table('password_reset_tokens') . ' WHERE user_id = :id');
            $stmt->execute(['id' => $id]);
        } catch (Throwable $e) {
            // Cleanup-only.
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM ' . bms_table('user_profiles') . ' WHERE user_id = :id');
            $stmt->execute(['id' => $id]);
        } catch (Throwable $e) {
            // Profile identity table is optional on pre-migration installs.
        }

        $stmt = $pdo->prepare('DELETE FROM ' . bms_table('users') . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // An address the deleted account shared may now belong to one account.
    bms_users_claim_unique_emails();

    if (function_exists('bms_user_avatar_delete_file')) {
        bms_user_avatar_delete_file((string)($user['avatar_path'] ?? ''));
    }
    if ($profileCoverPath !== '' && function_exists('bms_profile_cover_delete_file')) {
        bms_profile_cover_delete_file($profileCoverPath);
    }
    if ($profilePhotoPaths && function_exists('bms_profile_photo_delete_file')) {
        foreach ($profilePhotoPaths as $profilePhotoPath) {
            bms_profile_photo_delete_file((string)$profilePhotoPath, $id);
        }
    }
}

function bms_profile_social_link_definitions(): array
{
    return [
        'x' => ['label' => 'X', 'placeholder' => 'https://x.com/username'],
        'bluesky' => ['label' => 'Bluesky', 'placeholder' => 'https://bsky.app/profile/username.bsky.social'],
        'github' => ['label' => 'GitHub', 'placeholder' => 'https://github.com/username'],
        'instagram' => ['label' => 'Instagram', 'placeholder' => 'https://instagram.com/username'],
        'youtube' => ['label' => 'YouTube', 'placeholder' => 'https://youtube.com/@username'],
        'linkedin' => ['label' => 'LinkedIn', 'placeholder' => 'https://linkedin.com/in/username'],
        'facebook' => ['label' => 'Facebook', 'placeholder' => 'https://facebook.com/username'],
        'tiktok' => ['label' => 'TikTok', 'placeholder' => 'https://tiktok.com/@username'],
        'mastodon' => ['label' => 'Mastodon', 'placeholder' => 'https://mastodon.social/@username'],
    ];
}

function bms_profile_social_links_decode(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bms_normalize_profile_social_url(string $url, string $label): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (strlen($url) > 500) {
        throw new RuntimeException($label . ' URL is too long. Keep it under 500 characters.');
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Enter a valid ' . $label . ' URL or leave it blank.');
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException($label . ' URL must start with http:// or https://.');
    }
    return $url;
}

function bms_normalize_profile_social_label(string $label, string $fallback): string
{
    $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
    if ($label === '') {
        $label = $fallback;
    }
    if (function_exists('mb_substr')) {
        $label = bms_text_substr($label, 0, 60);
    } else {
        $label = substr($label, 0, 60);
    }
    return trim($label) !== '' ? trim($label) : $fallback;
}

function bms_profile_social_link_form_values(array $user): array
{
    $values = [];
    foreach (bms_profile_social_link_definitions() as $id => $definition) {
        $values[$id] = '';
    }
    $values['custom_1_label'] = '';
    $values['custom_1_url'] = '';
    $values['custom_2_label'] = '';
    $values['custom_2_url'] = '';

    $customIndex = 1;
    foreach (bms_profile_social_links_decode($user['social_links'] ?? '') as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (string)($item['id'] ?? '');
        $url = trim((string)($item['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        if (array_key_exists($id, $values)) {
            $values[$id] = $url;
            continue;
        }
        if (str_starts_with($id, 'custom_') && $customIndex <= 2) {
            $values['custom_' . $customIndex . '_label'] = (string)($item['label'] ?? '');
            $values['custom_' . $customIndex . '_url'] = $url;
            $customIndex++;
        }
    }

    return $values;
}

function bms_normalize_profile_social_links_from_input(array $input): string
{
    $links = [];
    foreach (bms_profile_social_link_definitions() as $id => $definition) {
        $label = (string)($definition['label'] ?? ucfirst($id));
        $url = bms_normalize_profile_social_url((string)($input[$id] ?? ''), $label);
        if ($url !== '') {
            $links[] = ['id' => $id, 'label' => $label, 'url' => $url];
        }
    }

    for ($i = 1; $i <= 2; $i++) {
        $labelKey = 'custom_' . $i . '_label';
        $urlKey = 'custom_' . $i . '_url';
        $rawLabel = trim((string)($input[$labelKey] ?? ''));
        $url = bms_normalize_profile_social_url((string)($input[$urlKey] ?? ''), 'Custom link ' . $i);
        if ($url === '') {
            continue;
        }
        if ($rawLabel === '') {
            throw new RuntimeException('Custom link ' . $i . ' needs a label.');
        }
        $links[] = [
            'id' => 'custom_' . $i,
            'label' => bms_normalize_profile_social_label($rawLabel, 'Custom Link ' . $i),
            'url' => $url,
        ];
    }

    return json_encode($links, JSON_UNESCAPED_SLASHES) ?: '[]';
}

function bms_profile_social_links_for_user(array $user): array
{
    $links = [];
    foreach (bms_profile_social_links_decode($user['social_links'] ?? '') as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = bms_normalize_profile_social_label((string)($item['label'] ?? ''), 'Link');
        $url = trim((string)($item['url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            continue;
        }
        $links[] = [
            'label' => $label,
            'url' => $url,
        ];
    }
    return $links;
}


/**
 * Save the signed-in account's username and handle an email change.
 *
 * Changing the email address needs the current password. A new address takes
 * effect only after the person opens the confirmation link sent to it; until
 * then the account keeps its current address. Removing the address applies
 * at once.
 *
 * @return array{user:array,email_change:string,pending_email:string}
 *   email_change is 'none', 'pending', or 'removed'.
 */
function bms_update_current_user_account(string $username, string $email = '', string $currentPassword = ''): array
{
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    if ($currentId < 1) {
        throw new RuntimeException('You must be signed in to update account details.');
    }

    $username = bms_normalize_username($username);
    $email = bms_normalize_email($email);

    if (strlen($username) < 3) {
        throw new RuntimeException('Username must be at least 3 characters.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address or leave it blank.');
    }

    $stmt = bms_db()->prepare('SELECT id FROM ' . bms_table('users') . ' WHERE username = :username AND id <> :id LIMIT 1');
    $stmt->execute(['username' => $username, 'id' => $currentId]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('That username is already taken.');
    }

    $emailChange = 'none';
    if ($email !== bms_normalize_email((string)($current['email'] ?? ''))) {
        $hash = (string)($current['password_hash'] ?? '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            throw new RuntimeException('Enter your current password to change your email address.');
        }
        if ($email !== '' && bms_email_in_use($email, $currentId)) {
            throw new RuntimeException(bms_email_in_use_message());
        }
        if ($email !== '') {
            if (!function_exists('bms_account_email_change_ready') || !bms_account_email_change_ready()) {
                throw new RuntimeException(bms_current_user_can('manage_users')
                    ? 'This site cannot send email yet, so a new address cannot be confirmed. Configure Mail in Settings, or change the address in Admin under Accounts.'
                    : 'This site cannot send email yet, so a new address cannot be confirmed. Ask the site admin to change your address.');
            }
            $emailChange = 'pending';
        } else {
            $emailChange = 'removed';
        }
    }

    // Request the confirmation first: if it cannot be sent, nothing changes.
    if ($emailChange === 'pending') {
        bms_account_request_email_change($currentId, $email);
    }

    $sql = 'UPDATE ' . bms_table('users') . ' SET username = :username';
    $params = ['username' => $username, 'id' => $currentId];
    if ($emailChange === 'removed') {
        $sql .= ", email = '', email_verified_at = NULL"
            . (bms_users_has_column('email_normalized') ? ', email_normalized = NULL' : '')
            . bms_users_pending_email_clear_sql();
    }
    $stmt = bms_db()->prepare($sql . ', updated_at = NOW() WHERE id = :id');
    $stmt->execute($params);

    if ($emailChange === 'removed') {
        bms_users_claim_unique_emails();
    }

    return [
        'user' => bms_find_user_by_id($currentId) ?? bms_current_user(),
        'email_change' => $emailChange,
        'pending_email' => $emailChange === 'pending' ? $email : '',
    ];
}

function bms_update_current_user_profile(string $username, string $displayName, string $email = '', string $bio = '', string $website = '', string $profileVisibility = 'public', array $socialLinksInput = []): array
{
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    $username = bms_normalize_username($username);
    $displayName = trim($displayName);
    $bio = trim($bio);
    $website = trim($website);
    $profileVisibility = $profileVisibility === 'private' ? 'private' : 'public';

    if (strlen($username) < 3) {
        throw new RuntimeException('Username must be at least 3 characters.');
    }

    if ($displayName === '') {
        throw new RuntimeException('Display name cannot be empty.');
    }

    if (strlen($displayName) > 120) {
        throw new RuntimeException('Display name is too long. Keep it under 120 characters.');
    }

    // This helper never changes the email address. An email change needs
    // the current password and confirmation; see Account details.
    if (bms_normalize_email($email) !== bms_normalize_email((string)($current['email'] ?? ''))) {
        throw new RuntimeException('Change your email address from Account details. It needs your current password.');
    }

    if ($website !== '') {
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if (!filter_var($website, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Enter a valid website URL or leave it blank.');
        }
    }

    if (strlen($bio) > 1000) {
        throw new RuntimeException('Profile bio is too long. Keep it under 1000 characters.');
    }

    $socialLinks = bms_normalize_profile_social_links_from_input($socialLinksInput);

    $stmt = bms_db()->prepare('SELECT id FROM ' . bms_table('users') . ' WHERE username = :username AND id <> :id LIMIT 1');
    $stmt->execute(['username' => $username, 'id' => $currentId]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('That username is already taken.');
    }

    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET username = :username, display_name = :display_name, bio = :bio, website = :website, social_links = :social_links, profile_visibility = :profile_visibility, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'username' => $username,
        'display_name' => $displayName,
        'bio' => $bio,
        'website' => $website,
        'social_links' => $socialLinks,
        'profile_visibility' => $profileVisibility,
        'id' => $currentId,
    ]);

    if (bms_normalize_role((string)($current['role'] ?? 'commenter')) === 'admin') {
        bms_set_setting('author_name', $displayName);
    }
    return bms_find_user_by_id($currentId) ?? bms_current_user();
}

function bms_create_commenter_account(string $username, string $displayName, string $email, string $password, string $confirmPassword): array
{
    if (function_exists('bms_registration_create_public_account')) {
        $result = bms_registration_create_public_account($username, $displayName, $email, $password, $confirmPassword);
        return is_array($result['user'] ?? null) ? $result['user'] : [];
    }
    if (bms_setting_or_config('comment_registration_enabled', '1') !== '1') {
        throw new RuntimeException('Comment account registration is currently closed.');
    }
    if ($password !== $confirmPassword) {
        throw new RuntimeException('Password and confirmation do not match.');
    }
    return bms_create_user($username, $displayName, $email, 'commenter', $password);
}

function bms_update_current_user_password(string $currentPassword, string $newPassword, string $confirmPassword): void
{
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    $hash = (string)($current['password_hash'] ?? '');

    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        throw new RuntimeException('Current password did not match.');
    }

    bms_validate_password_policy($newPassword, (string)($current['username'] ?? ''), (string)($current['email'] ?? ''));

    if ($newPassword !== $confirmPassword) {
        throw new RuntimeException('New password and confirmation do not match.');
    }

    $version = bms_store_user_password($currentId, $newPassword);
    bms_revoke_user_remember_tokens($currentId);
    // This session made the change, so it stays signed in.
    bms_rebind_current_session($currentId, $version);
}

function bms_is_logged_in(): bool
{
    return bms_session_user() !== null;
}

function bms_require_login(): void
{
    bms_require_installed();
    if (!bms_is_logged_in()) {
        bms_redirect(bms_admin_url('login.php'));
    }
    bms_enforce_admin_route_capability();
}

function bms_attempt_login(string $username, string $password, bool $remember = false): bool
{
    bms_require_installed();

    // Remembered devices are never throttled: they sign in through
    // bms_restore_remembered_login(), which does not consult this limit.
    if (bms_login_rate_limited($username)) {
        return false;
    }

    $user = bms_find_user_by_username($username);
    $hash = (string)($user['password_hash'] ?? '');

    if ($user && $hash !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        bms_bind_login_session($user);
        if ($remember) {
            bms_create_remember_token((int)$user['id']);
        } else {
            bms_revoke_current_remember_token();
        }
        bms_record_login_attempt($username, true);
        return true;
    }

    if (!$user || $hash === '') {
        // No active account matched. Do the same password-hashing work a wrong
        // password costs, so the response time does not tell an unknown,
        // pending, or inactive username from an active one.
        password_hash($password, PASSWORD_DEFAULT);
    }
    bms_record_login_attempt($username, false);
    return false;
}

function bms_logout(): void
{
    bms_revoke_current_remember_token();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => (string)($params['path'] ?? bms_session_cookie_path()),
            'domain' => (string)($params['domain'] ?? ''),
            'secure' => (bool)($params['secure'] ?? bms_is_https()),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function bms_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function bms_verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        bms_abort_request('Invalid request token.', 403);
    }
}
