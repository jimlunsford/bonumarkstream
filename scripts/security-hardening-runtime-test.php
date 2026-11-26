<?php
/**
 * Security hardening HTTP regression test against a disposable install.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_security_ci_* tables are created and
 * removed. Application files, sessions, mail captures, and the loopback HTTP
 * server are temporary.
 *
 * Covers, through real HTTP requests: the minimal unauthenticated API status,
 * theme ZIP SVG refusal and visible validation errors, the config-only
 * Sendmail path with working message delivery, absolute verification links,
 * and Markdown output in a rendered post and Profile.
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
$temporary = sys_get_temp_dir() . '/bms-security-runtime-' . bin2hex(random_bytes(6));
$prefix = 'bms_security_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;
$failures = [];

/** Record a check. Failures are collected so one run reports every gap. */
function bms_security_runtime_expect(bool $condition, string $label): void
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
 * @param array{field:string,name:string,type:string,bytes:string}|null $upload
 * @param list<string> $extraHeaders
 */
function bms_security_runtime_request(string $base, string $path, array &$cookies, ?array $form = null, ?array $upload = null, array $extraHeaders = []): array
{
    $headers = array_merge(['User-Agent: Bonumark Security Regression', 'Connection: close'], $extraHeaders);
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15];
    if ($form !== null) {
        if ($upload !== null) {
            $boundary = 'bms-security-test-' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
            $body = '';
            foreach ($form as $key => $value) {
                $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $key . "\"\r\n\r\n" . $value . "\r\n";
            }
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $upload['field'] . '"; filename="' . $upload['name'] . "\"\r\nContent-Type: " . $upload['type'] . "\r\n\r\n" . $upload['bytes'] . "\r\n--" . $boundary . "--\r\n";
            $options['content'] = $body;
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options['content'] = http_build_query($form);
        }
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

function bms_security_runtime_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_security_runtime_zip(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'bms-security-zip-');
    $zip = new ZipArchive();
    if ($path === false || $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not build a test ZIP.');
    }
    foreach ($files as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    $zip->close();
    $bytes = (string)file_get_contents($path);
    @unlink($path);
    return $bytes;
}

function bms_security_runtime_theme_manifest(string $slug, string $screenshot, array $images, array $css): string
{
    return (string)json_encode([
        'name' => 'Runtime Fixture ' . $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'Bonumark test',
        'description' => 'Disposable theme for the security runtime test.', 'screenshot' => $screenshot,
        'assets' => ['css' => $css, 'images' => $images, 'fonts' => []],
    ], JSON_UNESCAPED_SLASHES);
}

/** Attribute names on every element of an HTML document fragment. */
function bms_security_runtime_attribute_names(string $html): array
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $names = [];
    foreach ($document->getElementsByTagName('*') as $element) {
        if ($element instanceof DOMElement && $element->hasAttributes()) {
            foreach ($element->attributes as $attribute) {
                $names[strtolower($attribute->name)] = true;
            }
        }
    }
    return array_keys($names);
}

function bms_security_runtime_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($path);
}

function bms_security_runtime_capture(string $path): string
{
    clearstatcache(true, $path);
    return is_file($path) ? (string)file_get_contents($path) : '';
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

    // Two fake Sendmail programs: the one config.php names, and one an Admin
    // form submission might try to select. Each records its arguments and the
    // message it reads from standard input.
    $configSendmail = $temporary . '/mail/config-sendmail';
    $adminSendmail = $temporary . '/mail/admin-sendmail';
    foreach ([$configSendmail => 'config', $adminSendmail => 'admin'] as $program => $label) {
        file_put_contents($program, "#!/bin/sh\nn=\$(date +%s%N)\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($temporary . '/mail/' . $label) . "-\$n.argv\ncat > " . escapeshellarg($temporary . '/mail/' . $label) . "-\$n.eml\nexit 0\n");
        chmod($program, 0700);
    }

    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException('Cannot reserve a loopback port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $config = [
        'site_name' => 'Security Fixture', 'site_tagline' => 'Disposable', 'site_admin_email' => 'owner@example.test',
        'timezone' => 'UTC', 'base_url' => $base, 'base_path' => '', 'security_salt' => bin2hex(random_bytes(24)),
        'scheduled_tasks_public_traffic_enabled' => '0', 'scheduled_tasks_heartbeat_enabled' => '0',
        'activitypub_enabled' => '0', 'pwa_enabled' => '0', 'mail_transport' => 'disabled',
        'mail_sendmail_path' => $configSendmail,
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
    $insertUser = $pdo->prepare("INSERT INTO `{$prefix}users` (username,display_name,email,email_verified_at,password_hash,role,status,created_at,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(),?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $insertUser->execute(['owner', 'Owner', 'owner@example.test', password_hash('disposable-password', PASSWORD_DEFAULT), 'admin']);
    $ownerId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='owner'")->fetchColumn();
    $setting = static function (string $key) use ($pdo, $prefix): ?string {
        $statement = $pdo->prepare("SELECT setting_value FROM `{$prefix}settings` WHERE setting_key=?");
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string)$value;
    };

    $process = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=8M', '-d', 'post_max_size=9M', '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary);
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

    $owner = [];
    $login = bms_security_runtime_request($base, '/admin/login.php', $owner);
    $login = bms_security_runtime_request($base, '/admin/login.php', $owner, ['csrf_token' => bms_security_runtime_token($login['body']), 'username' => 'owner', 'password' => 'disposable-password']);
    if ($login['status'] !== 302) {
        throw new RuntimeException('Owner fixture could not sign in.');
    }

    // #54: the unauthenticated status payload carries no version or config.
    $guest = [];
    $status = bms_security_runtime_request($base, '/api/v1/status.php', $guest);
    $statusJson = json_decode($status['body'], true);
    bms_security_runtime_expect($status['status'] === 200 && is_array($statusJson) && array_keys($statusJson) === ['ok', 'api', 'authenticated'], 'Unauthenticated API status returns only ok, api, and authenticated');
    bms_security_runtime_expect(!str_contains($status['body'], bms_version()) && !str_contains($status['body'], 'remote_posting_enabled'), 'Unauthenticated API status discloses no version or API setting');

    $remote = bms_security_runtime_request($base, '/admin/remote-posting.php', $owner);
    $csrf = bms_security_runtime_token($remote['body']);
    bms_security_runtime_request($base, '/admin/remote-posting.php', $owner, ['csrf_token' => $csrf, 'action' => 'save_settings', 'remote_posting_enabled' => 1, 'remote_posting_rate_limit_per_minute' => 60]);
    $created = bms_security_runtime_request($base, '/admin/remote-posting.php', $owner, ['csrf_token' => $csrf, 'action' => 'create_token', 'token_name' => 'Security fixture', 'scopes' => ['status:read'], 'expires_date' => '2099-01-01']);
    preg_match('/<textarea[^>]*id="new_api_token"[^>]*>([^<]+)<\/textarea>/', $created['body'], $tokenMatch);
    $apiToken = html_entity_decode($tokenMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
    $authenticated = bms_security_runtime_request($base, '/api/v1/status.php', $guest, null, null, ['Authorization: Bearer ' . $apiToken]);
    $authenticatedJson = json_decode($authenticated['body'], true);
    bms_security_runtime_expect($apiToken !== '' && $authenticated['status'] === 200 && ($authenticatedJson['version'] ?? '') === bms_version() && ($authenticatedJson['authenticated'] ?? false) === true, 'Authenticated API status still reports the version and settings');
    $anonymousAgain = json_decode(bms_security_runtime_request($base, '/api/v1/status.php', $guest)['body'], true);
    bms_security_runtime_expect(is_array($anonymousAgain) && !array_key_exists('version', $anonymousAgain), 'Unauthenticated API status stays minimal while the API is enabled');

    // H1 and #56: theme ZIP refusals reach the owner with the real reason.
    $installPage = bms_security_runtime_request($base, '/admin/theme-install.php', $owner);
    $csrf = bms_security_runtime_token($installPage['body']);
    $svgZip = bms_security_runtime_zip([
        'svg-runtime/theme.json' => bms_security_runtime_theme_manifest('svg-runtime', 'assets/images/evil.svg', ['assets/images/evil.svg'], ['assets/css/theme.css']),
        'svg-runtime/assets/css/theme.css' => "body { color: #000; }\n",
        'svg-runtime/assets/images/evil.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>document.title="x"</script></svg>',
    ]);
    $upload = bms_security_runtime_request($base, '/admin/theme-install.php', $owner, ['csrf_token' => $csrf, 'activate_theme' => '1'], ['field' => 'theme_zip', 'name' => 'svg-runtime.zip', 'type' => 'application/zip', 'bytes' => $svgZip]);
    $after = bms_security_runtime_request($base, '/admin/theme-install.php', $owner);
    bms_security_runtime_expect($upload['status'] === 302 && str_contains($upload['location'], 'theme-install.php') && str_contains($after['body'], 'SVG images are not allowed in themes'), 'Admin theme upload refuses an SVG theme with a clear message');
    bms_security_runtime_expect(!is_dir($temporary . '/_bonumark_stream/themes/svg-runtime') && !is_dir($temporary . '/assets/themes/svg-runtime') && $setting('active_public_theme') !== 'svg-runtime', 'Refused SVG theme is neither installed nor activated');

    $missingAssetZip = bms_security_runtime_zip([
        'missing-asset/theme.json' => bms_security_runtime_theme_manifest('missing-asset', '', [], ['assets/css/theme.css', 'assets/css/missing.css']),
        'missing-asset/assets/css/theme.css' => "body { color: #000; }\n",
    ]);
    $csrf = bms_security_runtime_token($after['body']);
    $upload = bms_security_runtime_request($base, '/admin/theme-install.php', $owner, ['csrf_token' => $csrf], ['field' => 'theme_zip', 'name' => 'missing-asset.zip', 'type' => 'application/zip', 'bytes' => $missingAssetZip]);
    $after = bms_security_runtime_request($base, '/admin/theme-install.php', $owner);
    bms_security_runtime_expect(str_contains($after['body'], 'Theme package is missing a declared asset: assets/css/missing.css'), 'A post-extraction validation error reaches the owner instead of a generic failure');
    bms_security_runtime_expect(!str_contains(bms_security_runtime_capture($temporary . '/http.log'), 'Invalid or uninitialized Zip object'), 'The theme installer closes its ZIP exactly once');

    $validZip = bms_security_runtime_zip([
        'png-runtime/theme.json' => bms_security_runtime_theme_manifest('png-runtime', 'assets/images/screenshot.png', ['assets/images/screenshot.png'], ['assets/css/theme.css']),
        'png-runtime/assets/css/theme.css' => "body { color: #000; }\n",
        'png-runtime/assets/images/screenshot.png' => (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII='),
    ]);
    $csrf = bms_security_runtime_token($after['body']);
    $upload = bms_security_runtime_request($base, '/admin/theme-install.php', $owner, ['csrf_token' => $csrf], ['field' => 'theme_zip', 'name' => 'png-runtime.zip', 'type' => 'application/zip', 'bytes' => $validZip]);
    bms_security_runtime_expect($upload['status'] === 302 && str_contains($upload['location'], 'theme.php') && is_file($temporary . '/assets/themes/png-runtime/assets/images/screenshot.png'), 'A PNG theme still installs through Admin');

    // L2: Admin cannot choose the Sendmail program; the config.php program
    // receives the complete message.
    $mailPage = bms_security_runtime_request($base, '/admin/mail.php', $owner);
    $csrf = bms_security_runtime_token($mailPage['body']);
    bms_security_runtime_expect(!str_contains($mailPage['body'], 'name="mail_sendmail_path"') && str_contains($mailPage['body'], htmlspecialchars($configSendmail, ENT_QUOTES, 'UTF-8')), 'Admin Mail shows the config.php Sendmail path read-only');
    bms_security_runtime_request($base, '/admin/mail.php', $owner, ['csrf_token' => $csrf, 'mail_action' => 'save', 'mail_transport' => 'sendmail', 'mail_from_name' => 'Security Fixture', 'mail_from_email' => 'sender@example.test', 'mail_reply_to' => '', 'mail_smtp_host' => '', 'mail_smtp_port' => 587, 'mail_smtp_encryption' => 'tls', 'mail_smtp_username' => '', 'mail_smtp_password' => '', 'mail_sendmail_path' => $adminSendmail]);
    bms_security_runtime_expect($setting('mail_transport') === 'sendmail' && $setting('mail_sendmail_path') !== $adminSendmail, 'A submitted Sendmail path is not stored');
    bms_security_runtime_request($base, '/admin/mail.php', $owner, ['csrf_token' => $csrf, 'mail_action' => 'test', 'test_recipient' => 'recipient@example.test']);
    $configMessages = glob($temporary . '/mail/config-*.eml') ?: [];
    $adminRuns = glob($temporary . '/mail/admin-*.argv') ?: [];
    $testMessage = $configMessages ? bms_security_runtime_capture((string)end($configMessages)) : '';
    bms_security_runtime_expect($adminRuns === [], 'The Admin-submitted Sendmail program never runs');
    bms_security_runtime_expect(str_contains($testMessage, 'Subject: Bonumark Stream Test Email') && str_contains($testMessage, 'Bonumark Stream can send mail'), 'The config.php Sendmail program receives the complete test message');

    // An earlier version could store a path in settings. It is reported, not used.
    $insertSetting->execute(['mail_sendmail_path', $adminSendmail]);
    $mailPage = bms_security_runtime_request($base, '/admin/mail.php', $owner);
    bms_security_runtime_expect(str_contains($mailPage['body'], 'An earlier version saved') && str_contains($mailPage['body'], htmlspecialchars($adminSendmail, ENT_QUOTES, 'UTF-8')), 'Admin Mail names a legacy stored Sendmail path without using it');
    bms_security_runtime_request($base, '/admin/mail.php', $owner, ['csrf_token' => bms_security_runtime_token($mailPage['body']), 'mail_action' => 'test', 'test_recipient' => 'recipient@example.test']);
    bms_security_runtime_expect((glob($temporary . '/mail/admin-*.argv') ?: []) === [], 'A legacy stored Sendmail path never runs');

    // #57: verification emails carry an absolute link from the stored base URL.
    foreach (['registration_mode' => 'open', 'registration_require_email_verification' => '1', 'registration_require_admin_approval' => '0', 'registration_honeypot_enabled' => '1'] as $key => $value) {
        $insertSetting->execute([$key, $value]);
    }
    $visitor = [];
    $accountPage = bms_security_runtime_request($base, '/index.php?__bonumark_route=account', $visitor);
    $before = glob($temporary . '/mail/config-*.eml') ?: [];
    bms_security_runtime_request($base, '/index.php?__bonumark_route=account', $visitor, ['csrf_token' => bms_security_runtime_token($accountPage['body']), 'action' => 'register', 'company_url' => '', 'username' => 'newreader', 'display_name' => 'New Reader', 'email' => 'reader@example.test', 'password' => 'Correct-Horse-Battery-57!', 'confirm_password' => 'Correct-Horse-Battery-57!']);
    $newMessages = array_values(array_diff(glob($temporary . '/mail/config-*.eml') ?: [], $before));
    $verification = $newMessages ? bms_security_runtime_capture($newMessages[0]) : '';
    $verificationBody = stripos($verification, 'Content-Transfer-Encoding: quoted-printable') !== false ? quoted_printable_decode($verification) : $verification;
    $verificationOk = preg_match('#' . preg_quote($base, '#') . '/account\.php\?action=verify&token=[a-f0-9]{64}#', $verificationBody) === 1;
    bms_security_runtime_expect($verificationOk, 'Verification email contains an absolute link from the stored base URL');
    if (!$verificationOk) {
        fwrite(STDOUT, 'Captured verification message (' . count($newMessages) . " new):\n" . substr($verificationBody, 0, 1500) . "\n");
    }

    // Sendmail recipients that could be read as options or file paths are refused.
    $unsafeVisitor = [];
    $accountPage = bms_security_runtime_request($base, '/index.php?__bonumark_route=account', $unsafeVisitor);
    $before = glob($temporary . '/mail/*.argv') ?: [];
    bms_security_runtime_request($base, '/index.php?__bonumark_route=account', $unsafeVisitor, ['csrf_token' => bms_security_runtime_token($accountPage['body']), 'action' => 'register', 'company_url' => '', 'username' => 'optionreader', 'display_name' => 'Option Reader', 'email' => '-oQ/tmp/x@example.test', 'password' => 'Correct-Horse-Battery-58!', 'confirm_password' => 'Correct-Horse-Battery-58!']);
    $optionRuns = array_values(array_diff(glob($temporary . '/mail/*.argv') ?: [], $before));
    $optionArgs = '';
    foreach ($optionRuns as $run) {
        $optionArgs .= bms_security_runtime_capture($run);
    }
    // Addresses are lowercased before sending, so look for any option-shaped
    // argument other than the -i flag core passes itself.
    $optionShaped = array_filter(preg_split('/\R/', $optionArgs) ?: [], static fn(string $argument): bool => $argument !== '-i' && str_starts_with($argument, '-'));
    bms_security_runtime_expect($optionShaped === [], 'A registration email address shaped like a Sendmail option never reaches Sendmail');

    // M1: rendered post and Profile Markdown carry no injected attribute.
    $payload = '`" onmouseover="alert(1)` [a](/x%%CODE0%%) and `" data-stream-composer-mount data-stream-composer-endpoint="https://evil.example/` https://example.com/%%CODE1%%';
    $pdo->prepare("INSERT INTO `{$prefix}posts` (author_id,title,slug,status,post_type,description,content_body,category,category_slug,date_published,created_at,updated_at,published_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_DATE(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$ownerId, 'Escaping fixture', 'escaping-fixture', 'published', 'stream', 'Escaping fixture', $payload, 'Stream', 'stream']);
    $pdo->prepare("INSERT INTO `{$prefix}user_profiles` (user_id,headline,about_markdown,location,now_text,cover_image_path,links_json,interests_json,featured_items_json,profile_photos_json,show_post_count,show_comment_count,show_member_since,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$ownerId, '', $payload, '', '', '', '[]', '[]', '[]', '[]']);
    foreach (['Stream post' => '/index.php?__bonumark_route=stream&slug=escaping-fixture', 'Profile About' => '/index.php?__bonumark_route=profile&user=owner'] as $label => $path) {
        $page = bms_security_runtime_request($base, $path, $guest);
        $names = bms_security_runtime_attribute_names($page['body']);
        bms_security_runtime_expect($page['status'] === 200 && str_contains($page['body'], 'onmouseover=&quot;alert(1)'), $label . ' renders the code span as text');
        bms_security_runtime_expect(!in_array('onmouseover', $names, true) && !str_contains($page['body'], 'data-stream-composer-endpoint="https://evil.example/'), $label . ' Markdown produces no injected attribute');
    }

    fwrite(STDOUT, "\nSecurity hardening runtime test: " . ($checks - count($failures)) . ' passed, ' . count($failures) . " failed.\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($failures !== [] && is_file($temporary . '/http.log')) {
        fwrite(STDERR, "HTTP server log:\n" . substr((string)file_get_contents($temporary . '/http.log'), -4000) . "\n");
    }
    if ($pdo instanceof PDO) {
        $tables = $pdo->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$table) . '`');
        }
    }
    bms_security_runtime_remove_tree($temporary);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED ' . $failure . "\n");
    }
    exit(1);
}
