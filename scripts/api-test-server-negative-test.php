<?php
/** Baseline reproduction plus a mutation control exercising the same regression assertions. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/api-test-server.php';
require_once __DIR__ . '/fixtures/api-server-baseline.php';
$root = sys_get_temp_dir() . '/bms-server-negative-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
file_put_contents($root . '/index.php', '<?php usleep(100000); echo "unrelated HTTP 200";');
$foreign = null;
try {
    $foreign = BmsApiTestServer::start($root, $root . '/index.php');
    $assertions = 0;
    try {
        bms_server_baseline($root, (int)parse_url($foreign->base, PHP_URL_PORT), static function ($base) use (&$assertions): void {
            $assertions++;
            $actor = bms_api_smoke_http_request($base . 'activitypub_actor');
            if ($actor['status'] !== 200 || !str_contains($actor['body'], '"inbox"')) {
                throw new RuntimeException('Baseline application assertion failed against unrelated listener');
            }
        });
        throw new LogicException('Baseline negative control unexpectedly passed');
    } catch (RuntimeException $e) {
        if ($assertions !== 1 || !str_contains($e->getMessage(), 'unrelated listener')) { throw $e; }
        if (!str_contains($GLOBALS['bms_server_baseline_log'], 'Address already in use')) { throw new RuntimeException('Baseline bind failure missing'); }
        echo "PASS baseline falsely accepts unrelated HTTP 200 and fails the subsequent assertion\n";
    }
    // Uncaught failures must exit nonzero, not merely report an exception to a caller.
    foreach (['exhaustion', 'exit', 'assertion'] as $case) {
        $child = '<?php require ' . var_export(__DIR__ . '/api-test-server.php', true) . ';'
            . '$root = ' . var_export($root, true) . '; $server = null; $held = null;'
            . 'try {';
        if ($case === 'exhaustion') {
            $child .= '$held = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);'
                . '$address = stream_socket_get_name($held, false); $port = (int)substr($address, strrpos($address, ":") + 1);'
                . '$server = BmsApiTestServer::start($root, null, [], static fn() => $port);';
        } elseif ($case === 'exit') {
            $child .= '$server = BmsApiTestServer::start($root, null, ["--invalid-bms-option"]);';
        } else {
            $child .= '$server = BmsApiTestServer::start($root); echo "ASSERTION_ONCE\n"; throw new RuntimeException("intentional application assertion");';
        }
        $child .= '} finally { if ($server !== null) { $server->stop(); } if (is_resource($held)) { fclose($held); } }';
        file_put_contents($root . '/failure.php', $child);
        $started = microtime(true);
        $process = proc_open([PHP_BINARY, $root . '/failure.php'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/' . $case . '.log', 'a'], 2 => ['file', $root . '/' . $case . '.log', 'a']], $pipes);
        fclose($pipes[0]);
        $status = proc_close($process);
        $failureLog = (string)file_get_contents($root . '/' . $case . '.log');
        echo $failureLog;
        if ($status === 0 || microtime(true) - $started > 12
            || ($case === 'assertion' && substr_count($failureLog, 'ASSERTION_ONCE') !== 1)) {
            throw new RuntimeException('Uncaught ' . $case . ' did not fail once within its bound');
        }
        echo "PASS uncaught {$case} exits {$status} within twelve seconds\n";
    }
    $source = (string)file_get_contents(__DIR__ . '/api-test-server.php');
    $source = str_replace('$mismatch = $mismatch || !$owned;', '$owned = true; $mismatch = false;', $source, $count);
    if ($count !== 1) { throw new RuntimeException('Mutation anchor changed'); }
    file_put_contents($root . '/mutant.php', $source);
    $process = proc_open([PHP_BINARY, __DIR__ . '/api-test-server-test.php', '--helper', $root . '/mutant.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $root . '/mutant.log', 'a'], 2 => ['file', $root . '/mutant.log', 'a']], $pipes);
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = (string)file_get_contents($root . '/mutant.log');
    echo $log;
    if ($exit === 0 || !str_contains($log, 'Incorrect identity was accepted')) {
        throw new RuntimeException('Regression did not reject HTTP-only readiness mutation');
    }
    echo "PASS regression rejects HTTP-only readiness mutation with exit {$exit}\n";
} finally {
    if ($foreign !== null) { $foreign->stop(); }
    foreach (glob($root . '/*') as $file) { unlink($file); }
    rmdir($root);
}
