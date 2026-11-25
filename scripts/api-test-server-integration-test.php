<?php
/** Force a real first-bind collision in BOTH Remote API HTTP scenarios, in a disposable copy. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/api-test-server.php';
if (getenv('BMS_DB_DANGER_RESET') !== '1') { fwrite(STDERR, "BMS_DB_DANGER_RESET=1 required.\n"); exit(1); }
$root = sys_get_temp_dir() . '/bms-api-collision-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$foreign = null;
$process = null;
try {
    $source = dirname(__DIR__);
    mkdir($root . '/source', 0700);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        if (str_starts_with($relative, '.git' . DIRECTORY_SEPARATOR) || $relative === '.git'
            || in_array(str_replace(DIRECTORY_SEPARATOR, '/', $relative), ['_bonumark_stream/config.php', '_bonumark_stream/installed.lock'], true)) { continue; }
        $target = $root . '/source/' . $relative;
        if ($item->isDir()) { mkdir($target, 0700); }
        elseif ($item->isFile()) { copy($item->getPathname(), $target); }
    }
    file_put_contents($root . '/foreign.php', '<?php file_put_contents(' . var_export($root . '/requests.log', true) . ', $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND); echo "foreign unchanged";');
    $foreign = BmsApiTestServer::start($root, $root . '/foreign.php');
    $port = (int)parse_url($foreign->base, PHP_URL_PORT);
    $helper = $root . '/source/scripts/api-test-server.php';
    $code = (string)file_get_contents($helper);
    $anchor = '$port = $selectPort !== null ? $selectPort($attempt) : self::availablePort();';
    $code = str_replace($anchor, '$port = $attempt === 1 ? ' . $port . ' : self::availablePort();', $code, $count);
    if ($count !== 1) { throw new RuntimeException('Collision instrumentation anchor changed'); }
    $code = str_replace('$server->diagnostics[] = $message;', '$server->diagnostics[] = $message; file_put_contents(' . var_export($root . '/collisions.log', true) . ', $message . "\n", FILE_APPEND);', $code);
    file_put_contents($helper, $code);
    $process = proc_open([PHP_BINARY, $root . '/source/scripts/api-database-smoke-test.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $root . '/suite.log', 'a'], 2 => ['file', $root . '/suite.log', 'a']], $pipes, $root . '/source');
    if (!is_resource($process)) { throw new RuntimeException('Cannot launch collision integration suite'); }
    fclose($pipes[0]);
    $deadline = hrtime(true) + 120_000_000_000;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) { break; }
        usleep(100000);
    } while (hrtime(true) < $deadline);
    if ($state['running']) { throw new RuntimeException('Collision integration suite exceeded 120 seconds'); }
    $exit = $state['exitcode'];
    proc_close($process); $process = null;
    $output = (string)file_get_contents($root . '/suite.log');
    echo $output;
    if ($exit !== 0 || !str_contains($output, 'Remote API database smoke test passed.')) {
        throw new RuntimeException('Collision integration smoke suite failed, exit ' . $exit);
    }
    $collisionLog = (string)file_get_contents($root . '/collisions.log');
    echo $collisionLog;
    if (substr_count($collisionLog, 'Confirmed bind collision') !== 2) {
        throw new RuntimeException('Expected exactly two confirmed collisions, one in each HTTP scenario');
    }
    // Each scenario's parent normally suppresses successful child stderr. Startup diagnostics
    // are verified by the focused tests; this test verifies actual route assertions and isolation.
    $requests = is_file($root . '/requests.log') ? file($root . '/requests.log', FILE_IGNORE_NEW_LINES) : [];
    foreach ($requests as $request) {
        if (!str_starts_with($request, '/__bms_test_ready_')) {
            throw new RuntimeException('Application assertion reached unrelated listener: ' . $request);
        }
    }
    $response = file_get_contents($foreign->base . '/still-alive');
    if ($response !== 'foreign unchanged') { throw new RuntimeException('Unrelated listener changed'); }
    echo "PASS complete Remote API suite with forced first-bind collisions in both HTTP launchers; unrelated listener unchanged; no application assertions reached it.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
    if ($foreign !== null) { $foreign->stop(); }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($root);
}
