<?php
/**
 * Accounts, sessions, and abuse limits HTTP regression test against a
 * disposable install.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_accounts_ci_* tables are created and
 * removed. Application files, sessions, captured mail, and the loopback HTTP
 * server are temporary. Requests come from several loopback addresses
 * (127.0.0.x), so per-network behavior is exercised through real requests.
 *
 * Covers: sign-in throttling per username and network and per network, with
 * no lock on a username alone; sessions bound to credential changes and
 * "Sign out all other devices"; verification links limited to pending,
 * unverified accounts; password-gated, confirmed email changes with unique
 * addresses and a deterministic recovery lookup; comment rate limits and the
 * optional first-comment hold.
 *
 * Every check is reported, so a run against an older tree shows exactly
 * which protections are missing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run without BMS_DB_DANGER_RESET=1.\n");
    exit(1);
}
foreach (['BMS_DB_HOST', 'BMS_DB_NAME', 'BMS_DB_USER'] as $required) {
    if ((string)getenv($required) === '') {
        throw new RuntimeException($required . ' is required.');
    }
}

$source = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/bms-accounts-runtime-' . bin2hex(random_bytes(6));
$prefix = 'bms_accounts_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;
$failures = [];
const BMS_ACCT_PASSWORD = 'Disposable-Passphrase-43';
$ownerPassword = BMS_ACCT_PASSWORD;

/** Record a check. Failures are collected so one run reports every gap. */
function bms_acct_expect(bool $condition, string $label): void
{
    global $checks, $failures;
    $checks++;
    fwrite(STDOUT, ($condition ? 'PASS ' : 'FAIL ') . $label . "\n");
    if (!$condition) {
        $failures[] = $label;
    }
}

/**
 * Run one section; an unexpected exception is reported as one failure.
 * BMS_ACCOUNT_TEST_SECTIONS may name a comma-separated subset by key, so a
 * run against an older tree can test each protection on its own install.
 */
function bms_acct_section(string $key, string $label, callable $callback): void
{
    $selected = array_filter(array_map('trim', explode(',', (string)getenv('BMS_ACCOUNT_TEST_SECTIONS'))));
    if ($selected !== [] && !in_array($key, $selected, true)) {
        return;
    }
    fwrite(STDOUT, "\n== {$label}\n");
    try {
        $callback();
    } catch (Throwable $e) {
        bms_acct_expect(false, $label . ' ran without error (' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

/**
 * One HTTP request from a chosen loopback address.
 *
 * @param array<string,string> $cookies
 * @param array<string,mixed>|null $form
 * @return array{status:int,body:string,location:string}
 */
function bms_acct_request(string $path, array &$cookies, ?array $form = null, string $ip = '127.0.0.1', string $agent = 'Bonumark Accounts Regression'): array
{
    global $base;
    $headers = ['User-Agent: ' . $agent, 'Connection: close'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($form);
    }
    $options['header'] = implode("\r\n", $headers);
    $context = stream_context_create(['http' => $options, 'socket' => ['bindto' => $ip . ':0']]);
    $body = @file_get_contents($base . $path, false, $context);
    if ($body === false || !isset($http_response_header[0])) {
        throw new RuntimeException('HTTP request failed: ' . $path . ' from ' . $ip);
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    $location = '';
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            if ($match[2] === '' || $match[2] === 'deleted') {
                unset($cookies[$match[1]]);
            } else {
                $cookies[$match[1]] = $match[2];
            }
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location];
}

function bms_acct_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_acct_admin_error(string $html): string
{
    return preg_match('/<div class="flash error">(.*?)<\/div>/s', $html, $match) ? trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8')) : '';
}

/** Sign in through Admin and return the cookie jar, or [] when refused. */
function bms_acct_admin_login(string $username, string $password, string $ip, bool $remember = false, string $agent = 'Bonumark Accounts Regression'): array
{
    $jar = [];
    $page = bms_acct_request('/admin/login.php', $jar, null, $ip, $agent);
    $form = ['csrf_token' => bms_acct_token($page['body']), 'username' => $username, 'password' => $password];
    if ($remember) {
        $form['remember_me'] = '1';
    }
    $response = bms_acct_request('/admin/login.php', $jar, $form, $ip, $agent);
    return $response['status'] === 302 ? $jar : [];
}

/** Sign in through the public Account page and return the jar, or []. */
function bms_acct_account_login(string $username, string $password, string $ip, bool $remember = false, string $agent = 'Bonumark Accounts Regression'): array
{
    $jar = [];
    $page = bms_acct_request('/account.php', $jar, null, $ip, $agent);
    $form = ['csrf_token' => bms_acct_token($page['body']), 'action' => 'login', 'username' => $username, 'password' => $password];
    if ($remember) {
        $form['remember_me'] = '1';
    }
    $response = bms_acct_request('/account.php', $jar, $form, $ip, $agent);
    return $response['status'] === 302 ? $jar : [];
}

function bms_acct_admin_signed_in(array $jar, string $ip = '127.0.0.1', string $agent = 'Bonumark Accounts Regression'): bool
{
    return bms_acct_request('/admin/index.php', $jar, null, $ip, $agent)['status'] === 200;
}

function bms_acct_account_signed_in(array &$jar, string $ip = '127.0.0.1', string $agent = 'Bonumark Accounts Regression'): bool
{
    $page = bms_acct_request('/account.php', $jar, null, $ip, $agent);
    return str_contains($page['body'], 'Your account') && !str_contains($page['body'], '<h1>Sign in</h1>');
}

/** @return list<string> mail files written since $before */
function bms_acct_new_mail(array $before): array
{
    global $temporary;
    clearstatcache();
    $now = glob($temporary . '/mail/*.eml') ?: [];
    sort($now);
    return array_values(array_diff($now, $before));
}

function bms_acct_mail_files(): array
{
    global $temporary;
    clearstatcache();
    $files = glob($temporary . '/mail/*.eml') ?: [];
    sort($files);
    return $files;
}

function bms_acct_mail_text(string $file): string
{
    $raw = (string)file_get_contents($file);
    return stripos($raw, 'Content-Transfer-Encoding: quoted-printable') !== false ? quoted_printable_decode($raw) : $raw;
}

/** The first link in captured mail that matches an account action. */
function bms_acct_mail_link(array $files, string $action): string
{
    foreach ($files as $file) {
        if (preg_match('#https?://[^\s]+/account\.php\?action=' . preg_quote($action, '#') . '&token=([a-f0-9]{64})#', bms_acct_mail_text($file), $match)) {
            return $match[1];
        }
    }
    return '';
}

function bms_acct_row(string $table, string $where, array $params = []): array
{
    global $pdo, $prefix;
    $stmt = $pdo->prepare('SELECT * FROM `' . $prefix . $table . '` WHERE ' . $where . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

/** True when the row has the column and it holds SQL NULL. */
function bms_acct_is_null(array $row, string $column): bool
{
    return array_key_exists($column, $row) && $row[$column] === null;
}

function bms_acct_user(string $username): array
{
    return bms_acct_row('users', 'username = ?', [$username]);
}

function bms_acct_count(string $table, string $where, array $params = []): int
{
    global $pdo, $prefix;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $prefix . $table . '` WHERE ' . $where);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function bms_acct_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($path);
}

/** Post one comment as the session in $jar; returns the HTTP status. */
function bms_acct_comment(array &$jar, string $ip, string $body): array
{
    $panel = bms_acct_request('/comments.php?slug=limits-fixture', $jar, null, $ip);
    return bms_acct_request('/comments.php', $jar, ['csrf_token' => bms_acct_token($panel['body']), 'slug' => 'limits-fixture', 'body' => $body], $ip);
}

try {
    mkdir($temporary, 0700);
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file) use ($source): bool {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1));
        // Never copy an installed checkout's credentials, backups, uploads or
        // generated exports into the disposable HTTP document root.
        return !$file->isLink()
            && !in_array($relative, [
                '.git', '_bonumark_stream/config.php', '_bonumark_stream/installed.lock',
                '_bonumark_stream/data', '_bonumark_stream/tmp', '_bonumark_stream/backups',
                'media', 'index.html', 'stream', 'feed.xml', 'sitemap.xml', 'sitemap.xsl', 'robots.txt',
            ], true)
            && preg_match('/\.(?:log|sql|sqlite|zip|tar|tar\.gz|tgz)$/i', $relative) !== 1;
    }), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $target = $temporary . substr($file->getPathname(), strlen($source));
        $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
    }
    foreach (['sessions', 'media', 'mail', '_bonumark_stream/tmp', '_bonumark_stream/data', '_bonumark_stream/backups'] as $directory) {
        if (!is_dir($temporary . '/' . $directory)) {
            mkdir($temporary . '/' . $directory, 0700, true);
        }
    }

    // A fake Sendmail program captures every message as a file.
    $sendmail = $temporary . '/mail/capture-sendmail';
    file_put_contents($sendmail, "#!/bin/sh\nn=\$(date +%s%N)\ncat > " . escapeshellarg($temporary . '/mail') . "/message-\$n.eml\nexit 0\n");
    chmod($sendmail, 0700);

    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException('Cannot reserve a loopback port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $salt = bin2hex(random_bytes(24));
    $config = [
        'site_name' => 'Accounts Fixture', 'site_tagline' => 'Disposable', 'site_admin_email' => 'owner@example.test',
        'timezone' => 'UTC', 'base_url' => $base, 'base_path' => '', 'security_salt' => $salt,
        'scheduled_tasks_public_traffic_enabled' => '0', 'scheduled_tasks_heartbeat_enabled' => '0',
        'activitypub_enabled' => '0', 'pwa_enabled' => '0', 'mail_sendmail_path' => $sendmail,
        'database' => ['host' => (string)getenv('BMS_DB_HOST'), 'name' => (string)getenv('BMS_DB_NAME'),
            'user' => (string)getenv('BMS_DB_USER'), 'password' => (string)getenv('BMS_DB_PASS'), 'charset' => 'utf8mb4', 'prefix' => $prefix],
    ];
    file_put_contents($temporary . '/_bonumark_stream/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    touch($temporary . '/_bonumark_stream/installed.lock');
    require $temporary . '/_bonumark_stream/app/database.php';
    $pdo = bms_db();
    bms_database_require_supported($pdo);
    bms_install_schema($pdo, $prefix);
    $insertSetting = $pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key, setting_value, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach ($config as $key => $value) {
        if (is_string($value) && $key !== 'mail_sendmail_path') {
            $insertSetting->execute([$key, $value]);
        }
    }
    foreach ([
        'mail_transport' => 'sendmail', 'mail_from_email' => 'sender@example.test', 'mail_from_name' => 'Accounts Fixture',
        'registration_mode' => 'open', 'registration_require_email_verification' => '1',
        'registration_require_admin_approval' => '0', 'registration_honeypot_enabled' => '1',
        'comments_enabled' => '1', 'comments_default_status' => 'approved',
    ] as $key => $value) {
        $insertSetting->execute([$key, $value]);
    }

    // Seed accounts the way an installed site holds them. The claim column is
    // filled when it exists, so this also runs against an older tree.
    $hasClaims = (bool)$pdo->query("SHOW COLUMNS FROM `{$prefix}users` LIKE 'email_normalized'")->fetch();
    $hash = password_hash(BMS_ACCT_PASSWORD, PASSWORD_DEFAULT);
    $seed = static function (string $username, string $role, string $email, bool $claim = true) use ($pdo, $prefix, $hasClaims, $hash): int {
        $columns = 'username,display_name,email,email_verified_at,password_hash,role,status,created_at,updated_at';
        $values = "?,?,?,UTC_TIMESTAMP(),?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP()";
        $params = [$username, ucfirst($username), $email, $hash, $role];
        if ($hasClaims) {
            $columns .= ',email_normalized';
            $values .= ',?';
            $params[] = $claim ? strtolower($email) : null;
        }
        $pdo->prepare("INSERT INTO `{$prefix}users` ({$columns}) VALUES ({$values})")->execute($params);
        return (int)$pdo->lastInsertId();
    };
    $ownerId = $seed('owner', 'admin', 'owner@example.test');
    foreach (['alice', 'bob', 'carol', 'dave', 'erin', 'frank', 'grace', 'heidi', 'ivan', 'judy', 'kate', 'leo', 'mia'] as $name) {
        $seed($name, 'commenter', $name . '@example.test');
    }
    // Two accounts an earlier version let share one address.
    $sharedOldestId = $seed('shared-first', 'commenter', 'shared@example.test', false);
    $seed('shared-second', 'commenter', 'Shared@Example.test', false);
    $pdo->prepare("INSERT INTO `{$prefix}posts` (author_id,title,slug,status,post_type,description,content_body,category,category_slug,date_published,created_at,updated_at,published_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_DATE(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$ownerId, 'Limits fixture', 'limits-fixture', 'published', 'stream', 'Limits fixture', 'A post that accepts comments.', 'Stream', 'stream']);

    $environment = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']);
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the isolated PHP HTTP server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $error, $errorMessage, 0.1);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('Isolated loopback server did not start.');
    }
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    // ------------------------------------------------------------------
    bms_acct_section('throttle', 'Sign-in throttling', static function () use ($ownerId): void {
        $networkA = '127.0.0.2';
        $networkB = '127.0.0.3';
        $attacker = [];
        $page = bms_acct_request('/admin/login.php', $attacker, null, $networkA);
        $csrf = bms_acct_token($page['body']);
        $wrongError = '';
        $guess = static function (int $i) use (&$attacker, $csrf, $networkA, &$wrongError): void {
            $response = bms_acct_request('/admin/login.php', $attacker, ['csrf_token' => $csrf, 'username' => 'owner', 'password' => 'wrong-guess-' . $i], $networkA);
            if ($i === 0) {
                $wrongError = bms_acct_admin_error($response['body']);
            }
        };
        // Twelve guesses: five free ones, two more after each doubling delay,
        // then five made while the next four-second delay runs.
        for ($i = 0; $i < 5; $i++) {
            $guess($i);
        }
        usleep(1_100_000);
        $guess(5);
        usleep(2_100_000);
        $guess(6);
        for ($i = 7; $i < 12; $i++) {
            $guess($i);
        }
        bms_acct_expect(bms_acct_count('login_attempts', "username = 'owner' AND success = 0") === 7, 'Guesses made during a delay are refused without being checked');

        $owner = bms_acct_admin_login('owner', BMS_ACCT_PASSWORD, $networkB);
        bms_acct_expect($owner !== [] && bms_acct_admin_signed_in($owner, $networkB), 'Twelve failed sign-ins for the owner from one network do not block the correct password from another network');

        $throttled = bms_acct_request('/admin/login.php', $attacker, ['csrf_token' => $csrf, 'username' => 'owner', 'password' => BMS_ACCT_PASSWORD], $networkA);
        bms_acct_expect($throttled['status'] !== 302, 'The network that made the failures is still slowed, even with the correct password');
        bms_acct_expect($wrongError !== '' && bms_acct_admin_error($throttled['body']) === $wrongError, 'A slowed attempt shows the same generic message as a wrong password');

        bms_acct_expect(bms_acct_admin_login('alice', BMS_ACCT_PASSWORD, $networkA) !== [], 'Another account signs in from that network: the owner failures alone do not slow it');

        // Growing delays for one username from one network.
        $networkC = '127.0.0.4';
        $jar = [];
        $csrf = bms_acct_token(bms_acct_request('/admin/login.php', $jar, null, $networkC)['body']);
        $attempt = static function (string $password) use (&$jar, &$csrf, $networkC): bool {
            $response = bms_acct_request('/admin/login.php', $jar, ['csrf_token' => $csrf, 'username' => 'judy', 'password' => $password], $networkC);
            return $response['status'] === 302;
        };
        for ($i = 0; $i < 5; $i++) {
            $attempt('judy-wrong-' . $i);
        }
        usleep(1_100_000);
        $attempt('judy-wrong-5');
        usleep(2_100_000);
        $attempt('judy-wrong-6');
        $recorded = bms_acct_count('login_attempts', "username = 'judy' AND success = 0");
        bms_acct_expect($recorded === 7, 'Failures after the free ones are accepted once each delay has passed (' . $recorded . ' recorded)');
        bms_acct_expect($attempt(BMS_ACCT_PASSWORD) === false, 'Right after seven failures, even the correct password waits for the next delay');
        bms_acct_expect(bms_acct_count('login_attempts', "username = 'judy'") === 7, 'An attempt made during a delay is refused without being checked or recorded');
        usleep(4_100_000);
        bms_acct_expect($attempt(BMS_ACCT_PASSWORD) === true, 'Once the doubled delay has passed, the correct password signs in');

        // One network trying many usernames is slowed as a whole.
        $networkD = '127.0.0.5';
        $spray = [];
        $csrf = bms_acct_token(bms_acct_request('/admin/login.php', $spray, null, $networkD)['body']);
        for ($i = 0; $i < 20; $i++) {
            bms_acct_request('/admin/login.php', $spray, ['csrf_token' => $csrf, 'username' => 'nobody-' . $i, 'password' => 'guess'], $networkD);
        }
        usleep(1_100_000);
        bms_acct_request('/admin/login.php', $spray, ['csrf_token' => $csrf, 'username' => 'nobody-20', 'password' => 'guess'], $networkD);
        usleep(2_100_000);
        bms_acct_request('/admin/login.php', $spray, ['csrf_token' => $csrf, 'username' => 'nobody-21', 'password' => 'guess'], $networkD);
        $sprayed = bms_acct_request('/admin/login.php', $spray, ['csrf_token' => $csrf, 'username' => 'kate', 'password' => BMS_ACCT_PASSWORD], $networkD);
        bms_acct_expect($sprayed['status'] !== 302, 'A network that failed across many usernames is slowed for every username');
        bms_acct_expect(bms_acct_admin_login('kate', BMS_ACCT_PASSWORD, $networkB) !== [], 'That network does not slow the same account elsewhere');

        // Remembered devices are never throttled.
        $phone = 'Owner Phone';
        $remembered = bms_acct_admin_login('owner', BMS_ACCT_PASSWORD, $networkB, true, $phone);
        $deviceCookie = $remembered['bms_remember'] ?? '';
        $returning = ['bms_remember' => $deviceCookie];
        bms_acct_expect($deviceCookie !== '' && bms_acct_admin_signed_in($returning, $networkA, $phone), 'A remembered device signs in from the slowed network without a password prompt');
    });

    // ------------------------------------------------------------------
    bms_acct_section('sessions', 'Sessions follow credential changes', static function (): void {
        global $ownerPassword;
        // Owner: change the password in one session; the other is signed out.
        $first = bms_acct_admin_login('owner', BMS_ACCT_PASSWORD, '127.0.0.6');
        $second = bms_acct_admin_login('owner', BMS_ACCT_PASSWORD, '127.0.0.7');
        bms_acct_expect($first !== [] && $second !== [] && bms_acct_admin_signed_in($second, '127.0.0.7'), 'The owner is signed in in two sessions');
        $before = $first;
        $page = bms_acct_request('/admin/user.php', $first, null, '127.0.0.6');
        $response = bms_acct_request('/admin/user.php', $first, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'password', 'current_password' => BMS_ACCT_PASSWORD, 'new_password' => 'Changed-Passphrase-44', 'confirm_password' => 'Changed-Passphrase-44'], '127.0.0.6');
        bms_acct_expect($response['status'] === 302 && password_verify('Changed-Passphrase-44', (string)bms_acct_user('owner')['password_hash']), 'The owner changes the password in session A');
        $ownerPassword = 'Changed-Passphrase-44';
        bms_acct_expect(!bms_acct_admin_signed_in($second, '127.0.0.7'), 'Session B is signed out on its next request');
        bms_acct_expect(bms_acct_admin_signed_in($first, '127.0.0.6'), 'Session A, which made the change, stays signed in');
        bms_acct_expect(!bms_acct_admin_signed_in($before, '127.0.0.6'), 'An earlier copy of session A\'s cookie is signed out too');

        // Owner: sign out all other devices from Admin.
        $third = bms_acct_admin_login('owner', $ownerPassword, '127.0.0.7');
        $page = bms_acct_request('/admin/user.php', $first, null, '127.0.0.6');
        $response = bms_acct_request('/admin/user.php', $first, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'sign_out_other_devices'], '127.0.0.6');
        bms_acct_expect($third !== [] && $response['status'] === 302 && !bms_acct_admin_signed_in($third, '127.0.0.7'), 'Admin "Sign out all other devices" signs out the other session');
        bms_acct_expect(bms_acct_admin_signed_in($first, '127.0.0.6'), 'The session that signed out other devices stays signed in');

        // Commenter: password change on the public Account page.
        $a = bms_acct_account_login('alice', BMS_ACCT_PASSWORD, '127.0.0.6');
        $b = bms_acct_account_login('alice', BMS_ACCT_PASSWORD, '127.0.0.7');
        $page = bms_acct_request('/account.php', $a, null, '127.0.0.6');
        bms_acct_request('/account.php', $a, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'password', 'current_password' => BMS_ACCT_PASSWORD, 'new_password' => 'Alice-Changed-Pass-45', 'confirm_password' => 'Alice-Changed-Pass-45'], '127.0.0.6');
        bms_acct_expect($a !== [] && $b !== [] && !bms_acct_account_signed_in($b, '127.0.0.7'), 'A commenter password change signs out their other session');
        bms_acct_expect(bms_acct_account_signed_in($a, '127.0.0.6'), 'The commenter session that changed the password stays signed in');

        // Password reset by email signs out every session.
        $bobSession = bms_acct_account_login('bob', BMS_ACCT_PASSWORD, '127.0.0.6');
        $visitor = [];
        $page = bms_acct_request('/account.php?action=forgot', $visitor, null, '127.0.0.8');
        $before = bms_acct_mail_files();
        bms_acct_request('/account.php', $visitor, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'forgot_password', 'username_or_email' => 'bob'], '127.0.0.8');
        $token = bms_acct_mail_link(bms_acct_new_mail($before), 'reset');
        $resetPage = bms_acct_request('/account.php?action=reset&token=' . $token, $visitor, null, '127.0.0.8');
        bms_acct_request('/account.php', $visitor, ['csrf_token' => bms_acct_token($resetPage['body']), 'action' => 'reset_password', 'token' => $token, 'new_password' => 'Bob-Reset-Passphrase-46', 'confirm_password' => 'Bob-Reset-Passphrase-46'], '127.0.0.8');
        bms_acct_expect($token !== '' && password_verify('Bob-Reset-Passphrase-46', (string)bms_acct_user('bob')['password_hash']), 'A password reset link sets a new password');
        bms_acct_expect($bobSession !== [] && !bms_acct_account_signed_in($bobSession, '127.0.0.6'), 'A password reset signs out the account\'s existing session');

        // Admin reset of a commenter's password.
        $carol = bms_acct_account_login('carol', BMS_ACCT_PASSWORD, '127.0.0.7');
        $carolId = (int)bms_acct_user('carol')['id'];
        $page = bms_acct_request('/admin/user-edit.php?id=' . $carolId, $first, null, '127.0.0.6');
        bms_acct_request('/admin/user-edit.php?id=' . $carolId, $first, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'reset_password', 'user_id' => $carolId, 'new_password' => 'Carol-Temporary-Pass-47', 'confirm_password' => 'Carol-Temporary-Pass-47'], '127.0.0.6');
        bms_acct_expect($carol !== [] && !bms_acct_account_signed_in($carol, '127.0.0.7'), 'An Admin password reset signs out the commenter\'s session');
        bms_acct_expect(bms_acct_admin_signed_in($first, '127.0.0.6'), 'The owner who reset it stays signed in');

        // Commenter: sign out all other devices, including remembered ones.
        $laptop = bms_acct_account_login('dave', BMS_ACCT_PASSWORD, '127.0.0.6', true, 'Dave Laptop');
        $tablet = bms_acct_account_login('dave', BMS_ACCT_PASSWORD, '127.0.0.7', true, 'Dave Tablet');
        $tabletDevice = ['bms_remember' => $tablet['bms_remember'] ?? ''];
        $page = bms_acct_request('/account.php', $laptop, null, '127.0.0.6', 'Dave Laptop');
        $response = bms_acct_request('/account.php', $laptop, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'sign_out_other_devices'], '127.0.0.6', 'Dave Laptop');
        bms_acct_expect(str_contains($response['body'], 'Every other device is signed out'), 'Account "Sign out all other devices" confirms the result');
        bms_acct_expect(!bms_acct_account_signed_in($tablet, '127.0.0.7', 'Dave Tablet'), 'The other device\'s session is signed out');
        bms_acct_expect(($tabletDevice['bms_remember'] ?? '') !== '' && !bms_acct_account_signed_in($tabletDevice, '127.0.0.7', 'Dave Tablet'), 'The other device\'s remembered sign-in no longer works');
        bms_acct_expect(bms_acct_account_signed_in($laptop, '127.0.0.6', 'Dave Laptop'), 'This browser stays signed in');
        $laptopDevice = ['bms_remember' => $laptop['bms_remember'] ?? ''];
        bms_acct_expect(($laptopDevice['bms_remember'] ?? '') !== '' && bms_acct_account_signed_in($laptopDevice, '127.0.0.6', 'Dave Laptop'), 'This browser keeps its own remembered sign-in');
    });

    // ------------------------------------------------------------------
    bms_acct_section('verification', 'Verification links', static function () use ($ownerId): void {
        global $ownerPassword;
        $owner = bms_acct_admin_login('owner', $ownerPassword, '127.0.0.9');
        $register = static function (string $username) {
            $visitor = [];
            $page = bms_acct_request('/account.php', $visitor, null, '127.0.0.10');
            $before = bms_acct_mail_files();
            bms_acct_request('/account.php', $visitor, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'register', 'company_url' => '', 'username' => $username, 'display_name' => ucfirst($username), 'email' => $username . '@example.test', 'password' => 'Reader-Passphrase-48', 'confirm_password' => 'Reader-Passphrase-48'], '127.0.0.10');
            return bms_acct_mail_link(bms_acct_new_mail($before), 'verify');
        };

        $token = $register('latereader');
        $userId = (int)(bms_acct_user('latereader')['id'] ?? 0);
        $page = bms_acct_request('/admin/users.php', $owner, null, '127.0.0.9');
        bms_acct_request('/admin/users.php', $owner, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'deactivate', 'user_id' => $userId], '127.0.0.9');
        $deactivated = bms_acct_user('latereader');
        bms_acct_expect($token !== '' && ($deactivated['status'] ?? '') === 'inactive', 'The owner deactivates a pending account before it verifies');
        bms_acct_expect(bms_acct_is_null($deactivated, 'verification_token_hash'), 'Deactivating an account clears its verification link');
        $visitor = [];
        $response = bms_acct_request('/account.php?action=verify&token=' . $token, $visitor, null, '127.0.0.10');
        bms_acct_expect((bms_acct_user('latereader')['status'] ?? '') === 'inactive', 'The old verification link does not reactivate a deactivated account');
        bms_acct_expect(str_contains($response['body'], 'Verification link is invalid or expired'), 'The visitor sees that the link is invalid');

        // A link an older version left on a deactivated account is refused too.
        $staleToken = bin2hex(random_bytes(32));
        global $pdo, $prefix;
        $pdo->prepare("UPDATE `{$prefix}users` SET status = 'inactive', email_verified_at = NULL, verification_token_hash = ?, verification_token_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE username = 'leo'")
            ->execute([hash('sha256', $staleToken)]);
        bms_acct_request('/account.php?action=verify&token=' . $staleToken, $visitor, null, '127.0.0.10');
        bms_acct_expect((bms_acct_user('leo')['status'] ?? '') === 'inactive', 'A verification link left on an inactive account does not activate it');
        $pdo->prepare("UPDATE `{$prefix}users` SET status = 'active', email_verified_at = NULL, verification_token_hash = ?, verification_token_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE username = 'leo'")
            ->execute([hash('sha256', $staleToken)]);
        bms_acct_request('/account.php?action=verify&token=' . $staleToken, $visitor, null, '127.0.0.10');
        bms_acct_expect((bms_acct_user('leo')['email_verified_at'] ?? null) === null, 'A verification link never applies to an account that is not pending');

        $token = $register('goodreader');
        bms_acct_request('/account.php?action=verify&token=' . $token, $visitor, null, '127.0.0.10');
        $verified = bms_acct_user('goodreader');
        bms_acct_expect($token !== '' && ($verified['status'] ?? '') === 'active' && ($verified['email_verified_at'] ?? null) !== null, 'A pending account still verifies normally');
    });

    // ------------------------------------------------------------------
    bms_acct_section('email', 'Email changes and unique addresses', static function () use ($hasClaims, $sharedOldestId): void {
        global $ownerPassword;
        global $pdo, $prefix;
        $mia = bms_acct_account_login('mia', BMS_ACCT_PASSWORD, '127.0.0.11');
        $submit = static function (array $fields) use (&$mia): array {
            $page = bms_acct_request('/account.php', $mia, null, '127.0.0.11');
            return bms_acct_request('/account.php', $mia, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'account_details', 'username' => 'mia'] + $fields, '127.0.0.11');
        };
        $submit(['email' => 'mia-new@example.test']);
        bms_acct_expect((bms_acct_user('mia')['email'] ?? '') === 'mia@example.test', 'An email change without the current password is refused');
        $submit(['email' => 'mia-new@example.test', 'current_password' => 'not-the-password']);
        bms_acct_expect((bms_acct_user('mia')['email'] ?? '') === 'mia@example.test', 'An email change with a wrong password is refused');

        $before = bms_acct_mail_files();
        $submit(['email' => 'mia-new@example.test', 'current_password' => BMS_ACCT_PASSWORD]);
        $pending = bms_acct_user('mia');
        $newMail = bms_acct_new_mail($before);
        $token = bms_acct_mail_link($newMail, 'confirm_email');
        bms_acct_expect(($pending['email'] ?? '') === 'mia@example.test' && ($pending['pending_email'] ?? '') === 'mia-new@example.test', 'With the password, the new address waits for confirmation and the current one stays');
        bms_acct_expect($token !== '' && str_contains(bms_acct_mail_text($newMail[0] ?? ''), 'To: mia-new@example.test'), 'The confirmation link goes to the new address');
        $visitor = [];
        bms_acct_request('/account.php?action=confirm_email&token=' . $token, $visitor, null, '127.0.0.12');
        $confirmed = bms_acct_user('mia');
        bms_acct_expect(($confirmed['email'] ?? '') === 'mia-new@example.test' && ($confirmed['email_verified_at'] ?? null) !== null && ($confirmed['email_normalized'] ?? '') === 'mia-new@example.test' && bms_acct_is_null($confirmed, 'pending_email'), 'Opening the link applies the verified address and its unique claim');
        bms_acct_request('/account.php?action=confirm_email&token=' . $token, $visitor, null, '127.0.0.12');
        bms_acct_expect((bms_acct_user('mia')['email'] ?? '') === 'mia-new@example.test', 'A used confirmation link does nothing');

        $before = bms_acct_mail_files();
        $response = $submit(['email' => 'OWNER@example.test', 'current_password' => BMS_ACCT_PASSWORD]);
        $after = bms_acct_request('/account.php', $mia, null, '127.0.0.11');
        bms_acct_expect((bms_acct_user('mia')['email'] ?? '') === 'mia-new@example.test' && (bms_acct_user('mia')['pending_email'] ?? null) === null && bms_acct_new_mail($before) === [], 'An address another account uses cannot be requested, in any letter case');
        bms_acct_expect(str_contains($response['body'] . $after['body'], 'already in use by another account'), 'The person is told the address is already in use');

        // Registration with an address that is already in use.
        $visitor = [];
        $page = bms_acct_request('/account.php', $visitor, null, '127.0.0.13');
        bms_acct_request('/account.php', $visitor, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'register', 'company_url' => '', 'username' => 'copycat', 'display_name' => 'Copycat', 'email' => 'Owner@Example.test', 'password' => 'Copycat-Passphrase-49', 'confirm_password' => 'Copycat-Passphrase-49'], '127.0.0.13');
        bms_acct_expect(bms_acct_user('copycat') === [], 'Registration refuses an address another account uses');

        // Admin edits.
        $owner = bms_acct_admin_login('owner', $ownerPassword, '127.0.0.14');
        $carol = bms_acct_user('carol');
        $page = bms_acct_request('/admin/user-edit.php?id=' . $carol['id'], $owner, null, '127.0.0.14');
        $edit = ['csrf_token' => bms_acct_token($page['body']), 'action' => 'update_account', 'user_id' => $carol['id'], 'username' => 'carol', 'display_name' => 'Carol', 'status' => 'active', 'profile_visibility' => 'public', 'email_verified' => '1'];
        bms_acct_request('/admin/user-edit.php?id=' . $carol['id'], $owner, $edit + ['email' => 'mia-new@example.test'], '127.0.0.14');
        bms_acct_expect((bms_acct_user('carol')['email'] ?? '') === 'carol@example.test', 'Admin cannot give an account an address another account uses');
        bms_acct_request('/admin/user-edit.php?id=' . $carol['id'], $owner, $edit + ['email' => 'carol-new@example.test'], '127.0.0.14');
        bms_acct_expect((bms_acct_user('carol')['email'] ?? '') === 'carol-new@example.test', 'Admin still changes another account\'s address directly');

        $self = ['csrf_token' => $edit['csrf_token'], 'action' => 'update_account', 'user_id' => bms_acct_user('owner')['id'], 'username' => 'owner', 'display_name' => 'Owner', 'status' => 'active', 'profile_visibility' => 'public', 'email_verified' => '1'];
        $selfPath = '/admin/user-edit.php?id=' . bms_acct_user('owner')['id'];
        bms_acct_request($selfPath, $owner, $self + ['email' => 'owner-moved@example.test'], '127.0.0.14');
        bms_acct_expect((bms_acct_user('owner')['email'] ?? '') === 'owner@example.test', 'The owner\'s own address cannot be changed in Admin without the current password');
        bms_acct_request($selfPath, $owner, $self + ['email' => 'owner-moved@example.test', 'current_password' => $ownerPassword], '127.0.0.14');
        bms_acct_expect((bms_acct_user('owner')['email'] ?? '') === 'owner-moved@example.test', 'With the current password the owner changes it');

        $page = bms_acct_request('/admin/user.php', $owner, null, '127.0.0.14');
        bms_acct_request('/admin/user.php', $owner, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'account_details', 'username' => 'owner', 'email' => 'owner-again@example.test'], '127.0.0.14');
        bms_acct_expect((bms_acct_user('owner')['email'] ?? '') === 'owner-moved@example.test' && (bms_acct_user('owner')['pending_email'] ?? null) === null, 'Admin Account details refuses an email change without the current password');

        // Legacy shared addresses: deterministic recovery and System Check.
        $visitor = [];
        $page = bms_acct_request('/account.php?action=forgot', $visitor, null, '127.0.0.15');
        $before = bms_acct_mail_files();
        bms_acct_request('/account.php', $visitor, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'forgot_password', 'username_or_email' => 'shared@example.test'], '127.0.0.15');
        $resetFor = bms_acct_row('password_reset_tokens', 'used_at IS NULL ORDER BY id DESC');
        bms_acct_expect(bms_acct_new_mail($before) !== [] && (int)($resetFor['user_id'] ?? 0) === $sharedOldestId, 'Recovery by a shared address reaches the oldest active account');
        $check = bms_acct_request('/admin/system-check.php', $owner, null, '127.0.0.14');
        bms_acct_expect(str_contains($check['body'], 'Account email addresses') && str_contains($check['body'], 'shared-first, shared-second'), 'System Check names the accounts that share an address');
        $shared = [bms_acct_user('shared-first'), bms_acct_user('shared-second')];
        bms_acct_expect(($shared[0]['email'] ?? '') === 'shared@example.test' && ($shared[1]['email'] ?? '') === 'Shared@Example.test', 'Shared addresses are left exactly as stored');

        // The database refuses a second claim on one address.
        $refused = false;
        if ($hasClaims) {
            try {
                $pdo->prepare("UPDATE `{$prefix}users` SET email_normalized = 'mia-new@example.test' WHERE username = 'bob'")->execute();
            } catch (PDOException $e) {
                $refused = true;
            }
        }
        bms_acct_expect($refused, 'A unique index holds one claim per normalized address');
    });

    // ------------------------------------------------------------------
    bms_acct_section('comments', 'Comment limits', static function (): void {
        global $ownerPassword;
        $heidi = bms_acct_account_login('heidi', BMS_ACCT_PASSWORD, '127.0.0.16');
        $statuses = [];
        for ($i = 1; $i <= 6; $i++) {
            $statuses[] = bms_acct_comment($heidi, '127.0.0.16', 'Heidi comment ' . $i)['status'];
        }
        $last = bms_acct_comment($heidi, '127.0.0.16', 'Heidi comment 7');
        bms_acct_expect(array_slice($statuses, 0, 5) === [200, 200, 200, 200, 200], 'A commenter can post several comments in a row');
        bms_acct_expect($statuses[5] === 429 && $last['status'] === 429 && str_contains($last['body'], 'Try again in about'), 'The sixth comment in ten minutes is refused with a clear message');
        $heidiId = (int)bms_acct_user('heidi')['id'];
        bms_acct_expect(bms_acct_count('comments', 'user_id = ?', [$heidiId]) === 5, 'Refused comments are not stored');

        $network = '127.0.0.17';
        foreach (['erin', 'frank'] as $name) {
            $jar = bms_acct_account_login($name, BMS_ACCT_PASSWORD, $network);
            for ($i = 1; $i <= 5; $i++) {
                bms_acct_comment($jar, $network, ucfirst($name) . ' comment ' . $i);
            }
        }
        $grace = bms_acct_account_login('grace', BMS_ACCT_PASSWORD, $network);
        bms_acct_expect(bms_acct_comment($grace, $network, 'Grace from the same network')['status'] === 429, 'Ten comments from one network in ten minutes stop further comments from it');
        $graceElsewhere = bms_acct_account_login('grace', BMS_ACCT_PASSWORD, '127.0.0.18');
        bms_acct_expect(bms_acct_comment($graceElsewhere, '127.0.0.18', 'Grace from another network')['status'] === 200, 'The same commenter can comment from another network');

        $owner = bms_acct_admin_login('owner', $ownerPassword, $network);
        $ownerStatuses = [];
        for ($i = 1; $i <= 7; $i++) {
            $ownerStatuses[] = bms_acct_comment($owner, $network, 'Owner reply ' . $i)['status'];
        }
        bms_acct_expect($ownerStatuses === array_fill(0, 7, 200), 'The site owner is not limited');
    });

    // ------------------------------------------------------------------
    bms_acct_section('first-comment', 'First comment hold', static function (): void {
        global $ownerPassword;
        $ivan = bms_acct_account_login('ivan', BMS_ACCT_PASSWORD, '127.0.0.19');
        bms_acct_comment($ivan, '127.0.0.19', 'Ivan first comment');
        bms_acct_expect((bms_acct_row('comments', "body = 'Ivan first comment'")['status'] ?? '') === 'approved', 'By default a first comment follows the normal approval setting');

        $owner = bms_acct_admin_login('owner', $ownerPassword, '127.0.0.20');
        $page = bms_acct_request('/admin/registration.php', $owner, null, '127.0.0.20');
        bms_acct_expect(str_contains($page['body'], 'name="comments_hold_first_comment"'), 'Registration settings offer the first-comment hold');
        bms_acct_request('/admin/registration.php', $owner, ['csrf_token' => bms_acct_token($page['body']), 'action' => 'save_settings', 'registration_mode' => 'open', 'registration_require_email_verification' => '1', 'registration_honeypot_enabled' => '1', 'comments_hold_first_comment' => '1'], '127.0.0.20');
        bms_acct_expect((bms_acct_row('settings', "setting_key = 'comments_hold_first_comment'")['setting_value'] ?? '') === '1', 'The owner turns the hold on');

        $kate = bms_acct_account_login('kate', BMS_ACCT_PASSWORD, '127.0.0.21');
        $response = bms_acct_comment($kate, '127.0.0.21', 'Kate first comment');
        bms_acct_comment($kate, '127.0.0.21', 'Kate second comment');
        bms_acct_expect((bms_acct_row('comments', "body = 'Kate first comment'")['status'] ?? '') === 'pending' && str_contains($response['body'], 'Comment saved for review.'), 'A new commenter\'s first comment waits for review');
        bms_acct_expect((bms_acct_row('comments', "body = 'Kate second comment'")['status'] ?? '') === 'pending', 'Their comments keep waiting until one is approved');
        bms_acct_comment($ivan, '127.0.0.19', 'Ivan later comment');
        bms_acct_expect((bms_acct_row('comments', "body = 'Ivan later comment'")['status'] ?? '') === 'approved', 'A commenter with an approved comment is not held');

        $commentId = (int)(bms_acct_row('comments', "body = 'Kate first comment'")['id'] ?? 0);
        $page = bms_acct_request('/admin/comments.php?status=pending', $owner, null, '127.0.0.20');
        bms_acct_request('/admin/comments.php?status=pending', $owner, ['csrf_token' => bms_acct_token($page['body']), 'moderation_action' => 'approve', 'comment_id' => $commentId], '127.0.0.20');
        bms_acct_comment($kate, '127.0.0.21', 'Kate third comment');
        bms_acct_expect((bms_acct_row('comments', "body = 'Kate third comment'")['status'] ?? '') === 'approved', 'After the owner approves one, the commenter\'s comments follow the normal setting');
    });

    fwrite(STDOUT, "\nAccounts runtime test: " . ($checks - count($failures)) . ' passed, ' . count($failures) . " failed.\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($failures !== [] && is_file($temporary . '/http.log')) {
        fwrite(STDERR, "HTTP server log (tail):\n" . substr((string)file_get_contents($temporary . '/http.log'), -3000) . "\n");
    }
    if ($pdo instanceof PDO) {
        $tables = $pdo->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$table) . '`');
        }
    }
    bms_acct_remove_tree($temporary);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED ' . $failure . "\n");
    }
    exit(1);
}
