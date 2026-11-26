<?php
/**
 * Account and session security unit test. Needs no database.
 *
 * Covers the sign-in throttle (growing delays per username and network, and
 * per network across usernames, with no lock on a username alone), the
 * network key used for throttling, and the session credential-generation
 * check. Every check is reported, so a run against an older tree shows
 * exactly which protections are missing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

// Load the auth helpers without starting a PHP session.
define('BMS_STATELESS_PROTOCOL_REQUEST', true);
$root = dirname(__DIR__);
require_once $root . '/_bonumark_stream/app/auth.php';

$results = ['pass' => 0, 'fail' => 0, 'failed' => []];

function bms_account_check(string $label, bool $condition): void
{
    global $results;
    $results[$condition ? 'pass' : 'fail']++;
    if (!$condition) {
        $results['failed'][] = $label;
    }
    fwrite(STDOUT, ($condition ? 'PASS ' : 'FAIL ') . $label . "\n");
}

function bms_account_section(string $label, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        bms_account_check($label . ' ran without error (' . get_class($e) . ': ' . $e->getMessage() . ')', false);
    }
}

/** @return list<array{username:string,success:bool,time:int}> */
function bms_account_failures(string $username, int $count, int $start, int $step = 1): array
{
    $attempts = [];
    for ($i = 0; $i < $count; $i++) {
        $attempts[] = ['username' => $username, 'success' => false, 'time' => $start + ($i * $step)];
    }
    return $attempts;
}

bms_account_section('Throttle delays', static function (): void {
    $policy = bms_login_throttle_policy();
    bms_account_check('The throttle policy has no username-only rule', !array_key_exists('username_free_failures', $policy) && array_key_exists('pair_free_failures', $policy) && array_key_exists('network_free_failures', $policy));
    $free = (int)$policy['pair_free_failures'];
    $delays = [];
    for ($failures = 0; $failures <= $free + 12; $failures++) {
        $delays[] = bms_login_throttle_delay($failures, $free, $policy);
    }
    bms_account_check('Failures below the free count add no delay', array_slice($delays, 0, $free) === array_fill(0, $free, 0));
    $growing = array_slice($delays, $free);
    $strictlyGrowing = true;
    for ($i = 1; $i < count($growing); $i++) {
        if ($growing[$i] < $growing[$i - 1] || ($growing[$i - 1] < (int)$policy['max_delay_seconds'] && $growing[$i] <= $growing[$i - 1])) {
            $strictlyGrowing = false;
        }
    }
    bms_account_check('Each further failure doubles the delay until the cap', $growing[0] === (int)$policy['base_delay_seconds'] && $growing[1] === 2 * $growing[0] && $strictlyGrowing);
    bms_account_check('The delay never exceeds the cap', max($delays) === (int)$policy['max_delay_seconds']);
});

bms_account_section('Throttle evaluation', static function (): void {
    $now = 1_800_000_000;
    // Ten failures for the owner from one network, one per second.
    $owner = bms_account_failures('owner', 10, $now - 10);
    $state = bms_login_throttle_evaluate($owner, 'owner', $now);
    bms_account_check('Ten fast failures slow the next attempt for that username from that network', $state['limited'] === true && $state['retry_after'] > 0 && $state['pair_failures'] === 10);

    $later = bms_login_throttle_evaluate($owner, 'owner', $now + 3600);
    bms_account_check('Failures leave the window and stop counting', $later['limited'] === false && $later['pair_failures'] === 0);

    $waited = bms_login_throttle_evaluate($owner, 'owner', $now - 1 + bms_login_throttle_delay(10, 5, bms_login_throttle_policy()));
    bms_account_check('After the delay, one more attempt is allowed', $waited['limited'] === false);

    $retry10 = bms_login_throttle_evaluate($owner, 'owner', $now)['retry_after'];
    $retry11 = bms_login_throttle_evaluate(array_merge($owner, [['username' => 'owner', 'success' => false, 'time' => $now]]), 'owner', $now)['retry_after'];
    bms_account_check('Delays grow with each further failure', $retry11 > $retry10);

    $other = bms_login_throttle_evaluate($owner, 'someone-else', $now);
    bms_account_check('Another username from the same network is not slowed by the owner failures alone', $other['limited'] === false && $other['pair_failures'] === 0 && $other['network_failures'] === 10);

    $reset = bms_login_throttle_evaluate(array_merge(bms_account_failures('owner', 6, $now - 20), [['username' => 'owner', 'success' => true, 'time' => $now - 5]], bms_account_failures('owner', 1, $now - 1)), 'owner', $now);
    bms_account_check('A successful sign-in resets that username and network', $reset['limited'] === false && $reset['pair_failures'] === 1);

    $spray = [];
    for ($i = 0; $i < 20; $i++) {
        $spray[] = ['username' => 'user' . $i, 'success' => false, 'time' => $now];
    }
    $sprayState = bms_login_throttle_evaluate($spray, 'brand-new-name', $now);
    bms_account_check('Failures across many usernames slow the whole network', $sprayState['limited'] === true && $sprayState['network_failures'] === 20);
    $sprayAfterSuccess = bms_login_throttle_evaluate(array_merge($spray, [['username' => 'attacker', 'success' => true, 'time' => $now]]), 'brand-new-name', $now);
    bms_account_check('A success never resets the network count', $sprayAfterSuccess['limited'] === true && $sprayAfterSuccess['network_failures'] === 20);
});

bms_account_section('Network key', static function (): void {
    $cases = [
        '203.0.113.7' => '203.0.113.7',
        '2001:db8:1:2:3:4:5:6' => '2001:db8:1:2::/64',
        '2001:db8:1:2:ffff:ffff:ffff:ffff' => '2001:db8:1:2::/64',
        '::ffff:198.51.100.9' => '198.51.100.9',
        '' => 'unknown',
    ];
    foreach ($cases as $address => $expected) {
        $_SERVER['REMOTE_ADDR'] = $address;
        bms_account_check('Network key for ' . ($address === '' ? '(empty)' : $address) . ' is ' . $expected, bms_login_network_address() === $expected);
    }
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::10';
    $first = bms_login_ip_hash();
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::20';
    bms_account_check('Addresses in one IPv6 /64 share one throttle', $first === bms_login_ip_hash());
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::10';
    bms_account_check('Another IPv6 /64 has its own throttle', $first !== bms_login_ip_hash());
});

bms_account_section('Session credential generation', static function (): void {
    $_SESSION = ['bms_logged_in' => true, 'bms_user_id' => 7, 'bms_session_version' => 3];
    bms_account_check('A session on the current generation is accepted', bms_session_version_matches(['id' => 7, 'session_version' => 3]));
    bms_account_check('A session from an earlier generation is rejected', !bms_session_version_matches(['id' => 7, 'session_version' => 4]));
    $_SESSION = ['bms_logged_in' => true, 'bms_user_id' => 7];
    bms_account_check('A session signed in before sessions carried a version is accepted on the first generation', bms_session_version_matches(['id' => 7, 'session_version' => 1]) && ($_SESSION['bms_session_version'] ?? null) === 1);
    $_SESSION = ['bms_logged_in' => true, 'bms_user_id' => 7];
    bms_account_check('A session without a version is rejected once credentials have changed', !bms_session_version_matches(['id' => 7, 'session_version' => 2]));
    $_SESSION = [];
    bms_bind_login_session(['id' => 9, 'session_version' => 5]);
    bms_account_check('Signing in records the account and its generation', $_SESSION === ['bms_logged_in' => true, 'bms_user_id' => 9, 'bms_session_version' => 5]);
    bms_account_check('An account without the column reads as generation 1', bms_user_session_version(['id' => 9]) === 1);
    $_SESSION = [];
});

fwrite(STDOUT, "\nAccount security test: {$results['pass']} passed, {$results['fail']} failed.\n");
if ($results['fail'] > 0) {
    foreach ($results['failed'] as $failure) {
        fwrite(STDERR, 'FAILED ' . $failure . "\n");
    }
    exit(1);
}
