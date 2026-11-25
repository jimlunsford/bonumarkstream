<?php
/** Test-only PDO instrumentation, loaded only into disposable copies. */
declare(strict_types=1);
class BmsPageCreationTestPDO extends PDO
{
    public static string $fault = '';
    public static array $reached = [];
    public static array $savepoints = [];
    public static $beforeTermInsert = null;
    public static int $associations = 0;
    public static string $cleanupFault = '';
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BmsPageCreationTestStatement::class]);
        $file = __DIR__ . '/creation-fault';
        if (is_file($file)) { self::$fault = trim(file_get_contents($file)); unlink($file); }
    }
    public static function hit(string $fault): bool
    {
        if (self::$fault !== $fault) { return false; }
        self::$fault = ''; self::$reached[] = $fault;
        return true;
    }
    public function beginTransaction(): bool
    {
        if (self::hit('begin-false')) { return false; }
        return parent::beginTransaction();
    }
    public function commit(): bool
    {
        if (self::hit('commit-false')) { return false; }
        if (self::hit('commit-throw')) { throw new RuntimeException('Injected failure BEFORE COMMIT'); }
        return parent::commit();
    }
    public function rollBack(): bool
    {
        if (self::$cleanupFault === 'rollback-false') { self::$reached[] = self::$cleanupFault; self::$cleanupFault = ''; return false; }
        return parent::rollBack();
    }
    public function exec(string $sql): int|false
    {
        if (str_starts_with($sql, 'SAVEPOINT bms_page_create_')) { self::$savepoints[] = substr($sql, 10); }
        foreach (['savepoint-false' => 'SAVEPOINT bms_page_create_', 'release-false' => 'RELEASE SAVEPOINT bms_page_create_'] as $fault => $prefix) {
            if (str_starts_with($sql, $prefix) && self::hit($fault)) { return false; }
        }
        foreach (['rollback-savepoint-false' => 'ROLLBACK TO SAVEPOINT bms_page_create_', 'cleanup-release-false' => 'RELEASE SAVEPOINT bms_page_create_'] as $fault => $prefix) {
            if (self::$cleanupFault === $fault && str_starts_with($sql, $prefix)) { self::$reached[] = $fault; self::$cleanupFault = ''; return false; }
        }
        return parent::exec($sql);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with($query, 'SELECT id FROM ') && str_contains($query, 'terms ') && self::hit('lookup-prepare-false')) { return false; }
        return parent::prepare($query, $options);
    }
}
class BmsPageCreationTestStatement extends PDOStatement
{
    private bool $failedFetch = false;
    protected function __construct() {}
    public function fetchColumn(int $column = 0): mixed
    {
        if (str_starts_with($this->queryString, 'SELECT id FROM ') && str_contains($this->queryString, 'terms ') && BmsPageCreationTestPDO::hit('lookup-fetch-false')) {
            $this->failedFetch = true; return false;
        }
        return parent::fetchColumn($column);
    }
    public function errorCode(): ?string
    {
        return $this->failedFetch ? 'HY000' : parent::errorCode();
    }
    public function execute(?array $params = null): bool
    {
        $sql = $this->queryString;
        $insert = str_starts_with($sql, 'INSERT INTO ') && str_contains($sql, 'posts ');
        $lookup = str_starts_with($sql, 'SELECT id FROM ') && str_contains($sql, 'terms ');
        $term = str_starts_with($sql, 'INSERT INTO ') && str_contains($sql, 'terms ') && !str_contains($sql, 'post_terms ');
        $clear = str_starts_with($sql, 'DELETE FROM ') && str_contains($sql, 'post_terms ');
        $assoc = str_starts_with($sql, 'INSERT IGNORE INTO ') && str_contains($sql, 'post_terms ');
        if ($insert) { BmsPageCreationTestPDO::$associations = 0; }
        if ($assoc) { BmsPageCreationTestPDO::$associations++; }
        if ($term && is_callable(BmsPageCreationTestPDO::$beforeTermInsert)) {
            $callback = BmsPageCreationTestPDO::$beforeTermInsert; BmsPageCreationTestPDO::$beforeTermInsert = null; $callback();
        }
        $marker = (string)getenv('BMS_CREATION_WORKER_MARKER');
        if ($insert && $marker !== '') { file_put_contents($marker, json_encode(['connection' => (int)bms_db()->query('SELECT CONNECTION_ID()')->fetchColumn()])); }
        $fault = BmsPageCreationTestPDO::$fault;
        $match = ($insert && in_array($fault, ['insert-before','insert-after','insert-false'], true))
            || ($lookup && in_array($fault, ['lookup-false','lookup-throw'], true))
            || ($term && in_array($fault, ['term-false','term-after'], true))
            || ($clear && $fault === 'clear-false')
            || ($assoc && $fault === 'terms-after')
            || ($assoc && BmsPageCreationTestPDO::$associations === 3 && in_array($fault, ['association-false','association-after','transaction-lost'], true));
        if ($match) {
            BmsPageCreationTestPDO::hit($fault);
            if (str_ends_with($fault, '-false')) { return false; }
            if ($fault === 'transaction-lost') { bms_db()->rollBack(); throw new RuntimeException('Injected transaction-wide rollback'); }
            if (!str_ends_with($fault, '-after')) { throw new RuntimeException('Injected ' . $fault); }
        }
        $result = parent::execute($params);
        if ($match) { throw new RuntimeException('Injected ' . $fault); }
        return $result;
    }
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--worker') {
    $root = (string)($argv[2] ?? '');
    if (!str_starts_with(basename($root), 'bms-creation-test-') || getenv('BMS_DB_DANGER_RESET') !== '1') {
        throw new RuntimeException('Disposable creation fixture required.');
    }
    require $root . '/_bonumark_stream/app/pages.php';
    $page = json_decode(base64_decode($argv[3]), true, 512, JSON_THROW_ON_ERROR);
    bms_db()->exec('SET innodb_lock_wait_timeout = 5');
    bms_db()->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . (($argv[4] ?? '') === 'READ COMMITTED' ? 'READ COMMITTED' : 'REPEATABLE READ'));
    try {
        $id = bms_insert_new_page($page, $page['status'] === 'published' ? 'pages/published' : 'pages/drafts', $page['slug'] . '.md', 1);
        echo json_encode(['ok' => true, 'id' => $id, 'transaction_open' => bms_db()->inTransaction()]) . "\n";
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'transaction_open' => bms_db()->inTransaction()]) . "\n";
    }
    exit;
}
