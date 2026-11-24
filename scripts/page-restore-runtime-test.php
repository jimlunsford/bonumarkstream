<?php
/**
 * Failure-atomic Page restoration against a disposable database and HTTP fixture.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_restore_ci_* tables are created and
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
$temporary = sys_get_temp_dir() . '/bms-restore-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_restore_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_restore_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_restore_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
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

function bms_restore_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_restore_test_remove_tree(string $path): void
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

    copy(__DIR__ . '/fixtures/page-restore-test-support.php', $temporary . '/_bonumark_stream/app/page-restore-test-support.php');
    $databasePath = $temporary . '/_bonumark_stream/app/database.php';
    $database = (string)file_get_contents($databasePath);
    $database = str_replace('<?php', "<?php\nrequire_once __DIR__ . '/page-restore-test-support.php';", $database);
    $database = str_replace('$pdo = new PDO($dsn, $user, $pass, [', '$pdo = new BmsPageRestoreTestPDO($dsn, $user, $pass, [', $database);
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
    bms_restore_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_restore_test_request($base, '/admin/login.php', $cookies);
        $login = bms_restore_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => bms_restore_test_token($login['body']), 'username' => $username, 'password' => 'disposable-password']);
        bms_restore_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }

    require_once $temporary . '/_bonumark_stream/app/pages.php';
    $_SESSION['bms_logged_in'] = true;
    $_SESSION['bms_user_id'] = 1;
    $owner = $sessions['owner'];
    $csrf = bms_restore_test_token(bms_restore_test_request($base, '/admin/pages.php', $owner)['body']);
    $snapshot = static function () use ($pdo, $prefix): array {
        $out = [];
        foreach (['posts', 'trash', 'terms', 'post_terms', 'revisions', 'settings', 'media'] as $table) {
            $rows = array_map('serialize', $pdo->query("SELECT * FROM `{$prefix}{$table}`")->fetchAll());
            sort($rows); $out[$table] = $rows;
        }
        return $out;
    };
    $trashRow = static function (int $id) use ($pdo, $prefix): ?array {
        $s = $pdo->prepare("SELECT * FROM `{$prefix}trash` WHERE id=?"); $s->execute([$id]); return $s->fetch() ?: null;
    };
    $activeRow = static function (string $slug, string $status) use ($pdo, $prefix): ?array {
        $s = $pdo->prepare("SELECT * FROM `{$prefix}posts` WHERE post_type='page' AND slug=? AND status=?"); $s->execute([$slug, $status]); return $s->fetch() ?: null;
    };
    $seed = static function (string $slug, string $status = 'draft') use ($pdo, $prefix): array {
        $meta = ['title' => 'Recover ' . $slug, 'slug' => $slug, 'status' => $status, 'content_type' => 'page', 'date' => '2024-02-29', 'description' => 'Original SEO description.', 'seo_title' => 'Original search title', 'robots' => 'noindex,follow', 'tags' => ['Saved tag'], 'category' => 'Page', 'featured_media' => 'media/synthetic.jpg', 'media_gallery' => ['media/synthetic.jpg', 'media/second.jpg'], 'location_name' => 'Synthetic place', 'custom_recovery_key' => ['nested' => 'Preserve this metadata']];
        $body = "  Original **recoverable** body.\n\n![Saved image](media/synthetic.jpg)\n";
        $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $s = $pdo->prepare("INSERT INTO `{$prefix}trash` (title,slug,original_status,original_filename,trash_filename,markdown_path,post_type,content_body,content_front_matter,content_source,content_hash,original_author_id,deleted_by,deleted_at) VALUES (?,?,?,?,?,?,'page',?,?,'database',?,2,1,'2026-11-20 12:00:00')");
        $s->execute([$meta['title'], $slug, 'page_' . $status, $slug . '.md', 'trash-' . $slug . '.md', 'content/pages/trash/' . $slug . '.md', $body, $encoded, hash('sha256', $body . "\n" . $encoded)]);
        return ['id' => (int)$pdo->lastInsertId(), 'slug' => $slug, 'status' => $status, 'meta' => $meta, 'body' => $body];
    };
    $verifyRestored = static function (array $f) use ($activeRow, $trashRow, $pdo, $prefix): array {
        $row = $activeRow($f['slug'], $f['status']);
        bms_restore_test_expect($row !== null && $trashRow($f['id']) === null, 'Successful restoration replaces one Trash entry with one active Page');
        bms_restore_test_expect($row['content_body'] === $f['body'], 'Complete body survives without trimming or reconstruction');
        $meta = json_decode($row['content_front_matter'], true);
        foreach ($f['meta'] as $k => $v) { bms_restore_test_expect(($meta[$k] ?? null) === $v, 'Stored front matter preserved: ' . $k); }
        bms_restore_test_expect((int)$row['author_id'] === 2 && $row['date_published'] === '2024-02-29', 'Original author and content date preserved');
        bms_restore_test_expect($row['markdown_path'] === 'content/pages/' . ($f['status'] === 'published' ? 'published' : 'drafts') . '/' . $f['slug'] . '.md', 'Restoration preserves status and virtual path');
        bms_restore_test_expect(($f['status'] === 'published') === ($row['published_at'] !== null), 'Draft versus Published timestamp behavior preserved');
        bms_restore_test_expect($row['content_source'] === 'database' && $row['storage_mode'] === 'database', 'Restoration remains database-first');
        $s = $pdo->prepare("SELECT t.name FROM `{$prefix}terms` t JOIN `{$prefix}post_terms` pt ON pt.term_id=t.id WHERE pt.post_id=? ORDER BY t.name"); $s->execute([$row['id']]);
        bms_restore_test_expect($s->fetchAll(PDO::FETCH_COLUMN) === ['Page', 'Saved tag'], 'Category and tag relationships restored');
        return $row;
    };
    // Unrelated active content and recovery records participate in every snapshot.
    $stream = bms_parse_markdown_string("---\ntitle: Untouched Stream\nslug: untouched-stream\nstatus: draft\n---\nUnrelated Stream content.\n");
    bms_upsert_database_content($stream, 'drafts', 'untouched-stream.md', 1);
    $unrelatedTrash = $seed('unrelated-page');
    $unrelatedBefore = $trashRow($unrelatedTrash['id']);
    $streamBefore = $pdo->query("SELECT * FROM `{$prefix}posts` WHERE post_type='stream'")->fetchAll();

    foreach (['draft', 'published'] as $status) {
        $f = $seed('http-restore-' . $status, $status);
        $response = bms_restore_test_request($base, '/admin/page-restore.php', $owner, ['trash_id' => $f['id'], 'csrf_token' => $csrf]);
        bms_restore_test_expect($response['status'] === 302 && str_contains($response['location'], 'status=' . $status), 'HTTP restoration redirects to the correct Page list');
        $verifyRestored($f);
        $listing = bms_restore_test_request($base, '/admin/pages.php?status=' . $status, $owner);
        bms_restore_test_expect(str_contains($listing['body'], $f['meta']['title']) && str_contains($listing['body'], 'Restored page'), 'Normal Admin workflow discovers restored Page and success feedback');
        if ($status === 'published') {
            $public = bms_restore_test_request($base, '/page.php?slug=' . $f['slug'], $owner);
            bms_restore_test_expect($public['status'] === 200 && str_contains($public['body'], 'recoverable'), 'Published Page resolves through the public route');
        }
        $before = $snapshot();
        $repeat = bms_restore_test_request($base, '/admin/page-restore.php', $owner, ['trash_id' => $f['id'], 'csrf_token' => $csrf]);
        bms_restore_test_expect($repeat['status'] === 404 && $snapshot() === $before, 'Repeated restoration cannot create a duplicate');
    }

    foreach ([false, true] as $nested) {
        $faults = ['lock-before', 'insert-before', 'insert-after', 'insert-false', 'insert-noop', 'terms-after', 'creation-savepoint-false', 'creation-release-false', 'delete-before', 'delete-after', 'delete-false', 'delete-noop'];
        $faults = array_merge($faults, $nested ? ['savepoint-false', 'release-false'] : ['begin-false', 'commit-throw', 'commit-false']);
        foreach ($faults as $fault) {
            $f = $seed(($nested ? 'nested-' : 'owned-') . $fault);
            $beforeOuter = $snapshot();
            if ($nested) {
                $pdo->beginTransaction();
                $pdo->exec("UPDATE `{$prefix}settings` SET setting_value='Caller-owned write' WHERE setting_key='site_tagline'");
            }
            $before = $snapshot(); $original = $trashRow($f['id']);
            BmsPageRestoreTestPDO::$fault = $fault; $error = null;
            try { bms_restore_page_trash_item($f['id']); } catch (Throwable $e) { $error = $e; }
            bms_restore_test_expect($error !== null && BmsPageRestoreTestPDO::$fault === '', ($nested ? 'Nested ' : 'Owned ') . $fault . ': injected boundary reached and no success reported');
            bms_restore_test_expect($snapshot() === $before && $trashRow($f['id']) === $original, $fault . ': complete recoverable Trash and all database state preserved');
            bms_restore_test_expect($pdo->inTransaction() === $nested, $fault . ': correct transaction ownership preserved');
            bms_restore_test_expect($activeRow($f['slug'], 'draft') === null, $fault . ': no partial active Page');
            if ($nested) { $pdo->rollBack(); bms_restore_test_expect($snapshot() === $beforeOuter, $fault . ': caller rollback remains effective'); }
            bms_restore_page_trash_item($f['id']);
            bms_restore_test_expect($activeRow($f['slug'], 'draft') !== null && $trashRow($f['id']) === null, $fault . ': retry restores exactly once');
        }
    }
    $f = $seed('caller-success'); $before = $snapshot();
    $pdo->beginTransaction();
    $pdo->exec("UPDATE `{$prefix}settings` SET setting_value='Outer sentinel' WHERE setting_key='site_tagline'");
    bms_restore_page_trash_item($f['id']);
    bms_restore_test_expect($pdo->inTransaction() && $activeRow($f['slug'], 'draft') !== null && $trashRow($f['id']) === null, 'Successful nested restoration leaves commit ownership with caller');
    $pdo->rollBack();
    bms_restore_test_expect($snapshot() === $before, 'Caller rollback reverses successful nested restoration and outer write');

    foreach (['draft', 'published'] as $status) {
        $f = $seed('collision-' . $status, $status);
        $competitor = bms_parse_markdown_string("---\ntitle: Existing competitor\nslug: " . $f['slug'] . "\nstatus: " . $status . "\ncontent_type: page\n---\nExisting content.\n");
        bms_sync_page_metadata($competitor, 'pages/' . ($status === 'published' ? 'published' : 'drafts'), $f['slug'] . '.md', 1);
        $before = $snapshot(); $error = null;
        try { bms_restore_page_trash_item($f['id']); } catch (Throwable $e) { $error = $e; }
        bms_restore_test_expect($error !== null && str_contains($error->getMessage(), 'already uses this slug') && $snapshot() === $before, $status . ': slug conflict preserves competitor and Trash exactly');
    }

    // Native database failure, through the unchanged HTTP handler and logging.
    $f = $seed('native-write-failure'); $before = $snapshot(); $offset = filesize($temporary . '/http.log');
    $pdo->exec("ALTER TABLE `{$prefix}posts` RENAME COLUMN storage_mode TO restore_test_unavailable_column");
    try {
        $response = bms_restore_test_request($base, '/admin/page-restore.php', $owner, ['trash_id' => $f['id'], 'csrf_token' => $csrf]);
    } finally {
        $pdo->exec("ALTER TABLE `{$prefix}posts` RENAME COLUMN restore_test_unavailable_column TO storage_mode");
    }
    $listing = bms_restore_test_request($base, '/admin/pages.php?status=trash', $owner);
    bms_restore_test_expect($response['status'] === 302 && str_contains($response['location'], 'status=trash') && str_contains($listing['body'], 'Page restore failed.'), 'Native write failure retains HTTP error feedback');
    bms_restore_test_expect($snapshot() === $before, 'Native write failure preserves all database state');
    clearstatcache();
    $log = substr((string)file_get_contents($temporary . '/http.log'), $offset);
    bms_restore_test_expect(str_contains($log, 'Bonumark Stream admin error') && str_contains($log, 'storage_mode'), 'Native write failure reaches existing application error logging');
    bms_restore_page_trash_item($f['id']);
    bms_restore_test_expect($activeRow($f['slug'], 'draft') !== null && $trashRow($f['id']) === null, 'Retry succeeds after native SQL failure');

    $f = $seed('protected-restore'); $before = $snapshot();
    foreach (['guest', 'commenter', 'missing-csrf', 'invalid-csrf', 'get'] as $actor) {
        $cookies = $actor === 'guest' ? [] : ($actor === 'commenter' ? $sessions['commenter'] : $owner);
        $form = ['trash_id' => $f['id']];
        if ($actor !== 'missing-csrf') { $form['csrf_token'] = $actor === 'invalid-csrf' ? 'invalid' : $csrf; }
        $response = bms_restore_test_request($base, '/admin/page-restore.php', $cookies, $actor === 'get' ? null : $form);
        bms_restore_test_expect($response['status'] === ($actor === 'guest' ? 302 : 403), $actor . ': cannot restore Page');
        bms_restore_test_expect($snapshot() === $before, $actor . ': all Page, Trash and Stream state unchanged');
    }

    $workers = [];
    $startWorker = static function (string $action, string $value, string $isolation) use ($temporary, &$workers): array {
        $marker = $temporary . '/worker-' . bin2hex(random_bytes(4));
        $log = $marker . '.log';
        $env = array_merge(getenv(), ['BMS_RESTORE_WORKER_MARKER' => $marker]);
        $worker = proc_open([PHP_BINARY, $temporary . '/_bonumark_stream/app/page-restore-test-support.php', '--worker', $temporary, $action, $value, $isolation], [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.err', 'w']], $pipes, $temporary, $env);
        if (!is_resource($worker)) { throw new RuntimeException('Cannot start independent restoration worker.'); }
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
        bms_restore_test_expect(proc_get_status($worker['process'])['running'], 'Independent connection remains blocked until transaction completes');
        fwrite(STDOUT, json_encode(['concurrent_wait' => $verb, 'statement' => $info]) . "\n");
    };
    foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
        $suffix = strtolower(str_replace(' ', '-', $isolation));
        foreach (['commit', 'rollback'] as $completion) {
            $f = $seed('same-trash-' . $suffix . '-' . $completion);
            $pdo->beginTransaction();
            bms_restore_page_trash_item($f['id']);
            $worker = $startWorker('restore', (string)$f['id'], $isolation);
            $awaitBlocked($worker, 'SELECT ');
            $completion === 'commit' ? $pdo->commit() : $pdo->rollBack();
            $result = $finishWorker($worker);
            bms_restore_test_expect($result['ok'] === ($completion === 'rollback') && !$result['transaction_open'], $isolation . ' same Trash: second request follows first transaction outcome');
            $verifyRestored($f);
            fwrite(STDOUT, json_encode(['isolation' => $isolation, 'race' => 'same-trash-' . $completion, 'second_result' => $result['ok']]) . "\n");
        }
        // Commit a competitor after the friendly collision read, before INSERT.
        $f = $seed('creator-first-' . $suffix); $original = $trashRow($f['id']); $before = $snapshot();
        BmsPageRestoreTestPDO::$beforeInsert = static function () use ($startWorker, $finishWorker, $f, $isolation): void {
            $result = $finishWorker($startWorker('create', $f['slug'], $isolation));
            bms_restore_test_expect($result['ok'], 'Competing Page committed on a separate connection before restoration INSERT');
        };
        $error = null;
        try { bms_restore_page_trash_item($f['id']); } catch (Throwable $e) { $error = $e; }
        $competing = $activeRow($f['slug'], 'draft'); $after = $snapshot();
        bms_restore_test_expect($error instanceof PDOException && $error->getCode() === '23000', $isolation . ': unique key rejects concurrent conflicting creation');
        bms_restore_test_expect($trashRow($f['id']) === $original && $competing['title'] === 'Competing Page' && trim($competing['content_body']) === 'Independent competing body.', 'Concurrent creator wins without losing Trash or overwriting competing content');
        foreach ($before as $table => $rows) { bms_restore_test_expect(array_diff($rows, $after[$table]) === [], 'Concurrent creation preserves every original row: ' . $table); }
        bms_restore_test_expect(count($after['posts']) === count($before['posts']) + 1 && !$pdo->inTransaction(), 'Failed restoration adds no second active Page and closes its transaction');
        // A normal creator can pass its check before restore commits, then
        // reach the actual write afterwards. Its new-page intent must survive.
        $f = $seed('late-creator-' . $suffix);
        $pdo->beginTransaction(); bms_restore_page_trash_item($f['id']);
        $saved = $activeRow($f['slug'], 'draft');
        $worker = $startWorker('create-late', $f['slug'], $isolation);
        $deadline = microtime(true) + 3;
        while (!is_file($worker['marker'])) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Creator did not pass its initial checks.'); }
            usleep(10000);
        }
        bms_restore_test_expect(json_decode((string)file_get_contents($worker['marker']), true)['phase'] === 'creation-checked', 'Concurrent New Page request passes checks before restore commit');
        $pdo->commit(); touch($worker['marker'] . '.continue');
        $result = $finishWorker($worker);
        bms_restore_test_expect(!$result['ok'] && str_contains($result['error'], 'Duplicate entry'), $isolation . ': late creator rejects the newly restored identity instead of upserting it');
        bms_restore_test_expect($activeRow($f['slug'], 'draft') === $saved && $trashRow($f['id']) === null, 'Late creator cannot overwrite restored content or metadata');
        // Reverse ordering: the competitor must wait for the restoration INSERT.
        $f = $seed('restorer-first-' . $suffix);
        $pdo->beginTransaction(); bms_restore_page_trash_item($f['id']);
        $saved = $activeRow($f['slug'], 'draft');
        $worker = $startWorker('create', $f['slug'], $isolation);
        $awaitBlocked($worker, 'INSERT INTO ');
        $pdo->commit(); $result = $finishWorker($worker);
        bms_restore_test_expect(!$result['ok'] && !$result['transaction_open'] && str_contains($result['error'], 'Duplicate entry'), $isolation . ': competing creator cannot replace an already-inserted restoration');
        bms_restore_test_expect($activeRow($f['slug'], 'draft') === $saved && $trashRow($f['id']) === null, 'Winning restoration remains complete and unchanged');
        fwrite(STDOUT, json_encode(['isolation' => $isolation, 'race' => 'competing-creation-both-orders', 'protected' => true]) . "\n");
    }
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');


    bms_restore_test_expect($trashRow($unrelatedTrash['id']) === $unrelatedBefore, 'Unrelated recovery identity and content remain intact');
    bms_restore_test_expect($pdo->query("SELECT * FROM `{$prefix}posts` WHERE post_type='stream'")->fetchAll() === $streamBefore, 'Unrelated Stream Posts remain unchanged');
    bms_restore_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Restoration introduces no schema migration');
    bms_restore_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}activitypub_deliveries`")->fetchColumn() === 0, 'Restoration sends no federation deliveries');
    fwrite(STDOUT, 'Page restoration regression passed: ' . $checks . " checks.\n");

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
    bms_restore_test_remove_tree($temporary);
}
