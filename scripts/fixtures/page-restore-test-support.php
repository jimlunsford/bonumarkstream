<?php
/** Test-only PDO faults and independent worker. Copied into a disposable fixture. */
declare(strict_types=1);

class BmsPageRestoreTestPDO extends PDO
{
    public static string $fault = '';
    public static $beforeInsert = null;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BmsPageRestoreTestStatement::class]);
    }

    public function beginTransaction(): bool
    {
        if (self::$fault === 'begin-false') { self::$fault = ''; return false; }
        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        if (self::$fault === 'commit-throw') { self::$fault = ''; throw new RuntimeException('Injected commit failure before COMMIT'); }
        if (self::$fault === 'commit-false') { self::$fault = ''; return false; }
        return parent::commit();
    }

    public function exec(string $statement): int|false
    {
        foreach (['savepoint-false' => 'SAVEPOINT bms_page_restore_', 'release-false' => 'RELEASE SAVEPOINT bms_page_restore_', 'creation-savepoint-false' => 'SAVEPOINT bms_page_create_', 'creation-release-false' => 'RELEASE SAVEPOINT bms_page_create_'] as $fault => $prefix) {
            if (self::$fault === $fault && str_starts_with($statement, $prefix)) { self::$fault = ''; return false; }
        }
        return parent::exec($statement);
    }
}

class BmsPageRestoreTestStatement extends PDOStatement
{
    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        $sql = $this->queryString;
        $insert = str_starts_with($sql, 'INSERT INTO ') && str_contains($sql, 'posts ');
        $delete = str_starts_with($sql, 'DELETE FROM ') && str_contains($sql, 'trash ');
        $terms = str_starts_with($sql, 'INSERT IGNORE INTO ') && str_contains($sql, 'post_terms ');
        $lock = str_starts_with($sql, 'SELECT ') && str_contains($sql, 'trash ') && str_contains($sql, 'FOR UPDATE');
        if ($insert && is_callable(BmsPageRestoreTestPDO::$beforeInsert)) {
            $callback = BmsPageRestoreTestPDO::$beforeInsert;
            BmsPageRestoreTestPDO::$beforeInsert = null;
            $callback();
        }
        $marker = (string)getenv('BMS_RESTORE_WORKER_MARKER');
        if ($marker !== '' && ($lock || $insert)) {
            file_put_contents($marker, json_encode(['phase' => $lock ? 'before-lock' : 'before-insert', 'connection' => (int)bms_db()->query('SELECT CONNECTION_ID()')->fetchColumn()]));
        }
        $fault = BmsPageRestoreTestPDO::$fault;
        $matches = ($insert && in_array($fault, ['insert-before', 'insert-after', 'insert-false', 'insert-noop'], true))
            || ($delete && in_array($fault, ['delete-before', 'delete-after', 'delete-false', 'delete-noop'], true))
            || ($terms && $fault === 'terms-after') || ($lock && $fault === 'lock-before');
        if ($matches) {
            BmsPageRestoreTestPDO::$fault = '';
            if (str_ends_with($fault, '-noop')) { return true; }
            if (str_ends_with($fault, '-false')) { return false; }
            if (!str_ends_with($fault, '-after')) { throw new RuntimeException('Injected ' . $fault); }
        }
        $result = parent::execute($params);
        if ($matches) { throw new RuntimeException('Injected ' . $fault); }
        return $result;
    }
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--worker') {
    $root = (string)($argv[2] ?? '');
    if (!str_starts_with(basename($root), 'bms-restore-test-') || getenv('BMS_DB_DANGER_RESET') !== '1') {
        throw new RuntimeException('Disposable restoration fixture required.');
    }
    require $root . '/_bonumark_stream/app/pages.php';
    $_SESSION['bms_logged_in'] = true;
    $_SESSION['bms_user_id'] = 1;
    bms_db()->exec('SET innodb_lock_wait_timeout = 5');
    bms_db()->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . (($argv[5] ?? '') === 'READ COMMITTED' ? 'READ COMMITTED' : 'REPEATABLE READ'));
    try {
        if (($argv[3] ?? '') === 'restore') {
            $result = bms_restore_page_trash_item((int)$argv[4]);
        } else {
            $page = bms_parse_markdown_string("---\ntitle: Competing Page\nslug: " . $argv[4] . "\nstatus: draft\ncontent_type: page\ndate: 2026-01-14\n---\nIndependent competing body.\n");
            if (($argv[3] ?? '') === 'create-late') {
                if (bms_find_database_content_by_slug_status($argv[4], 'draft', 'page') || bms_find_database_content_by_slug_status($argv[4], 'published', 'page')) {
                    throw new RuntimeException('Creator found an early collision.');
                }
                $marker = (string)getenv('BMS_RESTORE_WORKER_MARKER');
                file_put_contents($marker, json_encode(['phase' => 'creation-checked']));
                $deadline = microtime(true) + 5;
                while (!is_file($marker . '.continue')) {
                    if (microtime(true) > $deadline) { throw new RuntimeException('Creator barrier timed out.'); }
                    usleep(10000);
                }
            }
            bms_insert_new_page($page, 'pages/drafts', $argv[4] . '.md', 1);
            $result = ['slug' => $argv[4]];
        }
        fwrite(STDOUT, json_encode(['ok' => true, 'result' => $result, 'transaction_open' => bms_db()->inTransaction()]) . "\n");
    } catch (Throwable $e) {
        fwrite(STDOUT, json_encode(['ok' => false, 'error' => $e->getMessage(), 'transaction_open' => bms_db()->inTransaction()]) . "\n");
    }
    exit;
}
