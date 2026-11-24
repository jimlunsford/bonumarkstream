<?php
/**
 * Failure-atomic Page creation against a disposable database and HTTP fixture.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_creation_ci_* tables are created and
 * removed. Application files, sessions and the loopback HTTP server are temporary.
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
$temporary = sys_get_temp_dir() . '/bms-creation-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_creation_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_creation_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_creation_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Page Lifecycle Regression', 'Connection: close'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($form);
    }
    $options['header'] = implode("\r\n", $headers);
    $body = file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
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

function bms_creation_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_creation_test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
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

    copy(__DIR__ . '/fixtures/page-creation-test-support.php', $temporary . '/_bonumark_stream/app/page-creation-test-support.php');
    $databasePath = $temporary . '/_bonumark_stream/app/database.php';
    $database = (string)file_get_contents($databasePath);
    $database = str_replace('<?php', "<?php\nrequire_once __DIR__ . '/page-creation-test-support.php';", $database);
    $database = str_replace('$pdo = new PDO($dsn, $user, $pass, [', '$pdo = new BmsPageCreationTestPDO($dsn, $user, $pass, [', $database);
    file_put_contents($databasePath, $database);
    mkdir($temporary . '/sessions', 0700);
    mkdir($temporary . '/media', 0700);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException('Cannot reserve a loopback port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $config = [
        'site_name' => 'Page Lifecycle Fixture', 'site_tagline' => 'Saved tagline',
        'site_admin_email' => 'saved@example.test', 'timezone' => 'America/Indiana/Indianapolis',
        'base_url' => $base, 'base_path' => '', 'security_salt' => bin2hex(random_bytes(24)),
        'scheduled_tasks_public_traffic_enabled' => '0', 'scheduled_tasks_heartbeat_enabled' => '0',
        'activitypub_enabled' => '0', 'pwa_enabled' => '0', 'mail_transport' => 'disabled',
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
        if (is_string($value)) {
            $insertSetting->execute([$key, $value]);
        }
    }
    $insertUser = $pdo->prepare("INSERT INTO `{$prefix}users` (username,display_name,email,email_verified_at,password_hash,role,status,created_at,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(),?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    foreach (['owner' => 'admin', 'commenter' => 'commenter'] as $username => $role) {
        $insertUser->execute([$username, ucfirst($username), $username . '@example.test', password_hash('disposable-password', PASSWORD_DEFAULT), $role]);
    }
    $migrationCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn();
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary);
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
    bms_creation_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_creation_test_request($base, '/admin/login.php', $cookies);
        $login = bms_creation_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => bms_creation_test_token($login['body']), 'username' => $username, 'password' => 'disposable-password']);
        bms_creation_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }

    require_once $temporary . '/_bonumark_stream/app/pages.php';
    $_SESSION['bms_logged_in'] = true;
    $_SESSION['bms_user_id'] = 1;
    $owner = $sessions['owner'];
    $csrf = bms_creation_test_token(bms_creation_test_request($base, '/admin/pages.php', $owner)['body']);
    $snapshot = static function () use ($pdo, $prefix): array {
        $out = [];
        foreach (['posts', 'trash', 'terms', 'post_terms', 'revisions', 'settings', 'media'] as $table) {
            $rows = array_map('serialize', $pdo->query("SELECT * FROM `{$prefix}{$table}`")->fetchAll());
            sort($rows); $out[$table] = $rows;
        }
        return $out;
    };
    $activeRow = static function (string $slug, string $status) use ($pdo, $prefix): ?array {
        $s = $pdo->prepare("SELECT * FROM `{$prefix}posts` WHERE post_type='page' AND slug=? AND status=?"); $s->execute([$slug, $status]); return $s->fetch() ?: null;
    };

    $negative = in_array('--baseline', $argv, true);
    $fields = static fn(string $slug, string $status): array => ['page_title' => 'Page ' . $slug, 'page_slug' => $slug, 'body_markdown' => 'Synthetic **body**.', 'page_date' => '2024-02-29', 'csrf_token' => $csrf, 'page_submit_action' => $status === 'published' ? 'publish' : 'draft'];
    $makePage = static fn(string $slug, string $status): array => bms_parse_markdown_string("---\ntitle: Page $slug\nslug: $slug\nstatus: $status\ncontent_type: page\ncategory: New category $slug\ntags: Existing tag, New tag $slug\ndate: 2024-02-29\ncustom_key: preserved\n---\nSynthetic **body**.\n");
    bms_get_or_create_term('tag', 'Existing tag');
    foreach (['draft', 'published'] as $status) {
        foreach (['runtime', 'http'] as $entry) {
            $slug = 'failure-' . $entry . '-' . $status;
            $before = $snapshot();
            if ($entry === 'runtime') {
                BmsPageCreationTestPDO::$fault = 'terms-after';
                $error = null;
                try { bms_insert_new_page($makePage($slug, $status), $status === 'draft' ? 'pages/drafts' : 'pages/published', $slug . '.md', 1); } catch (Throwable $e) { $error = $e; }
                bms_creation_test_expect($error !== null && BmsPageCreationTestPDO::$fault === '', 'Runtime injection reached after first association and reports failure');
                $response = ['error' => $error->getMessage()];
            } else {
                file_put_contents($temporary . '/_bonumark_stream/app/creation-fault', 'terms-after');
                $response = bms_creation_test_request($base, '/admin/page-new.php', $owner, $fields($slug, $status));
                bms_creation_test_expect($response['status'] === 200 && str_contains($response['body'], 'Page creation failed.') && $response['location'] === '', 'Real New Page handler reports failure without success');
                bms_creation_test_expect(!is_file($temporary . '/_bonumark_stream/app/creation-fault'), 'HTTP fault consumed in disposable PDO');
            }
            $after = $snapshot();
            $row = $activeRow($slug, $status);
            $delta = [];
            foreach ($before as $table => $rows) { $delta[$table] = array_values(array_diff($after[$table], $rows)); }
            fwrite(STDOUT, json_encode(['mode' => $negative ? 'baseline' : 'corrected', 'entry' => $entry, 'status' => $status, 'failure' => 'after category association', 'before_counts' => array_map('count', $before), 'after_counts' => array_map('count', $after), 'surviving_changes' => $delta, 'response' => array_diff_key($response, ['body' => true])], JSON_UNESCAPED_SLASHES) . "\n");
            bms_creation_test_expect($negative ? $row !== null && $before !== $after : $row === null && $before === $after, $negative ? 'Baseline defect demonstrated: partial Page and term work survives' : 'Complete rollback preserves all pre-operation state');
            $retry = bms_creation_test_request($base, '/admin/page-new.php', $owner, $fields($slug, $status));
            bms_creation_test_expect($retry['status'] === 302 && str_contains($retry['location'], 'file=' . $slug . ($negative ? '-2' : '') . '.md'), $negative ? 'Baseline HTTP retry creates a suffixed Page and leaves the partial original' : 'Retry succeeds after complete rollback');
            fwrite(STDOUT, json_encode(['retry' => $slug, 'http_status' => $retry['status'], 'location' => $retry['location']]) . "\n");
        }
    }
    if (!$negative) {
        $observer = new PDO('mysql:host=' . getenv('BMS_DB_HOST') . ';dbname=' . getenv('BMS_DB_NAME') . ';charset=utf8mb4', getenv('BMS_DB_USER'), getenv('BMS_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $visible = static function (string $slug) use ($observer, $prefix): int {
            $s = $observer->prepare("SELECT COUNT(*) FROM `{$prefix}posts` WHERE slug=?"); $s->execute([$slug]); return (int)$s->fetchColumn();
        };
        $sentinel = static function () use ($observer, $prefix): string { return (string)$observer->query("SELECT setting_value FROM `{$prefix}settings` WHERE setting_key='site_tagline'")->fetchColumn(); };
        foreach (['draft','published'] as $status) {
            $slug = 'fidelity-' . $status; $page = $makePage($slug, $status);
            $section = $status === 'draft' ? 'pages/drafts' : 'pages/published';
            $id = bms_insert_new_page($page, $section, $slug . '.md', 2);
            $row = $activeRow($slug, $status);
            bms_creation_test_expect($id === (int)$row['id'] && $visible($slug) === 1 && !$pdo->inTransaction(), $status . ': strict identity committed and independently visible');
            bms_creation_test_expect($row['content_body'] === $page['body'] && json_decode($row['content_front_matter'], true) === bms_content_front_matter_for_database($page), $status . ': body and complete front matter fidelity');
            bms_creation_test_expect((int)$row['author_id'] === 2 && $row['date_published'] === '2024-02-29' && ($row['published_at'] !== null) === ($status === 'published') && $row['created_at'] !== '' && $row['updated_at'] !== '', $status . ': author, date and timestamp semantics');
            $s = $pdo->prepare("SELECT t.name FROM `{$prefix}terms` t JOIN `{$prefix}post_terms` pt ON pt.term_id=t.id WHERE pt.post_id=? ORDER BY t.name"); $s->execute([$id]);
            $names = ['New category ' . $slug, 'Existing tag', 'New tag ' . $slug]; sort($names);
            bms_creation_test_expect($s->fetchAll(PDO::FETCH_COLUMN) === $names, $status . ': existing and new category/tag associations');
            // Duplicate INSERT IGNORE legitimately reports zero affected rows.
            bms_sync_post_terms($id, array_replace($page, ['tags' => ['Existing tag', 'Existing tag']]));
            bms_creation_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}post_terms` WHERE post_id=$id")->fetchColumn() === 2, 'Duplicate association remains a valid INSERT IGNORE outcome');
            $listing = bms_creation_test_request($base, '/admin/pages.php?status=' . $status, $owner);
            $edit = bms_creation_test_request($base, '/admin/page-edit.php?type=' . $status . '&file=' . $slug . '.md', $owner);
            bms_creation_test_expect(str_contains($listing['body'], $page['title']) && $edit['status'] === 200 && str_contains($edit['body'], 'Synthetic'), $status . ': Admin discovery and editing');
            $public = bms_creation_test_request($base, '/page.php?slug=' . $slug, $guest);
            bms_creation_test_expect($status === 'published' ? $public['status'] === 200 && str_contains($public['body'], 'Synthetic') : $public['status'] === 404, $status . ': public visibility contract');
        }
        foreach ([false,true] as $nested) {
            foreach (['draft','published'] as $status) {
                $faults = ['insert-before','insert-after','insert-false','lookup-prepare-false','lookup-false','lookup-fetch-false','lookup-throw','term-false','term-after','clear-false','terms-after','association-false','association-after'];
                $faults = array_merge($faults, $nested ? ['savepoint-false','release-false'] : ['begin-false','commit-false','commit-throw']);
                foreach ($faults as $fault) {
                    $slug = ($nested ? 'nested-' : 'owned-') . $status . '-' . $fault;
                    $section = $status === 'draft' ? 'pages/drafts' : 'pages/published';
                    $page = $makePage($slug, $status); $oldSentinel = $sentinel();
                    if ($nested) { $pdo->beginTransaction(); $pdo->exec("UPDATE `{$prefix}settings` SET setting_value='$slug' WHERE setting_key='site_tagline'"); }
                    $before = $snapshot(); BmsPageCreationTestPDO::$fault = $fault; $error = null; BmsPageCreationTestPDO::$reached = []; BmsPageCreationTestPDO::$savepoints = [];
                    try { bms_insert_new_page($page, $section, $slug . '.md', 1); } catch (Throwable $e) { $error = $e; }
                    bms_creation_test_expect($error !== null && BmsPageCreationTestPDO::$reached === [$fault], "$slug: intended failure boundary reached, no success");
                    bms_creation_test_expect($snapshot() === $before && $visible($slug) === 0, "$slug: all operation writes rolled back, unrelated/shared state preserved");
                    bms_creation_test_expect($pdo->inTransaction() === $nested, "$slug: correct transaction ownership");
                    if ($nested) {
                        if ($fault !== 'savepoint-false') {
                            $missing = null;
                            try { $pdo->exec('ROLLBACK TO SAVEPOINT ' . end(BmsPageCreationTestPDO::$savepoints)); } catch (PDOException $e) { $missing = $e; }
                            bms_creation_test_expect($missing !== null && ($missing->errorInfo[1] ?? 0) === 1305, "$slug: operation savepoint was released during cleanup");
                        }
                        bms_creation_test_expect($sentinel() === $oldSentinel, "$slug: outer sentinel not yet visible to independent connection");
                        $pdo->commit();
                        bms_creation_test_expect($sentinel() === $slug, "$slug: caller can commit its earlier sentinel after recoverable failure");
                    }
                    $id = bms_insert_new_page($page, $section, $slug . '.md', 1);
                    bms_creation_test_expect($id > 0 && $visible($slug) === 1 && !$pdo->inTransaction(), "$slug: same valid operation retries successfully");
                }
            }
        }
        $before = $snapshot(); $pdo->beginTransaction(); BmsPageCreationTestPDO::$savepoints = [];
        bms_insert_new_page($makePage('outer-success', 'draft'), 'pages/drafts', 'outer-success.md', 1);
        bms_creation_test_expect($pdo->inTransaction() && $activeRow('outer-success','draft') !== null && $visible('outer-success') === 0, 'Nested success leaves caller open and uncommitted');
        bms_insert_new_page($makePage('outer-success-second', 'draft'), 'pages/drafts', 'outer-success-second.md', 1);
        bms_creation_test_expect(count(array_unique(BmsPageCreationTestPDO::$savepoints)) === 2, 'Nested operations use unique savepoint names');
        foreach (BmsPageCreationTestPDO::$savepoints as $savepoint) {
            $missing = null;
            try { $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint); } catch (PDOException $e) { $missing = $e; }
            bms_creation_test_expect($missing !== null && ($missing->errorInfo[1] ?? 0) === 1305, 'Successful operation releases its savepoint before returning');
        }
        $pdo->rollBack();
        bms_creation_test_expect($snapshot() === $before && $visible('outer-success') === 0, 'Caller rollback reverses successful creation including all terms');
        foreach (['rollback-false','rollback-savepoint-false','cleanup-release-false'] as $cleanup) {
            $nested = $cleanup !== 'rollback-false'; $before = $snapshot();
            if ($nested) { $pdo->beginTransaction(); }
            BmsPageCreationTestPDO::$fault = 'terms-after'; BmsPageCreationTestPDO::$cleanupFault = $cleanup; BmsPageCreationTestPDO::$reached = []; $error = null;
            try { bms_insert_new_page($makePage($cleanup,'draft'), 'pages/drafts', $cleanup . '.md', 1); } catch (Throwable $e) { $error = $e; }
            bms_creation_test_expect($error !== null && $error->getPrevious() !== null && str_contains($error->getPrevious()->getMessage(), 'terms-after') && str_contains($error->getMessage(), 'Cleanup:') && in_array($cleanup, BmsPageCreationTestPDO::$reached, true), "$cleanup: original error and reached cleanup failure remain observable");
            bms_creation_test_expect($pdo->inTransaction(), "$cleanup: no false commit after cleanup failure");
            $pdo->rollBack(); bms_creation_test_expect($snapshot() === $before, "$cleanup: explicit caller recovery rolls back uncertain cleanup");
        }
        $before = $snapshot(); $pdo->beginTransaction();
        $pdo->exec("UPDATE `{$prefix}settings` SET setting_value='Will be invalidated' WHERE setting_key='site_tagline'");
        BmsPageCreationTestPDO::$fault = 'transaction-lost'; $error = null;
        try { bms_insert_new_page($makePage('invalidated','draft'), 'pages/drafts', 'invalidated.md', 1); } catch (Throwable $e) { $error = $e; }
        bms_creation_test_expect($error !== null && str_contains($error->getMessage(), 'verify its outcome') && $error->getPrevious() !== null && !$pdo->inTransaction() && $snapshot() === $before, 'Transaction-wide rollback is reported without claiming caller sentinel preservation');
        foreach (['guest','commenter','missing-csrf','invalid-csrf'] as $actor) {
            $cookies = $actor === 'guest' ? [] : ($actor === 'commenter' ? $sessions['commenter'] : $owner);
            $form = $fields('protected-' . $actor, 'published');
            if ($actor === 'missing-csrf') { unset($form['csrf_token']); }
            if ($actor === 'invalid-csrf') { $form['csrf_token'] = 'invalid'; }
            $before = $snapshot(); $response = bms_creation_test_request($base, '/admin/page-new.php', $cookies, $form);
            bms_creation_test_expect($response['status'] === ($actor === 'guest' ? 302 : 403) && $snapshot() === $before, "$actor: rejected without writes");
        }
        $before = $snapshot();
        $trigger = $prefix . 'creation_failure';
        $pdo->exec("CREATE TRIGGER `$trigger` BEFORE INSERT ON `{$prefix}post_terms` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Work38 controlled association failure'");
        try {
            $response = bms_creation_test_request($base, '/admin/page-new.php', $owner, $fields('native-association-failure','published'));
        } finally {
            $pdo->exec("DROP TRIGGER `$trigger`");
        }
        bms_creation_test_expect($response['status'] === 200 && str_contains($response['body'], 'Page creation failed.') && $snapshot() === $before, 'Native database association failure reaches HTTP error feedback and rolls back all writes');
        $retry = bms_creation_test_request($base, '/admin/page-new.php', $owner, $fields('native-association-failure','published'));
        bms_creation_test_expect($retry['status'] === 302 && str_contains($retry['location'], 'file=native-association-failure.md'), 'Native database failure retries successfully with original slug');
        foreach (['page_title','body_markdown'] as $key) {
            $before = $snapshot(); $form = $fields('invalid-form','draft'); $form[$key] = '';
            $response = bms_creation_test_request($base, '/admin/page-new.php', $owner, $form);
            bms_creation_test_expect($response['status'] === 302 && str_contains($response['location'], 'page-new.php') && $snapshot() === $before, "$key: existing required-field validation without writes");
        }
    $workers = [];
    $startWorker = static function (array $page, string $isolation) use ($temporary, &$workers): array {
        $marker = $temporary . '/worker-' . bin2hex(random_bytes(4));
        $log = $marker . '.log';
        $env = array_merge(getenv(), ['BMS_CREATION_WORKER_MARKER' => $marker]);
        $worker = proc_open([PHP_BINARY, $temporary . '/_bonumark_stream/app/page-creation-test-support.php', '--worker', $temporary, base64_encode(json_encode($page)), $isolation], [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.err', 'w']], $pipes, $temporary, $env);
        if (!is_resource($worker)) { throw new RuntimeException('Cannot start independent creation worker.'); }
        fclose($pipes[0]); $workers[] = $worker;
        return ['process' => $worker, 'marker' => $marker, 'log' => $log];
    };
    $finishWorker = static function (array $worker): array {
        $deadline = microtime(true) + 8;
        do {
            $state = proc_get_status($worker['process']);
            if (!$state['running']) { break; }
            if (microtime(true) > $deadline) { proc_terminate($worker['process']); throw new RuntimeException('Worker did not complete.'); }
            usleep(10000);
        } while (true);
        proc_close($worker['process']);
        $text = trim((string)file_get_contents($worker['log']));
        $result = json_decode($text, true);
        if (!is_array($result)) { throw new RuntimeException('Worker returned no result: ' . $text . file_get_contents($worker['log'] . '.err')); }
        return $result;
    };
    $awaitBlocked = static function (array $worker, string $verb) use ($pdo): void {
        $deadline = microtime(true) + 3; $info = '';
        do {
            if (is_file($worker['marker'])) {
                $marker = json_decode((string)file_get_contents($worker['marker']), true);
                if (is_array($marker)) {
                    $s = $pdo->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');
                    $s->execute([$marker['connection']]); $info = (string)$s->fetchColumn();
                    if (str_starts_with($info, $verb)) { break; }
                }
            }
            if (microtime(true) > $deadline) { throw new RuntimeException('Independent worker never reached its blocked database statement.'); }
            usleep(10000);
        } while (true);
        usleep(100000);
        bms_creation_test_expect(proc_get_status($worker['process'])['running'], 'Independent connection remains blocked until transaction completes');
        fwrite(STDOUT, json_encode(['concurrent_wait' => $verb, 'statement' => $info]) . "\n");
    };

        foreach (['REPEATABLE READ','READ COMMITTED'] as $isolation) {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
            $suffix = strtolower(str_replace(' ', '-', $isolation));
            foreach (['draft','published'] as $status) {
                foreach (['commit','rollback'] as $completion) {
                    $slug = 'race-' . $suffix . '-' . $status . '-' . $completion;
                    $page = $makePage($slug, $status); $section = $status === 'draft' ? 'pages/drafts' : 'pages/published';
                    $pdo->beginTransaction(); bms_insert_new_page($page, $section, $slug . '.md', 1);
                    $saved = $activeRow($slug, $status); $before = $snapshot();
                    $worker = $startWorker($page, $isolation); $awaitBlocked($worker, 'INSERT INTO ');
                    $completion === 'commit' ? $pdo->commit() : $pdo->rollBack();
                    $result = $finishWorker($worker);
                    bms_creation_test_expect($result['ok'] === ($completion === 'rollback') && !$result['transaction_open'], "$slug: competing creator follows first transaction outcome");
                    bms_creation_test_expect($visible($slug) === 1, "$slug: exactly one committed identity");
                    if ($completion === 'commit') { bms_creation_test_expect($activeRow($slug,$status) === $saved && $snapshot() === $before, "$slug: loser leaves winner and all unrelated data intact"); }
                    else { bms_creation_test_expect((int)$activeRow($slug,$status)['id'] === $result['id'], "$slug: waiting creator owns surviving identity after first rollback"); }
                    fwrite(STDOUT, json_encode(['isolation' => $isolation, 'race' => $slug, 'worker' => $result]) . "\n");
                }
            }
            $slug = 'shared-existing-' . $suffix;
            $a = array_replace($makePage($slug . '-a','draft'), ['category' => 'Shared category', 'tags' => ['Existing tag']]);
            $b = array_replace($makePage($slug . '-b','draft'), ['category' => 'Shared category', 'tags' => ['Existing tag']]);
            bms_get_or_create_term('category','Shared category');
            $pdo->beginTransaction(); bms_insert_new_page($a, 'pages/drafts', $a['slug'] . '.md',1);
            $result = $finishWorker($startWorker($b,$isolation));
            fwrite(STDOUT, json_encode(['shared_existing_result' => $result]) . "\n");
            if ($result['ok']) {
                bms_creation_test_expect($visible($b['slug']) === 1 && $visible($a['slug']) === 0, "$suffix: independent Pages share existing terms during overlap");
                $pdo->commit();
            } else {
                bms_creation_test_expect(str_contains($result['error'], '1205') && !$result['transaction_open'] && $visible($b['slug']) === 0, "$suffix: shared-term lock timeout rolls losing operation back completely");
                $pdo->commit();
                $beforeRetry = $snapshot();
                $result = $finishWorker($startWorker($b,$isolation));
                bms_creation_test_expect($result['ok'], "$suffix: shared-term timeout retries after winning caller commits");
                $afterRetry = $snapshot(); foreach ($beforeRetry as $table => $rows) { bms_creation_test_expect(array_diff($rows,$afterRetry[$table]) === [], "$suffix: shared-term retry preserves $table"); }
            }
            bms_creation_test_expect($visible($a['slug']) === 1 && $visible($b['slug']) === 1, "$suffix: both independent shared-term Pages ultimately commit");
            // Term lookup sees absence; another connection creates it before our
            // INSERT. Keep the existing unique-key race behavior, prove rollback/retry.
            $slug = 'shared-new-' . $suffix;
            $a = array_replace($makePage($slug . '-a','draft'), ['category' => $slug, 'tags' => ['Existing tag']]);
            $before = $snapshot();
            BmsPageCreationTestPDO::$beforeTermInsert = static function () use ($observer,$prefix,$slug): void {
                $statement = $observer->prepare("INSERT INTO `{$prefix}terms` (term_type,name,slug,created_at,updated_at) VALUES ('category',?,?,NOW(),NOW())");
                $statement->execute([$slug,$slug]);
                bms_creation_test_expect((int)$observer->lastInsertId() > 0, 'Separate connection commits shared new term between lookup and insert');
            };
            $error = null;
            try { bms_insert_new_page($a, 'pages/drafts', $a['slug'] . '.md',1); } catch (Throwable $e) { $error = $e; }
            bms_creation_test_expect($error instanceof PDOException && $error->getCode() === '23000' && !$pdo->inTransaction() && $visible($a['slug']) === 0, "$suffix: shared-term race fails recoverably without partial losing Page");
            $after = $snapshot(); foreach ($before as $table => $rows) { bms_creation_test_expect(array_diff($rows,$after[$table]) === [], "$suffix: shared-term race preserves original $table rows"); }
            bms_creation_test_expect(count($after['terms']) === count($before['terms']) + 1 && $after['posts'] === $before['posts'] && $after['post_terms'] === $before['post_terms'], "$suffix: only the independently committed winning term survives");
            bms_insert_new_page($a, 'pages/drafts', $a['slug'] . '.md',1);
            bms_creation_test_expect($visible($a['slug']) === 1, "$suffix: shared-term loser retries using winning term");
        }
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        foreach (['draft','published'] as $status) { bms_insert_new_page($makePage('cross-status-contract',$status), $status === 'draft' ? 'pages/drafts' : 'pages/published', 'cross-status-contract.md',1); }
        bms_creation_test_expect($visible('cross-status-contract') === 2, 'Helper preserves existing type/slug/status uniqueness, no cross-status constraint added');

        if (getenv('BMS_CREATION_BROWSER_REPORT')) {
            $report = (string)getenv('BMS_CREATION_BROWSER_REPORT');
            $browser = proc_open(['node', __DIR__ . '/page-creation-browser-test.cjs', $temporary, $base, $report], [0 => ['pipe','r'], 1 => ['file', $temporary . '/browser.log', 'w'], 2 => ['file', $temporary . '/browser.log', 'a']], $pipes);
            if (!is_resource($browser)) { throw new RuntimeException('Could not start browser verification.'); }
            fclose($pipes[0]);
            $browserExit = proc_close($browser);
            fwrite(STDOUT, (string)file_get_contents($temporary . '/browser.log'));
            bms_creation_test_expect($browserExit === 0, 'Real browser submission and feedback verification passes');
            foreach (['draft','published'] as $status) {
                bms_creation_test_expect($visible('browser-' . $status) === 1 && $visible('browser-' . $status . '-2') === 0, $status . ': browser retry leaves exactly one requested identity');
            }
        } else {
            fwrite(STDOUT, "SKIP optional browser: BMS_CREATION_BROWSER_REPORT is unset.\n");
        }

    }
    fwrite(STDOUT, 'Page creation ' . ($negative ? 'baseline reproduction' : 'regression') . ' passed: ' . $checks . " checks.\n");

} catch (Throwable $failure) {
    fwrite(STDERR, (string)$failure . "\n");
    throw $failure;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foreach ($workers ?? [] as $worker) { if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); } }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (str_starts_with((string)$table, $prefix)) {
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    bms_creation_test_remove_tree($temporary);
}
