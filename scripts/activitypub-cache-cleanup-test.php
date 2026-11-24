<?php
/**
 * ActivityPub cache cleanup database and HTTP regression against a disposable install.
 * --negative-control loads the exact pre-correction cleanup function only.
 * --retention-negative-control applies only its established column correction.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_cache_ci_* tables are created and
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
$negativeControl = in_array('--negative-control', $argv, true);
$retentionNegativeControl = in_array('--retention-negative-control', $argv, true);
$temporary = sys_get_temp_dir() . '/bms-cache-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_cache_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_cache_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_cache_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Cache Cleanup Regression', 'Connection: close'];
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

function bms_cache_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_cache_test_remove_tree(string $path): void
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
    if ($negativeControl || $retentionNegativeControl) {
        $operationsPath = $temporary . '/_bonumark_stream/app/activitypub-operations.php';
        $operations = (string)file_get_contents($operationsPath);
        $baseline = (string)file_get_contents(__DIR__ . '/fixtures/activitypub-cache-cleanup-baseline.php');
        $start = strpos($operations, 'function bms_activitypub_cleanup_remote_cache(');
        $baselineStart = strpos($baseline, 'function bms_activitypub_cleanup_remote_cache(');
        if ($start === false || $baselineStart === false) {
            throw new RuntimeException('Cannot install the negative-control function.');
        }
        $originalFunction = substr($baseline, $baselineStart);
        if ($retentionNegativeControl) {
            // Untouched main cannot reach DELETE because its SELECT throws on r.id.
            // Isolate retention using only the already-verified column correction.
            $originalFunction = str_replace('r.id IS NULL', 'r.post_id IS NULL', $originalFunction);
            fwrite(STDOUT, "Retention negative control: original function with ONLY r.id -> r.post_id.\n");
        }
        file_put_contents($operationsPath, substr($operations, 0, $start) . $originalFunction);
        fwrite(STDOUT, 'Negative-control function SHA-256: ' . hash('sha256', substr($baseline, $baselineStart)) . "\n");
    }
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
        'site_name' => 'Settings Fixture', 'site_tagline' => 'Saved tagline',
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
    require_once $temporary . '/_bonumark_stream/app/scheduler.php';
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");
    // A frozen database clock makes expiry edges and affected-row counts exact.
    $pdo->exec('SET timestamp = 1795089600');
    $now = (string)$pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
    $old = '2020-01-01 00:00:00';
    $insert = static function (string $table, array $values) use ($pdo, $prefix): int {
        $columns = implode(',', array_map(static fn(string $key): string => '`' . $key . '`', array_keys($values)));
        $sql = "INSERT INTO `{$prefix}{$table}` ({$columns}) VALUES (" . implode(',', array_fill(0, count($values), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($values));
        return (int)$pdo->lastInsertId();
    };
    $rows = static function (string $table) use ($pdo, $prefix): array {
        $key = $table === 'activitypub_reply_targets' ? 'post_id' : 'id';
        return $pdo->query("SELECT * FROM `{$prefix}{$table}` ORDER BY `{$key}`")->fetchAll(PDO::FETCH_ASSOC);
    };
    $object = static function (int $id) use ($pdo, $prefix): ?array {
        $q = $pdo->prepare("SELECT * FROM `{$prefix}activitypub_remote_objects` WHERE id=?");
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $actor = static function (string $name) use ($insert, $old): array {
        $uri = 'https://' . $name . '.example.test/actor';
        $id = $insert('activitypub_remote_actors', [
            'actor_uri' => $uri, 'actor_type' => 'Person', 'preferred_username' => $name,
            'inbox_url' => $uri . '/inbox', 'public_key_id' => $uri . '#key',
            'public_key_pem' => 'synthetic-not-a-key', 'key_owner_uri' => $uri,
            'document_json' => '{}', 'fetched_at' => $old, 'expires_at' => $old,
            'created_at' => $old, 'updated_at' => $old,
        ]);
        return ['id' => $id, 'uri' => $uri];
    };
    $sequence = 0;
    $makeObject = static function (array $actor, array $overrides = []) use ($insert, $old, &$sequence): int {
        $sequence++;
        return $insert('activitypub_remote_objects', array_replace([
            'remote_actor_id' => $actor['id'], 'actor_uri' => $actor['uri'],
            'object_uri' => $actor['uri'] . '/note/' . $sequence,
            'content_html' => '<p>Synthetic cached content</p>', 'content_text' => 'Synthetic cached content',
            'content_hash' => hash('sha256', 'synthetic'), 'metadata_json' => '{"synthetic":true}',
            'fetched_at' => $old, 'expires_at' => $old, 'created_at' => $old, 'updated_at' => $old,
        ], $overrides));
    };
    $blockedActor = $actor('blocked');
    $ordinaryActor = $actor('ordinary');
    $insert('activitypub_blocks', ['block_type' => 'actor', 'block_value' => $blockedActor['uri'], 'created_at' => $old, 'updated_at' => $old]);
    $blockedId = $makeObject($blockedActor);
    $expiredId = $makeObject($ordinaryActor);
    $before = $rows('activitypub_remote_objects');
    if ($negativeControl) {
        $error = null;
        $result = null;
        try {
            $result = bms_activitypub_cleanup_remote_cache();
        } catch (PDOException $e) {
            $error = $e;
        }
        bms_cache_test_expect($error instanceof PDOException && $error->getCode() === '42S22'
            && str_contains($error->getMessage(), 'r.id'), 'Original invalid-column error is detected');
        bms_cache_test_expect($result === null, 'Original failure returns no success result');
        $after = $rows('activitypub_remote_objects');
        bms_cache_test_expect($after !== $before && $object($blockedId)['lifecycle_state'] === 'blocked'
            && $object($blockedId)['content_html'] === '' && $object($blockedId)['metadata_json'] === '{}', 'Earlier blocked-content UPDATE persists before failing SELECT');
        bms_cache_test_expect($object($expiredId) === $before[1] && !$pdo->inTransaction(), 'Original failure leaves eligible row untouched without a transaction');
        fwrite(STDOUT, json_encode(['error' => $error->getMessage(), 'before' => $before, 'after' => $after], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fwrite(STDOUT, 'Negative control passed: ' . $checks . " checks.\n");
        return;
    }
    // Same four-row scenario before and after the safety extension. Untouched
    // main's separate control already proved that r.id prevents any DELETE.
    if (!$retentionNegativeControl) {
        $pdo->beginTransaction();
    }
    $pdo->prepare("UPDATE `{$prefix}activitypub_remote_objects` SET lifecycle_state='blocked',content_html='',content_text='',metadata_json='{}' WHERE id=?")->execute([$blockedId]);
    $pdo->prepare("DELETE FROM `{$prefix}activitypub_remote_objects` WHERE id=?")->execute([$expiredId]);
    $secondBlockedActor = $actor('second-blocked-control');
    $insert('activitypub_blocks', ['block_type' => 'actor', 'block_value' => $secondBlockedActor['uri'], 'created_at' => $old, 'updated_at' => $old]);
    $pendingBlocked = $makeObject($secondBlockedActor);
    $legitimate = $makeObject($ordinaryActor);
    $retentionBefore = $rows('activitypub_remote_objects');
    $retentionResult = bms_activitypub_cleanup_remote_cache(1);
    $retentionAfter = $rows('activitypub_remote_objects');
    fwrite(STDOUT, json_encode(['retention_control' => $retentionNegativeControl ? 'column-only baseline' : 'corrected', 'limit' => 1, 'cleared_early_id' => $blockedId, 'protected_blocked_id' => $pendingBlocked, 'legitimate_id' => $legitimate, 'before' => $retentionBefore, 'after' => $retentionAfter, 'result' => $retentionResult], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    if ($retentionNegativeControl) {
        bms_cache_test_expect($object($pendingBlocked) === null, 'Column-only baseline wrongly deletes the blocked actor identity beyond its clearing limit');
        bms_cache_test_expect($object($legitimate) !== null && $retentionResult['unreferenced_objects_removed'] === 1, 'Wrong deletion consumes the limit ahead of the legitimate expired object');
        bms_cache_test_expect($object($blockedId)['content_html'] === '' && $object($blockedId)['updated_at'] === $now, 'Already-cleared blocked row consumes clearing work and receives a timestamp update');
        bms_cache_test_expect(!$pdo->inTransaction(), 'Retention negative control observes persisted original-policy deletion');
        fwrite(STDOUT, 'Retention negative control passed: ' . $checks . " checks.\n");
        return;
    }
    bms_cache_test_expect($object($pendingBlocked) !== null && $object($pendingBlocked)['lifecycle_state'] === 'blocked', 'Identical limit-one fixture retains and clears the pending blocked identity');
    bms_cache_test_expect($object($legitimate) === null && $retentionResult === ['blocked_content_cleared' => 1, 'unreferenced_objects_removed' => 1], 'Identical fixture still expires the unrelated eligible object with exact counts');
    bms_cache_test_expect($object($blockedId) === $retentionBefore[0], 'Already-cleared early row is unchanged and consumes no clearing slot');
    $pdo->rollBack();
    bms_cache_test_expect($rows('activitypub_remote_objects') === $before, 'Restores original two-row scenario for preserved coverage');
    $result = bms_activitypub_cleanup_remote_cache();
    bms_cache_test_expect($result === ['blocked_content_cleared' => 1, 'unreferenced_objects_removed' => 1], 'Original two-row scenario succeeds with exact update/delete counts');
    bms_cache_test_expect($object($expiredId) === null && $object($blockedId)['content_html'] === '' && !$pdo->inTransaction(), 'Corrected scenario commits both phases and closes its transaction');
    fwrite(STDOUT, json_encode(['corrected_before' => $before, 'corrected_after' => $rows('activitypub_remote_objects'), 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    $followingActor = $actor('followed');
    $pendingActor = $actor('pending');
    foreach ([[$followingActor, 'accepted'], [$pendingActor, 'pending']] as [$remote, $state]) {
        $insert('activitypub_following', ['remote_actor_id' => $remote['id'], 'actor_uri' => $remote['uri'], 'follow_activity_uri' => $remote['uri'] . '/follow', 'state' => $state, 'created_at' => $old, 'updated_at' => $old]);
    }
    $protected = [];
    $protected['accepted Following'] = $makeObject($followingActor);
    $protected['recent cache'] = $makeObject($ordinaryActor, ['expires_at' => '2030-01-01 00:00:00']);
    $protected['within retention grace'] = $makeObject($ordinaryActor, ['expires_at' => (string)$pdo->query('SELECT DATE_SUB(UTC_TIMESTAMP(), INTERVAL 29 DAY)')->fetchColumn()]);
    $protected['exact 30-day boundary'] = $makeObject($ordinaryActor, ['expires_at' => (string)$pdo->query('SELECT DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)')->fetchColumn()]);
    $protected['ordinary tombstone'] = $makeObject($ordinaryActor, ['lifecycle_state' => 'deleted', 'deleted_at' => $old]);
    $protected['blocked actor tombstone'] = $makeObject($blockedActor, ['lifecycle_state' => 'deleted', 'deleted_at' => $old]);
    $protected['unrelated lifecycle'] = $makeObject($ordinaryActor, ['lifecycle_state' => 'unavailable']);
    foreach (['active', 'undone'] as $state) {
        $id = $makeObject($ordinaryActor);
        $protected[$state . ' owner interaction'] = $id;
        $insert('activitypub_owner_interactions', [
            'semantic_key' => hash('sha256', $state), 'interaction_type' => 'Like',
            'remote_actor_id' => $ordinaryActor['id'], 'actor_uri' => $ordinaryActor['uri'],
            'target_object_uri' => $object($id)['object_uri'], 'current_activity_uri' => $ordinaryActor['uri'] . '/like/' . $state,
            'state' => $state, 'created_at' => $old, 'updated_at' => $old,
        ]);
    }
    $replyId = $makeObject($ordinaryActor);
    $protected['reply target'] = $replyId;
    // Two local posts may reference the same remote object; neither is disposable.
    foreach ([1, 2] as $n) {
        $postId = $insert('posts', ['post_type' => 'stream', 'slug' => 'reply-' . $n, 'title' => 'Synthetic reply', 'status' => 'draft', 'markdown_path' => 'drafts/reply-' . $n . '.md', 'content_hash' => hash('sha256', 'reply-' . $n), 'created_at' => $old, 'updated_at' => $old]);
        $insert('activitypub_reply_targets', ['post_id' => $postId, 'remote_object_id' => $replyId, 'remote_actor_id' => $ordinaryActor['id'], 'actor_uri' => $ordinaryActor['uri'], 'in_reply_to_uri' => $object($replyId)['object_uri'], 'created_at' => $old, 'updated_at' => $old]);
    }
    $alreadyBlocked = $makeObject($ordinaryActor, ['lifecycle_state' => 'blocked']);
    $newBlocked = $makeObject($blockedActor);
    $protectedBefore = [];
    foreach ($protected as $label => $id) {
        $protectedBefore[$label] = $object($id);
    }
    $otherTables = static function () use ($pdo, $prefix): array {
        $result = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (str_starts_with($table, $prefix) && $table !== $prefix . 'activitypub_remote_objects') {
                $records = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
                $encoded = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $records);
                sort($encoded);
                $result[$table] = $encoded;
            }
        }
        return $result;
    };
    $relationshipsBefore = $otherTables();
    $eligible = [$makeObject($ordinaryActor), $makeObject($pendingActor), $makeObject($ordinaryActor)];
    $limitBefore = $rows('activitypub_remote_objects');
    $pdo->beginTransaction();
    $result = bms_activitypub_cleanup_remote_cache(2);
    fwrite(STDOUT, json_encode(['limit_probe' => 2, 'new_blocked_actor_object_id' => $newBlocked, 'expected_eligible_ids' => $eligible, 'before' => $limitBefore, 'after' => $rows('activitypub_remote_objects'), 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    // The original blocked UPDATE matches rows even when already cleared. Its
    // LIMIT is a matched-row cap, and is independent of the deletion limit.
    $blockedLimitViolation = $object($newBlocked) === null;
    fwrite(STDOUT, json_encode(['BLOCKED_LIMIT_FINDING' => $blockedLimitViolation, 'blocked_actor_object_id' => $newBlocked, 'deleted_despite_actor_block' => $blockedLimitViolation, 'retention_proof_result' => $result]) . "\n");
    $pdo->rollBack();
    bms_cache_test_expect($rows('activitypub_remote_objects') === $limitBefore, 'Caller rollback restores the limit-probe fixture before independent checks');
    foreach ($protected as $label => $id) {
        bms_cache_test_expect($object($id) === $protectedBefore[$label], 'Preserves full record: ' . $label);
    }
    bms_cache_test_expect($otherTables() === $relationshipsBefore, 'All actor, owner, post, relationship, queue and other tables remain unchanged');
    $result = bms_activitypub_cleanup_remote_cache();
    bms_cache_test_expect($result['unreferenced_objects_removed'] === 3 && $object($eligible[2]) === null, 'Sufficient cleanup limit removes all three eligible objects and clears blocked rows');
    foreach ([$blockedId, $alreadyBlocked, $newBlocked] as $id) {
        $row = $object($id);
        bms_cache_test_expect($row !== null && $row['lifecycle_state'] === 'blocked' && $row['content_html'] === '' && $row['content_text'] === '' && $row['metadata_json'] === '{}', 'Blocked content is cleared while identity ' . $id . ' remains');
    }
    $beforeRepeat = $rows('activitypub_remote_objects');
    $result = bms_activitypub_cleanup_remote_cache();
    bms_cache_test_expect($result === ['blocked_content_cleared' => 0, 'unreferenced_objects_removed' => 0] && $rows('activitypub_remote_objects') === $beforeRepeat, 'Repeated cleanup with unchanged clock has no mutations and reports zero');
    $pdo->exec('SET timestamp = 1795089601');
    // Keep the original grace-boundary fixture protected as the clock advances.
    $pdo->prepare("UPDATE `{$prefix}activitypub_remote_objects` SET expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) WHERE id=?")->execute([$protected['exact 30-day boundary']]);
    $beforeTick = $rows('activitypub_remote_objects');
    $result = bms_activitypub_cleanup_remote_cache();
    $afterTick = $rows('activitypub_remote_objects');
    bms_cache_test_expect($result === ['blocked_content_cleared' => 0, 'unreferenced_objects_removed' => 0] && $afterTick === $beforeTick, 'Later repeated cleanup leaves already-cleared timestamps unchanged and reports zero');
    $pdo->exec('SET timestamp = 1795089600');

    // Native PDO statements execute against the real engine before this wrapper
    // injects an exception. No SQL results or database behavior are mocked.
    class BmsCacheFaultStatement extends PDOStatement
    {
        public static string $fault = '';
        public static array $observed = [];
        public static $beforeDelete = null;
        public static array $deletes = [];
        public static array $deleteAttempts = [];
        public function execute(?array $params = null): bool
        {
            $verb = strtok($this->queryString, ' ');
            if ($verb === 'DELETE') {
                self::$deleteAttempts[] = $params;
            }
            if ($verb === 'DELETE' && is_callable(self::$beforeDelete)) {
                $callback = self::$beforeDelete;
                self::$beforeDelete = null;
                $callback();
            }
            $result = parent::execute($params);
            if ($verb === 'DELETE') {
                self::$deletes[] = ['ids' => $params, 'affected' => $this->rowCount()];
            }
            if (self::$fault !== '' && $verb === self::$fault && str_contains($this->queryString, 'activitypub_remote_objects')) {
                self::$fault = '';
                self::$observed = ['affected' => $this->rowCount(), 'rows' => ($GLOBALS['rows'])('activitypub_remote_objects')];
                throw new RuntimeException('Injected failure after real ' . $verb);
            }
            return $result;
        }
    }
    $faultBlocked = $makeObject($blockedActor);
    $faultEligible = [$makeObject($ordinaryActor), $makeObject($ordinaryActor)];
    foreach (['UPDATE', 'DELETE'] as $phase) {
        foreach ([false, true] as $nested) {
            $beforeFault = $rows('activitypub_remote_objects');
            if ($nested) {
                $pdo->beginTransaction();
                $pdo->exec("UPDATE `{$prefix}users` SET display_name='Caller pending change' WHERE username='owner'");
            }
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BmsCacheFaultStatement::class]);
            BmsCacheFaultStatement::$fault = $phase;
            $error = null;
            $result = null;
            try {
                $result = bms_activitypub_cleanup_remote_cache();
            } catch (RuntimeException $e) {
                $error = $e;
            } finally {
                BmsCacheFaultStatement::$fault = '';
                $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
            }
            $label = $phase . ($nested ? ' inside caller transaction' : ' in owned transaction');
            bms_cache_test_expect($error !== null && $error->getMessage() === 'Injected failure after real ' . $phase && $result === null, $label . ': exception propagates without success');
            bms_cache_test_expect(BmsCacheFaultStatement::$observed['affected'] > 0 && BmsCacheFaultStatement::$observed['rows'] !== $beforeFault, $label . ': actual database write completed before fault');
            bms_cache_test_expect($rows('activitypub_remote_objects') === $beforeFault, $label . ': every cleanup write is rolled back');
            bms_cache_test_expect($pdo->inTransaction() === $nested, $label . ': transaction ownership is preserved');
            if ($nested) {
                bms_cache_test_expect($pdo->query("SELECT display_name FROM `{$prefix}users` WHERE username='owner'")->fetchColumn() === 'Caller pending change', $label . ': savepoint rollback retains earlier caller change');
                $pdo->rollBack();
            }
            fwrite(STDOUT, json_encode(['fault' => $label, 'affected_before_throw' => BmsCacheFaultStatement::$observed['affected'], 'rollback_snapshot_equal' => true]) . "\n");
        }
    }
    bms_cache_test_expect($otherTables() === $relationshipsBefore, 'Fault injection preserves all unrelated tables after caller rollback');
    $beforeNested = $rows('activitypub_remote_objects');
    $pdo->beginTransaction();
    $result = bms_activitypub_cleanup_remote_cache();
    bms_cache_test_expect($pdo->inTransaction() && $result['unreferenced_objects_removed'] === 2 && $object($faultBlocked)['lifecycle_state'] === 'blocked', 'Nested success releases savepoint without committing caller transaction');
    $pdo->rollBack();
    bms_cache_test_expect($rows('activitypub_remote_objects') === $beforeNested, 'Caller rollback also reverses successful nested cleanup');
    $pdo->beginTransaction();
    $result = bms_activitypub_cleanup_remote_cache();
    $pdo->commit();
    bms_cache_test_expect($result['unreferenced_objects_removed'] === 2 && $object($faultEligible[0]) === null && $object($faultEligible[1]) === null, 'Retry after removing faults succeeds and caller commit persists cleanup');

    foreach ([0, -5, 1, 2, 1001] as $requested) {
        $expected = max(1, min(1000, $requested));
        $batch = [];
        for ($n = 0; $n < $expected + 1; $n++) {
            $batch[] = $makeObject($ordinaryActor);
        }
        $beforeCount = count($rows('activitypub_remote_objects'));
        $result = bms_activitypub_cleanup_remote_cache($requested);
        bms_cache_test_expect($result['unreferenced_objects_removed'] === $expected && count($rows('activitypub_remote_objects')) === $beforeCount - $expected && $object($batch[$expected]) !== null, 'Deletion clamp and actual row count for requested limit ' . $requested);
        $result = bms_activitypub_cleanup_remote_cache();
        bms_cache_test_expect($result['unreferenced_objects_removed'] === 1, 'Repeated cleanup drains exactly one excess object for limit ' . $requested);
    }
    // The default deletion limit remains 200.
    for ($n = 0; $n < 201; $n++) {
        $makeObject($ordinaryActor);
    }
    bms_cache_test_expect(bms_activitypub_cleanup_remote_cache()['unreferenced_objects_removed'] === 200, 'Default deletion limit is 200');
    bms_cache_test_expect(bms_activitypub_cleanup_remote_cache()['unreferenced_objects_removed'] === 1, 'Default limit retains the 201st object for the next call');
    bms_cache_test_expect($otherTables() === $relationshipsBefore, 'Limit and retry scenarios preserve all unrelated tables');
    foreach ($protected as $label => $id) {
        bms_cache_test_expect($object($id) !== null, 'Protected identity survives all cleanup scenarios: ' . $label);
    }

    // Each batch exceeds the clearing limit and alternates two blocked actors.
    // Roll back only these synthetic fixtures after verifying repeated progress.
    foreach ([1, 2, 7, 200, 1000] as $batchLimit) {
        $pdo->beginTransaction();
        $batchActors = [$actor('batch-a-' . $batchLimit), $actor('batch-b-' . $batchLimit)];
        foreach ($batchActors as $remote) {
            $insert('activitypub_blocks', ['block_type' => 'actor', 'block_value' => $remote['uri'], 'created_at' => $old, 'updated_at' => $old]);
        }
        $earlyCleared = $makeObject($batchActors[0], ['lifecycle_state' => 'blocked', 'content_html' => '', 'content_text' => '', 'metadata_json' => '{}']);
        $earlyBefore = $object($earlyCleared);
        $blockedBatch = [];
        for ($n = 0; $n < 2 * $batchLimit + 1; $n++) {
            $blockedBatch[] = $makeObject($batchActors[$n % 2]);
        }
        $ordinaryBatch = [];
        for ($n = 0; $n < $batchLimit + 1; $n++) {
            $ordinaryBatch[] = $makeObject($ordinaryActor);
        }
        $remainingClear = count($blockedBatch);
        $remainingDelete = count($ordinaryBatch);
        $calls = 0;
        while ($remainingClear > 0 || $remainingDelete > 0) {
            $beforeBatch = $rows('activitypub_remote_objects');
            $result = bms_activitypub_cleanup_remote_cache($batchLimit);
            $expectedClear = min($batchLimit, $remainingClear);
            $expectedDelete = min($batchLimit, $remainingDelete);
            bms_cache_test_expect($result === ['blocked_content_cleared' => $expectedClear, 'unreferenced_objects_removed' => $expectedDelete], 'Batch limit ' . $batchLimit . ' call ' . $calls . ' reports exact useful work');
            $actualChanges = 0;
            $actualDeletes = 0;
            foreach ($beforeBatch as $row) {
                $after = $object((int)$row['id']);
                if ($after === null) {
                    $actualDeletes++;
                } elseif ($after !== $row) {
                    $actualChanges++;
                }
            }
            bms_cache_test_expect($actualChanges === $expectedClear && $actualDeletes === $expectedDelete, 'Batch limit ' . $batchLimit . ' counts match complete database diffs');
            $retained = 0;
            $uncleared = 0;
            foreach ($blockedBatch as $id) {
                $row = $object($id);
                $retained += $row !== null ? 1 : 0;
                $uncleared += is_array($row) && $row['lifecycle_state'] === 'active' ? 1 : 0;
            }
            $remainingClear -= $expectedClear;
            $remainingDelete -= $expectedDelete;
            bms_cache_test_expect($retained === count($blockedBatch) && $uncleared === $remainingClear, 'All blocked identities survive, including those beyond clearing limit ' . $batchLimit);
            bms_cache_test_expect($object($earlyCleared) === $earlyBefore, 'Already-cleared early record consumes no work at limit ' . $batchLimit);
            $calls++;
            if ($calls > 4) {
                throw new RuntimeException('Clearing failed to make bounded progress.');
            }
        }
        bms_cache_test_expect(bms_activitypub_cleanup_remote_cache($batchLimit) === ['blocked_content_cleared' => 0, 'unreferenced_objects_removed' => 0], 'Exhausted batch is idempotent at limit ' . $batchLimit);
        fwrite(STDOUT, json_encode(['clearing_limit' => $batchLimit, 'blocked_objects' => count($blockedBatch), 'legitimate_expirations' => count($ordinaryBatch), 'calls_to_drain' => $calls, 'blocked_identities_removed' => 0]) . "\n");
        $pdo->rollBack();
    }
    bms_cache_test_expect($otherTables() === $relationshipsBefore, 'Expanded batch fixtures preserve all original relationships');

    $pdo->beginTransaction();
    $dirtyForms = [
        ['lifecycle_state' => 'blocked', 'content_html' => ' ', 'content_text' => '', 'metadata_json' => '{}'],
        ['lifecycle_state' => 'blocked', 'content_html' => '', 'content_text' => "\t", 'metadata_json' => '{}'],
        ['lifecycle_state' => 'blocked', 'content_html' => '', 'content_text' => '', 'metadata_json' => '{} '],
        ['lifecycle_state' => 'active', 'content_html' => '', 'content_text' => '', 'metadata_json' => '{}'],
    ];
    $dirtyIds = array_map(static fn(array $values): int => $makeObject($blockedActor, $values), $dirtyForms);
    $result = bms_activitypub_cleanup_remote_cache();
    bms_cache_test_expect($result === ['blocked_content_cleared' => 4, 'unreferenced_objects_removed' => 0], 'Whitespace, noncanonical metadata and active empty blocked objects are useful clearing work');
    foreach ($dirtyIds as $id) {
        $row = $object($id);
        bms_cache_test_expect($row['lifecycle_state'] === 'blocked' && $row['content_html'] === '' && $row['content_text'] === '' && $row['metadata_json'] === '{}', 'Exact bytes cleared for dirty record ' . $id);
    }
    $pdo->rollBack();

    $otherPdo = new PDO('mysql:host=' . getenv('BMS_DB_HOST') . ';dbname=' . getenv('BMS_DB_NAME') . ';charset=utf8mb4', (string)getenv('BMS_DB_USER'), (string)getenv('BMS_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $otherPdo->exec("SET time_zone = '+00:00'");
    $otherPdo->exec('SET innodb_lock_wait_timeout = 3');
    $raceIndex = 0;
    foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
        foreach (['block', 'following', 'interaction', 'reply', 'tombstone', 'refresh'] as $protection) {
            $raceIndex++;
            $remote = $actor('race-' . $raceIndex);
            // These two early dirty rows exhaust clearing before the race target.
            $dirty = [$makeObject($blockedActor), $makeObject($blockedActor)];
            $target = $makeObject($remote);
            $ordinary = $makeObject($ordinaryActor);
            $targetBefore = $object($target);
            $postId = null;
            if ($protection === 'reply') {
                $postId = $insert('posts', ['post_type' => 'stream', 'slug' => 'concurrent-reply-' . $raceIndex, 'title' => 'Synthetic reply', 'status' => 'draft', 'created_at' => $old, 'updated_at' => $old]);
            }
            BmsCacheFaultStatement::$deletes = [];
            BmsCacheFaultStatement::$deleteAttempts = [];
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BmsCacheFaultStatement::class]);
            $committed = false;
            $committedSnapshot = [];
            BmsCacheFaultStatement::$beforeDelete = static function () use ($otherPdo, $prefix, $protection, $remote, $target, $targetBefore, $postId, $old, $raceIndex, &$committed, &$committedSnapshot): void {
                switch ($protection) {
                    case 'block':
                        $otherPdo->prepare("INSERT INTO `{$prefix}activitypub_blocks` (block_type,block_value,created_at,updated_at) VALUES ('actor',?,?,?)")->execute([$remote['uri'], $old, $old]);
                        break;
                    case 'following':
                        $otherPdo->prepare("INSERT INTO `{$prefix}activitypub_following` (remote_actor_id,actor_uri,follow_activity_uri,state,created_at,updated_at) VALUES (?,?,?,'accepted',?,?)")->execute([$remote['id'], $remote['uri'], $remote['uri'] . '/follow', $old, $old]);
                        break;
                    case 'interaction':
                        $otherPdo->prepare("INSERT INTO `{$prefix}activitypub_owner_interactions` (semantic_key,interaction_type,remote_actor_id,actor_uri,target_object_uri,current_activity_uri,state,created_at,updated_at) VALUES (?,'Like',?,?,?,?,'undone',?,?)")->execute([hash('sha256', 'race-' . $raceIndex), $remote['id'], $remote['uri'], $targetBefore['object_uri'], $remote['uri'] . '/like', $old, $old]);
                        break;
                    case 'reply':
                        $otherPdo->prepare("INSERT INTO `{$prefix}activitypub_reply_targets` (post_id,remote_object_id,remote_actor_id,actor_uri,in_reply_to_uri,created_at,updated_at) VALUES (?,?,?,?,?,?,?)")->execute([$postId, $target, $remote['id'], $remote['uri'], $targetBefore['object_uri'], $old, $old]);
                        break;
                    case 'tombstone':
                        $otherPdo->prepare("UPDATE `{$prefix}activitypub_remote_objects` SET lifecycle_state='deleted',deleted_at=? WHERE id=?")->execute([$old, $target]);
                        break;
                    case 'refresh':
                        $otherPdo->prepare("UPDATE `{$prefix}activitypub_remote_objects` SET expires_at='2030-01-01 00:00:00' WHERE id=?")->execute([$target]);
                        break;
                }
                // Autocommit on a distinct PDO connection establishes a real
                // committed interleaving after selection, before final deletion.
                $committed = !$otherPdo->inTransaction();
                $committedSnapshot = $otherPdo->query("SELECT * FROM `{$prefix}activitypub_remote_objects` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            };
            $concurrencyError = null;
            $result = null;
            try {
                $result = bms_activitypub_cleanup_remote_cache(2);
            } catch (PDOException $e) {
                $concurrencyError = $e;
            } finally {
                BmsCacheFaultStatement::$beforeDelete = null;
                $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
            }
            $label = $isolation . ' concurrent ' . $protection;
            bms_cache_test_expect($committed && BmsCacheFaultStatement::$deleteAttempts === [[$target, $ordinary]], $label . ': DELETE rechecks both originally selected IDs');
            if ($concurrencyError !== null) {
                // MariaDB can reject a changed join row instead of completing
                // the DELETE. That is safe only with full rollback and retry.
                bms_cache_test_expect(in_array((int)($concurrencyError->errorInfo[1] ?? 0), [1020, 1205, 1213], true), $label . ': recognized database concurrency conflict');
                bms_cache_test_expect($result === null && !$pdo->inTransaction() && $rows('activitypub_remote_objects') === $committedSnapshot, $label . ': conflict returns no success and rolls back all cleanup writes');
                $result = bms_activitypub_cleanup_remote_cache(2);
                fwrite(STDOUT, json_encode(['concurrency_conflict' => $label, 'sqlstate' => $concurrencyError->getCode(), 'engine_code' => (int)$concurrencyError->errorInfo[1], 'rollback_verified' => true, 'retry_succeeded' => true]) . "\n");
            } else {
                bms_cache_test_expect(BmsCacheFaultStatement::$deletes === [['ids' => [$target, $ordinary], 'affected' => 1]], $label . ': only one selected row is actually deleted');
            }
            bms_cache_test_expect($result === ['blocked_content_cleared' => 2, 'unreferenced_objects_removed' => 1] && $object($target) !== null && $object($ordinary) === null, $label . ': new protection wins while legitimate expiration proceeds');
            if ($protection === 'block') {
                bms_cache_test_expect($object($target) === $targetBefore && $object($target)['lifecycle_state'] === 'active', $label . ': retains full object without relying on a blocked lifecycle flag');
            }
            fwrite(STDOUT, json_encode(['isolation' => $isolation, 'concurrent_protection' => $protection, 'target_retained' => true, 'ordinary_removed' => true, 'result' => $result]) . "\n");
            foreach (['activitypub_blocks' => 'block_value', 'activitypub_following' => 'actor_uri', 'activitypub_owner_interactions' => 'actor_uri', 'activitypub_reply_targets' => 'actor_uri'] as $table => $column) {
                $pdo->prepare("DELETE FROM `{$prefix}{$table}` WHERE `{$column}`=?")->execute([$remote['uri']]);
            }
            $pdo->prepare("DELETE FROM `{$prefix}activitypub_remote_objects` WHERE id IN (?,?,?,?)")->execute([$dirty[0], $dirty[1], $target, $ordinary]);
            $pdo->prepare("DELETE FROM `{$prefix}activitypub_remote_actors` WHERE id=?")->execute([$remote['id']]);
            if ($postId !== null) {
                $pdo->prepare("DELETE FROM `{$prefix}posts` WHERE id=?")->execute([$postId]);
            }
        }
    }
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $otherPdo = null;
    bms_cache_test_expect($otherTables() === $relationshipsBefore, 'Concurrent fixture cleanup restores all original non-cache records');


    // Return to wall time for the independent HTTP connection.
    $pdo->exec('SET timestamp = 0');
    $pdo->prepare("UPDATE `{$prefix}activitypub_remote_objects` SET expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 29 DAY) WHERE id=?")->execute([$protected['within retention grace']]);
    $pdo->prepare("UPDATE `{$prefix}activitypub_remote_objects` SET expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id=?")->execute([$protected['exact 30-day boundary']]);
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
    bms_cache_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $sessions = [];
    $loginTokens = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_cache_test_request($base, '/admin/login.php', $cookies);
        $loginTokens[$username] = bms_cache_test_token($login['body']);
        $login = bms_cache_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => $loginTokens[$username], 'username' => $username, 'password' => 'disposable-password']);
        bms_cache_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }
    $owner =& $sessions['owner'];
    $page = bms_cache_test_request($base, '/admin/activitypub.php', $owner);
    $csrf = bms_cache_test_token($page['body']);
    $httpBlocked = $makeObject($blockedActor);
    $httpExpired = $makeObject($ordinaryActor);
    $httpBefore = $rows('activitypub_remote_objects');
    foreach (['GET', 'POST'] as $method) {
        $guest = [];
        $form = $method === 'POST' ? ['csrf_token' => $csrf, 'activitypub_action' => 'cleanup_remote_cache'] : null;
        $response = bms_cache_test_request($base, '/admin/activitypub.php', $guest, $form);
        bms_cache_test_expect($response['status'] === 302 && str_contains($response['location'], 'login.php'), 'Cleanup rejects anonymous ' . $method);
        $response = bms_cache_test_request($base, '/admin/activitypub.php', $sessions['commenter'], $form);
        bms_cache_test_expect($response['status'] === 403, 'Cleanup manage_settings capability rejects commenter ' . $method);
    }
    foreach (['', 'invalid-token'] as $token) {
        $response = bms_cache_test_request($base, '/admin/activitypub.php', $owner, ['csrf_token' => $token, 'activitypub_action' => 'cleanup_remote_cache']);
        bms_cache_test_expect($response['status'] === 403, 'Cleanup rejects ' . ($token === '' ? 'missing' : 'invalid') . ' CSRF token');
    }
    $response = bms_cache_test_request($base, '/admin/activitypub.php?activitypub_action=cleanup_remote_cache', $owner);
    bms_cache_test_expect($response['status'] === 200 && $rows('activitypub_remote_objects') === $httpBefore, 'GET action and rejected POSTs do not mutate the cache');
    // Test-only FK guard forces a real engine DELETE failure after UPDATE,
    // without requiring global privileges or server configuration changes.
    $guard = $prefix . 'cleanup_guard';
    $pdo->exec("CREATE TABLE `{$guard}` (object_id BIGINT UNSIGNED PRIMARY KEY, FOREIGN KEY (object_id) REFERENCES `{$prefix}activitypub_remote_objects` (id)) ENGINE=InnoDB");
    $pdo->prepare("INSERT INTO `{$guard}` (object_id) VALUES (?)")->execute([$httpExpired]);
    $response = bms_cache_test_request($base, '/admin/activitypub.php', $owner, ['csrf_token' => $csrf, 'activitypub_action' => 'cleanup_remote_cache']);
    bms_cache_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/activitypub.php'), 'Failed cleanup redirects to ActivityPub');
    $html = bms_cache_test_request($base, '/admin/activitypub.php', $owner)['body'];
    bms_cache_test_expect(str_contains($html, 'could not be completed') && !str_contains($html, 'Remote cache cleanup cleared'), 'SQL failure displays an error and no success message');
    bms_cache_test_expect($rows('activitypub_remote_objects') === $httpBefore, 'HTTP failure rolls back blocked updates and retains every protected record');
    $pdo->exec("DROP TABLE `{$guard}`");
    // Previously-cleared rows do not count as mutations, even with old timestamps.
    $expectedBlocked = 1;
    $beforeHttpSuccess = $rows('activitypub_remote_objects');
    $response = bms_cache_test_request($base, '/admin/activitypub.php', $owner, ['csrf_token' => $csrf, 'activitypub_action' => 'cleanup_remote_cache']);
    bms_cache_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/activitypub.php'), 'Successful cleanup redirects to ActivityPub');
    $html = bms_cache_test_request($base, '/admin/activitypub.php', $owner)['body'];
    bms_cache_test_expect(str_contains($html, 'Remote cache cleanup cleared ' . $expectedBlocked . ' blocked object(s) and removed 1 expired, unreferenced object(s).') && !str_contains($html, 'could not be completed'), 'Successful POST reports exact actual operation counts');
    bms_cache_test_expect(count($rows('activitypub_remote_objects')) === count($beforeHttpSuccess) - 1 && $object($httpExpired) === null && $object($httpBlocked)['lifecycle_state'] === 'blocked', 'Successful HTTP retry clears content and deletes the one eligible object');
    bms_cache_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}activitypub_deliveries`")->fetchColumn() === 0, 'All cleanup tests create no federation deliveries');
    bms_cache_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Cleanup introduces no schema migration');
    fwrite(STDOUT, 'Completed cleanup checks: ' . $checks . "; retention assertion follows.\n");
    bms_cache_test_expect(!$blockedLimitViolation, 'Blocked actor identity beyond the clearing limit must survive cleanup');
    fwrite(STDOUT, 'Cache cleanup regression passed: ' . $checks . " checks.\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (str_starts_with((string)$table, $prefix)) {
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    bms_cache_test_remove_tree($temporary);
}
