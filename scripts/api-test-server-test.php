<?php
/** Deterministic subprocess/HTTP regressions; no database or application fault switches. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once $argv[2] ?? __DIR__ . '/api-test-server.php';

function bms_server_check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS {$message}\n";
}
function bms_server_get(string $url): string
{
    return (string)file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]));
}

$root = sys_get_temp_dir() . '/bms-startup-regression-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
file_put_contents($root . '/index.php', '<?php echo "intended application";');
$events = [];
$observe = static function (string $event, array $data) use (&$events): void { $events[] = [$event, $data]; };
$cleanupCheck = static function () use (&$events): void {
    foreach ($events as [$event, $data]) {
        if ($event !== 'spawn') { continue; }
        bms_server_check(!is_dir($data['directory']), 'launch files removed for PID ' . $data['pid']);
        if (is_dir('/proc')) {
            bms_server_check(!file_exists('/proc/' . $data['pid']), 'child reaped for PID ' . $data['pid']);
        }
    }
    $events = [];
};
$foreign = null;
$server = null;
try {
    // A separate fixture acts as an unrelated, successful HTTP listener throughout collisions.
    file_put_contents($root . '/foreign.php', '<?php usleep(100000); echo "foreign listener unchanged";');
    $foreign = BmsApiTestServer::start($root, $root . '/foreign.php');
    $foreignPort = (int)parse_url($foreign->base, PHP_URL_PORT);
    $foreignBefore = bms_server_get($foreign->base . '/anything');
    $processResources = count(get_resources('process'));
    $streamResources = count(get_resources('stream'));
    $server = BmsApiTestServer::start($root, null, [], static fn(int $n): int => $n === 1 ? $foreignPort : BmsApiTestServer::availablePort(), $observe);
    bms_server_check(count($server->diagnostics) === 1 && str_contains($server->diagnostics[0], 'Confirmed bind collision'), 'occupied first port detected and retried exactly once');
    bms_server_check(bms_server_get($server->base . '/index.php') === 'intended application', 'application request reaches intended fixture after collision');
    $server->stop(); $server = null; $cleanupCheck();
    bms_server_check(bms_server_get($foreign->base . '/anything') === $foreignBefore, 'unrelated HTTP listener remains unchanged after collision cleanup');

    $start = microtime(true);
    try {
        $server = BmsApiTestServer::start($root, null, [], static fn(): int => $foreignPort, $observe);
        throw new LogicException('Exhaustion accepted unrelated HTTP 200');
    } catch (RuntimeException $e) {
        bms_server_check(str_contains($e->getMessage(), 'attempts exhausted'), 'four collisions produce explicit exhaustion failure');
    }
    bms_server_check(count(array_filter($events, static fn($e) => $e[0] === 'spawn')) === 4, 'exhaustion has exactly four attempts');
    bms_server_check(microtime(true) - $start < 12, 'exhaustion is bounded');
    $cleanupCheck();
    bms_server_check(bms_server_get($foreign->base . '/anything') === $foreignBefore, 'exhaustion preserves unrelated listener');

    // Wrong and stale identities from a live child cannot pass merely because it owns the port.
    foreach (['stale-nonce:123', '', 'correct-nonce-wrong-pid'] as $identity) {
        $calls = 0;
        $start = microtime(true);
        try {
            $server = BmsApiTestServer::start($root, null, [], null,
                static function ($event, $data) use ($observe, $identity, &$calls): void {
                    $observe($event, $data);
                    if ($event === 'spawn') {
                        $calls++;
                        if ($identity === 'correct-nonce-wrong-pid') { $identity = substr($data['path'], strlen('/__bms_test_ready_')) . ':' . ($data['pid'] + 1); }
                        file_put_contents($data['directory'] . '/router.php', '<?php echo ' . var_export($identity, true) . ';');
                    }
                });
            throw new LogicException('Incorrect identity was accepted');
        } catch (RuntimeException $e) {
            bms_server_check(str_contains($e->getMessage(), 'identity mismatch'), 'wrong/stale HTTP 200 identity rejected');
        }
        bms_server_check($calls === 1 && microtime(true) - $start < 4, 'identity failure is bounded and never retried');
        $cleanupCheck();
    }

    foreach (['exit', 'router', 'silent'] as $failure) {
        $start = microtime(true);
        try {
            $server = BmsApiTestServer::start($root, null, $failure === 'exit' ? ['--invalid-bms-option'] : [], null,
                static function ($event, $data) use ($observe, $failure): void {
                    $observe($event, $data);
                    if ($event === 'spawn' && $failure !== 'exit') {
                        file_put_contents($data['directory'] . '/router.php', $failure === 'router' ? '<?php this is invalid syntax' : '<?php sleep(30);');
                    }
                });
            throw new LogicException('Startup failure was accepted');
        } catch (RuntimeException $e) {
            bms_server_check(str_contains($e->getMessage(), $failure === 'exit' ? 'Child exited' : 'Readiness deadline'), $failure . ' produces useful failure diagnostics');
            if ($failure === 'router') { bms_server_check(str_contains($e->getMessage(), 'Parse error'), 'router error log preserved'); }
        }
        bms_server_check(count(array_filter($events, static fn($e) => $e[0] === 'spawn')) === 1 && microtime(true) - $start < 4, $failure . ' fails without retries within deadline');
        $cleanupCheck();
    }
    try {
        BmsApiTestServer::start($root . '/missing');
        throw new LogicException('Invalid configuration accepted');
    } catch (RuntimeException $e) {
        bms_server_check(str_contains($e->getMessage(), 'Invalid'), 'invalid configuration fails before launch');
    }

    $assertions = 0;
    try {
        $server = BmsApiTestServer::start($root, null, [], null, $observe);
        try { $assertions++; throw new RuntimeException('intentional application assertion'); }
        finally { $server->stop(); $server = null; }
    } catch (RuntimeException $e) {
        bms_server_check($e->getMessage() === 'intentional application assertion', 'application failure preserved unchanged');
    }
    bms_server_check($assertions === 1 && count(array_filter($events, static fn($e) => $e[0] === 'spawn')) === 1, 'application assertion executed once with no restart');
    $cleanupCheck();
    for ($i = 0; $i < 10; $i++) {
        $server = BmsApiTestServer::start($root, $root . '/index.php', [], null, $observe);
        bms_server_check(bms_server_get($server->base . '/route') === 'intended application', 'ordinary routed operation ' . $i);
        $server->stop(); $server = null;
    }
    $cleanupCheck();
    bms_server_check(bms_server_get($foreign->base . '/anything') === $foreignBefore, 'unrelated listener survives complete regression run');
    bms_server_check(count(get_resources('process')) === $processResources, 'no leaked process handles');
    bms_server_check(count(get_resources('stream')) === $streamResources, 'no leaked stream or pipe handles');
    echo "API test-server regression passed.\n";
} finally {
    if ($server !== null) { $server->stop(); }
    if ($foreign !== null) { $foreign->stop(); }
    foreach (glob($root . '/*') as $file) { unlink($file); }
    rmdir($root);
}
