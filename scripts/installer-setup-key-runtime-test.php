<?php
/**
 * First-run setup key: HTTP regression test of the real installer.
 *
 * A disposable copy of the application is served over loopback HTTP with
 * private-folder deny rules. Two local listeners record every connection
 * the installer makes: a fake MySQL server on the Unix socket PHP uses for
 * the database host "localhost", and a fake web server that a forged Host
 * header points the private-folder probe at.
 *
 * Covers, through real HTTP requests:
 * - without the setup key, no step shows settings or proceeds, and no
 *   database connection or network request is made;
 * - wrong keys are limited per network, a correct key is refused while
 *   limited, and a new key starts the limit over;
 * - a session that entered an older key must enter the current one;
 * - with the key, every database connection failure shows one generic
 *   message, the install completes, the key file is deleted, Admin works,
 *   and a re-run of the installer is refused.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_installer_ci_* tables are created
 * and removed.
 *
 * By default the test serves the copy with the PHP built-in server. To run
 * the same checks against Apache or Nginx with PHP-FPM, set:
 *   BMS_INSTALLER_TEST_BASE_URL  the URL that serves the copy
 *   BMS_INSTALLER_TEST_ROOT      the document root, a clean uninstalled copy
 *   BMS_INSTALLER_TEST_SOCKET    the pdo_mysql.default_socket the server's
 *                                PHP uses; the test listens there
 * The test never removes an external document root.
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
        fwrite(STDERR, $required . " is required.\n");
        exit(1);
    }
}
if (strtolower((string)getenv('BMS_DB_HOST')) === 'localhost') {
    // "localhost" means the Unix socket, which this test replaces with a listener.
    fwrite(STDERR, "BMS_DB_HOST must name a TCP host such as 127.0.0.1, not localhost.\n");
    exit(1);
}

$source = dirname(__DIR__);
$externalBase = rtrim((string)getenv('BMS_INSTALLER_TEST_BASE_URL'), '/');
$external = $externalBase !== '';
$temporary = sys_get_temp_dir() . '/bms-installer-key-' . bin2hex(random_bytes(6));
$root = $external ? rtrim((string)getenv('BMS_INSTALLER_TEST_ROOT'), '/') : $temporary . '/site';
$socket = $external ? (string)getenv('BMS_INSTALLER_TEST_SOCKET') : $temporary . '/mysql.sock';
$prefix = 'bms_installer_ci_' . bin2hex(random_bytes(4)) . '_';
$processes = [];
$checks = 0;
$failures = [];

function bms_installer_expect(bool $condition, string $label): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        fwrite(STDOUT, 'PASS ' . $label . "\n");
        return;
    }
    $failures[] = $label;
    fwrite(STDOUT, 'FAIL ' . $label . "\n");
}

/**
 * @param array<string,string> $cookies
 * @param array<string,mixed>|null $form
 * @param list<string> $headers
 * @return array{status:int,body:string,location:string}
 */
function bms_installer_request(string $base, string $path, array &$cookies, ?array $form = null, array $headers = []): array
{
    $headers = array_merge(['User-Agent: Bonumark Installer Regression', 'Connection: close'], $headers);
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($form);
    }
    $options['header'] = implode("\r\n", $headers);
    $body = @file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
    if ($body === false || !isset($http_response_header[0])) {
        throw new RuntimeException('HTTP request failed: ' . $path);
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    $location = '';
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location];
}

function bms_installer_token(string $html): string
{
    if (!preg_match('/name="install_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing installer token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_installer_flash(string $html): string
{
    return preg_match('/class="flash error"[^>]*>(.*?)<\/div>/s', $html, $match) === 1
        ? trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8'))
        : '';
}

function bms_installer_key_on_disk(string $root): string
{
    $contents = is_file($root . '/_bonumark_stream/setup-key.php') ? (string)file_get_contents($root . '/_bonumark_stream/setup-key.php') : '';
    return preg_match('/Setup key: ([A-Z0-9-]+)/', $contents, $match) === 1 ? $match[1] : '';
}

function bms_installer_lines(string $path): int
{
    clearstatcache(true, $path);
    return is_file($path) ? count(array_filter(explode("\n", (string)file_get_contents($path)))) : 0;
}

function bms_installer_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($path);
}

/** Wait until a local listener accepts connections. */
function bms_installer_wait_for(string $address): void
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $client = @stream_socket_client($address, $errorCode, $errorMessage, 0.1);
        if ($client !== false) {
            fclose($client);
            return;
        }
        usleep(50000);
    }
    throw new RuntimeException('Listener did not start: ' . $address);
}

try {
    mkdir($temporary, 0700);
    if ($external) {
        if ($root === '' || !is_file($root . '/install.php') || $socket === '') {
            throw new RuntimeException('External mode needs BMS_INSTALLER_TEST_ROOT with a clean copy and BMS_INSTALLER_TEST_SOCKET.');
        }
        if (is_file($root . '/_bonumark_stream/config.php') || is_file($root . '/_bonumark_stream/installed.lock')) {
            throw new RuntimeException('External document root is already installed.');
        }
    } else {
        mkdir($root, 0700);
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file) use ($source): bool {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1));
            // Never copy an installed checkout's credentials, key, backups,
            // uploads or generated exports into the disposable document root.
            return !$file->isLink()
                && !in_array($relative, [
                    '.git', '_bonumark_stream/config.php', '_bonumark_stream/installed.lock', '_bonumark_stream/setup-key.php',
                    '_bonumark_stream/data', '_bonumark_stream/tmp', '_bonumark_stream/backups',
                    'media', 'index.html', 'stream', 'feed.xml', 'sitemap.xml', 'sitemap.xsl', 'robots.txt',
                ], true)
                && preg_match('/\.(?:log|sql|sqlite|zip|tar|tar\.gz|tgz)$/i', $relative) !== 1;
        }), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $target = $root . substr($file->getPathname(), strlen($source));
            $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
        }
        foreach (['media', '_bonumark_stream/data', '_bonumark_stream/backups'] as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                mkdir($root . '/' . $directory, 0700, true);
            }
        }
        mkdir($temporary . '/sessions', 0700);
        // The built-in server ignores .htaccess. Deny the private folders the
        // way the shipped Apache and Nginx rules do.
        file_put_contents($temporary . '/router.php', "<?php\n\$path = (string)parse_url((string)(\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);\nif (preg_match('#^/(?:_bonumark_stream|scripts)(?:/|\$)#', \$path) === 1) {\n    http_response_code(403);\n    echo 'Forbidden';\n    return true;\n}\nreturn false;\n");
    }

    // A listener that records each connection, then closes it at once.
    $listenerScript = $temporary . '/listener.php';
    file_put_contents($listenerScript, "<?php\n[\$self, \$address, \$log] = \$argv;\n\$server = stream_socket_server(\$address, \$errorCode, \$errorMessage);\nif (\$server === false) {\n    fwrite(STDERR, \$errorMessage);\n    exit(1);\n}\nif (str_starts_with(\$address, 'unix://')) {\n    chmod(substr(\$address, 7), 0777);\n}\nfwrite(STDOUT, (string)stream_socket_get_name(\$server, false) . \"\\n\");\nwhile (true) {\n    \$connection = @stream_socket_accept(\$server, 3600);\n    if (\$connection === false) {\n        continue;\n    }\n    file_put_contents(\$log, gmdate('c') . \"\\n\", FILE_APPEND | LOCK_EX);\n    fclose(\$connection);\n}\n");
    $start = static function (string $address, string $log) use ($listenerScript, $temporary, &$processes): string {
        $process = proc_open([PHP_BINARY, '-n', $listenerScript, $address, $log], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $temporary . '/listener-errors.log', 'a']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a local listener.');
        }
        fclose($pipes[0]);
        $name = trim((string)fgets($pipes[1]));
        fclose($pipes[1]);
        $processes[] = $process;
        return $name;
    };
    @unlink($socket);
    $databaseLog = $temporary . '/database-connections.log';
    $probeLog = $temporary . '/probe-connections.log';
    // Each listener prints its address only once it is listening.
    $start('unix://' . $socket, $databaseLog);
    $probeAddress = $start('tcp://127.0.0.1:0', $probeLog);
    clearstatcache(true, $socket);
    if (!file_exists($socket) || preg_match('/^127\.0\.0\.1:\d+$/', $probeAddress) !== 1) {
        throw new RuntimeException('The local listeners did not start.');
    }
    $newDatabaseConnections = static fn(): int => bms_installer_lines($databaseLog);
    $newProbeConnections = static fn(): int => bms_installer_lines($probeLog);

    if ($external) {
        $base = $externalBase;
    } else {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($listener === false) {
            throw new RuntimeException('Cannot reserve a loopback port: ' . $errorMessage);
        }
        $address = (string)stream_socket_get_name($listener, false);
        fclose($listener);
        $base = 'http://' . $address;
        // Two workers let the installer's private-folder probe reach this
        // same server while the request that runs it is still open.
        $environment = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '2']);
        $server = proc_open([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $socket, '-d', 'session.save_path=' . $temporary . '/sessions', '-d', 'log_errors=1', '-d', 'error_log=' . $temporary . '/php-errors.log', '-S', $address, '-t', $root, $temporary . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $root, $environment);
        if (!is_resource($server)) {
            throw new RuntimeException('Could not start the isolated PHP HTTP server.');
        }
        fclose($pipes[0]);
        $processes[] = $server;
        bms_installer_wait_for('tcp://' . $address);
    }
    fwrite(STDOUT, 'Runtime: ' . ($external ? 'external server ' . $base : 'PHP ' . PHP_VERSION . ' built-in server') . "\n");

    $dbHost = (string)getenv('BMS_DB_HOST');
    $dbForm = static fn(string $token, string $host, string $password): array => [
        'install_token' => $token, 'db_name' => (string)getenv('BMS_DB_NAME'), 'db_user' => (string)getenv('BMS_DB_USER'),
        'db_password' => $password, 'db_host' => $host, 'table_prefix' => $prefix,
    ];
    $adminForm = static fn(string $token): array => [
        'install_token' => $token, 'site_name' => 'Setup Key Fixture', 'site_tagline' => 'Disposable', 'timezone' => 'UTC',
        'username' => 'owner', 'display_name' => 'Owner', 'email' => 'owner@example.test',
        'password' => 'Correct-Horse-Battery-45!', 'confirm_password' => 'Correct-Horse-Battery-45!',
    ];

    // 1. Without the setup key nothing proceeds.
    $visitor = [];
    $first = bms_installer_request($base, '/install.php', $visitor);
    $keyOnDisk = bms_installer_key_on_disk($root);
    bms_installer_expect($first['status'] === 200 && str_contains($first['body'], 'Enter your setup key') && str_contains($first['body'], '_bonumark_stream/setup-key.php'), 'The first installer screen asks for the setup key and says where it is');
    bms_installer_expect(preg_match('/^[A-Z2-9]{4}(?:-[A-Z2-9]{4}){5}$/', $keyOnDisk) === 1, 'The first visit writes a setup key into the private folder');
    bms_installer_expect($keyOnDisk !== '' && !str_contains($first['body'], $keyOnDisk) && !str_contains($first['body'], str_replace('-', '', $keyOnDisk)), 'The setup key is never shown in the page');
    bms_installer_expect(!str_contains($first['body'], 'Server check') && !str_contains($first['body'], 'name="db_host"') && !str_contains($first['body'], 'PHP version'), 'Without the key the installer shows no server details or settings');
    $direct = bms_installer_request($base, '/_bonumark_stream/setup-key.php', $visitor);
    bms_installer_expect($direct['status'] !== 200 && !str_contains($direct['body'], $keyOnDisk), 'The key file cannot be read over HTTP');

    bms_installer_request($base, '/install.php', $visitor, null, ['Host: ' . $probeAddress]);
    bms_installer_request($base, '/install.php?step=database', $visitor, null, ['Host: ' . $probeAddress]);
    bms_installer_expect($newProbeConnections() === 0, 'Without the key a forged Host header makes the installer send no request');

    // A form token comes from whichever installer page this session can see.
    $token = str_contains($first['body'], 'name="install_token"')
        ? bms_installer_token($first['body'])
        : bms_installer_token(bms_installer_request($base, '/install.php?step=database', $visitor)['body']);
    $databaseStep = bms_installer_request($base, '/install.php?step=database', $visitor, $dbForm($token, 'localhost', 'not-checked'));
    bms_installer_expect($databaseStep['status'] === 403 && str_contains($databaseStep['body'], 'Enter the setup key before continuing'), 'Without the key the database step is refused');
    $setupStep = bms_installer_request($base, '/install.php?step=setup', $visitor, $adminForm($token));
    bms_installer_expect($setupStep['status'] === 403 && !is_file($root . '/_bonumark_stream/config.php') && !is_file($root . '/_bonumark_stream/installed.lock'), 'Without the key the setup step is refused and nothing is written');
    $stranger = [];
    $noToken = bms_installer_request($base, '/install.php?step=database', $stranger, $dbForm('', 'localhost', 'not-checked'));
    bms_installer_expect($noToken['status'] === 403, 'A database step without a session or token is refused');
    bms_installer_expect($newDatabaseConnections() === 0, 'Without the key no database connection is attempted');

    // 2. Wrong keys are limited per network.
    $wrong = [];
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $wrong[] = bms_installer_request($base, '/install.php?step=key', $visitor, ['install_token' => $token, 'setup_key' => 'WRNG-KEYS-' . $attempt . 'AAA-BBBB-CCCC-DDDD']);
    }
    bms_installer_expect(array_map(static fn(array $response): int => $response['status'], $wrong) === [403, 403, 403, 403, 403] && str_contains($wrong[0]['body'], 'That setup key is not correct'), 'Wrong setup keys are refused');
    $limited = bms_installer_request($base, '/install.php?step=key', $visitor, ['install_token' => $token, 'setup_key' => $keyOnDisk]);
    bms_installer_expect($limited['status'] === 429 && str_contains($limited['body'], 'Too many wrong setup keys'), 'After five wrong keys even the correct key is refused for a while');
    $attempts = is_file($root . '/_bonumark_stream/tmp/setup-key-attempts.json') ? (string)file_get_contents($root . '/_bonumark_stream/tmp/setup-key-attempts.json') : '';
    bms_installer_expect($attempts !== '' && !str_contains($attempts, '127.0.0.1') && !str_contains($attempts, str_replace('-', '', $keyOnDisk)), 'The attempt record holds no address and no key');

    // Deleting the key file gives a new key and starts the limit over.
    @unlink($root . '/_bonumark_stream/setup-key.php');
    $again = bms_installer_request($base, '/install.php', $visitor);
    $secondKey = bms_installer_key_on_disk($root);
    bms_installer_expect($secondKey !== '' && $secondKey !== $keyOnDisk && str_contains($again['body'], 'Enter your setup key'), 'Deleting the key file makes the installer write a new key');
    // When an attempt cannot be recorded, the key is not even compared.
    $attemptsPath = $root . '/_bonumark_stream/tmp/setup-key-attempts.json';
    @unlink($attemptsPath);
    mkdir($attemptsPath);
    $unrecorded = bms_installer_request($base, '/install.php?step=key', $visitor, ['install_token' => bms_installer_token($again['body']), 'setup_key' => $secondKey]);
    rmdir($attemptsPath);
    bms_installer_expect($unrecorded['status'] === 503 && str_contains($unrecorded['body'], 'could not record setup key attempts'), 'A correct key is refused while attempts cannot be recorded');
    $accepted = bms_installer_request($base, '/install.php?step=key', $visitor, ['install_token' => bms_installer_token($again['body']), 'setup_key' => strtolower(str_replace('-', ' ', $secondKey))]);
    bms_installer_expect($accepted['status'] === 302 && str_ends_with($accepted['location'], 'install.php'), 'The new key is accepted, in lowercase and with spaces');
    bms_installer_expect($newDatabaseConnections() === 0, 'Entering keys attempts no database connection');

    // 3. A session that entered an older key must enter the current one.
    $welcome = bms_installer_request($base, '/install.php', $visitor);
    bms_installer_expect($welcome['status'] === 200 && str_contains($welcome['body'], 'Server check') && str_contains($welcome['body'], 'Start setup'), 'With the key the server check is shown');
    @unlink($root . '/_bonumark_stream/setup-key.php');
    $rotated = bms_installer_request($base, '/install.php?step=database', $visitor);
    $thirdKey = bms_installer_key_on_disk($root);
    bms_installer_expect(str_contains($rotated['body'], 'Enter your setup key') && !str_contains($rotated['body'], 'name="db_host"'), 'A new key file ends sessions that entered the old key');
    $accepted = bms_installer_request($base, '/install.php?step=key', $visitor, ['install_token' => bms_installer_token($rotated['body']), 'setup_key' => $thirdKey]);
    bms_installer_expect($accepted['status'] === 302, 'The current key is accepted');

    // 4. With the key, database connection failures all look the same.
    $databasePage = bms_installer_request($base, '/install.php?step=database', $visitor);
    $token = bms_installer_token($databasePage['body']);
    bms_installer_expect(str_contains($databasePage['body'], 'name="db_host"'), 'With the key the database step shows its form');
    $messages = [];
    foreach (['A closed socket' => ['localhost', 'not-checked'], 'An unknown host' => ['bms-installer-test.invalid', 'not-checked'], 'A wrong password' => [$dbHost, 'wrong-' . bin2hex(random_bytes(4))]] as $label => [$host, $password]) {
        $response = bms_installer_request($base, '/install.php?step=database', $visitor, $dbForm($token, $host, $password));
        $messages[$label] = bms_installer_flash($response['body']);
        bms_installer_expect($response['status'] === 200 && str_contains($messages[$label], 'could not connect to the database'), $label . ' shows the generic connection message');
    }
    bms_installer_expect($newDatabaseConnections() === 1, 'With the key the database step connects to the host it is given');
    bms_installer_expect(count(array_unique($messages)) === 1, 'Every connection failure shows exactly the same message');
    $leaks = array_filter(['SQLSTATE', 'refused', 'denied', 'getaddrinfo', 'gone away', 'Lost connection', 'No such file', 'bms-installer-test.invalid', $dbHost], static fn(string $needle): bool => stripos((string)reset($messages), $needle) !== false);
    bms_installer_expect($leaks === [], 'The connection message names no cause, host, or server error');

    $connected = bms_installer_request($base, '/install.php?step=database', $visitor, $dbForm($token, $dbHost, (string)getenv('BMS_DB_PASS')));
    bms_installer_expect($connected['status'] === 302 && str_contains($connected['location'], 'step=setup'), 'Correct database details continue to site setup');
    $setupPage = bms_installer_request($base, '/install.php?step=setup', $visitor);
    bms_installer_expect($setupPage['status'] === 200 && str_contains($setupPage['body'], 'Verified:'), 'Site setup verifies the private folder is blocked');
    $installed = bms_installer_request($base, '/install.php?step=setup', $visitor, $adminForm(bms_installer_token($setupPage['body'])));
    bms_installer_expect($installed['status'] === 302 && str_ends_with(rtrim($installed['location'], '/'), '/admin'), 'Installation completes with the key');
    clearstatcache();
    bms_installer_expect(is_file($root . '/_bonumark_stream/config.php') && is_file($root . '/_bonumark_stream/installed.lock'), 'Installation writes config.php and installed.lock');
    bms_installer_expect(!is_file($root . '/_bonumark_stream/setup-key.php') && !is_file($root . '/_bonumark_stream/tmp/setup-key-attempts.json'), 'Installation deletes the setup key and its attempt record');
    bms_installer_expect(bms_installer_request($base, '/admin/', $visitor)['status'] === 200, 'The new owner is signed in to Admin');

    // 5. A re-run on the installed site is still refused.
    $before = $newDatabaseConnections();
    $rerun = bms_installer_request($base, '/install.php', $stranger);
    $rerunPost = bms_installer_request($base, '/install.php?step=database', $stranger, $dbForm('', 'localhost', 'not-checked'));
    $rerunKey = bms_installer_request($base, '/install.php?step=key', $stranger, ['setup_key' => $thirdKey]);
    bms_installer_expect($rerun['status'] === 302 && str_contains($rerun['location'], 'login.php') && $rerunPost['status'] === 302 && $rerunKey['status'] === 302, 'The installer refuses to run again on the installed site');
    bms_installer_expect($newDatabaseConnections() === $before && !is_file($root . '/_bonumark_stream/setup-key.php'), 'The refused re-run connects to nothing and writes no new key');
    $login = [];
    $loginPage = bms_installer_request($base, '/admin/login.php', $login);
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $csrf);
    $signIn = bms_installer_request($base, '/admin/login.php', $login, ['csrf_token' => html_entity_decode((string)($csrf[1] ?? ''), ENT_QUOTES, 'UTF-8'), 'username' => 'owner', 'password' => 'Correct-Horse-Battery-45!']);
    bms_installer_expect($signIn['status'] === 302 && bms_installer_request($base, '/admin/', $login)['status'] === 200, 'The installed owner can sign in');

    fwrite(STDOUT, "\nInstaller setup key runtime test: " . ($checks - count($failures)) . ' passed, ' . count($failures) . " failed.\n");
} catch (Throwable $e) {
    bms_installer_expect(false, 'The installer test ran without error (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    foreach (array_reverse($processes) as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    if ($failures !== []) {
        foreach (['http.log', 'php-errors.log', 'listener-errors.log'] as $log) {
            if (is_file($temporary . '/' . $log)) {
                fwrite(STDERR, $log . ":\n" . substr((string)file_get_contents($temporary . '/' . $log), -3000) . "\n");
            }
        }
    }
    try {
        $pdo = new PDO('mysql:host=' . getenv('BMS_DB_HOST') . ';dbname=' . getenv('BMS_DB_NAME') . ';charset=utf8mb4', (string)getenv('BMS_DB_USER'), (string)getenv('BMS_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($pdo->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$table) . '`');
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Could not remove test tables: ' . $e->getMessage() . "\n");
    }
    if (!$external) {
        bms_installer_remove_tree($root);
    }
    @unlink($socket);
    bms_installer_remove_tree($temporary);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED ' . $failure . "\n");
    }
    exit(1);
}
