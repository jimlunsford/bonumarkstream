<?php
/**
 * ActivityPub inbox lookup limits: database regression test.
 *
 * An inbox request names its actor before anything proves the sender holds
 * that actor's key. This test sends inbox requests to a disposable install
 * with ActivityPub enabled and counts every remote actor fetch they cause.
 * The fetcher and DNS resolver are local fixtures: nothing leaves the
 * machine and no real federation server is contacted.
 *
 * Covers:
 * - requests that fail checks needing no network (signature, digest, host,
 *   and time) cause no fetch;
 * - a burst of requests naming distinct new actors causes at most the
 *   allowed number of fetches per client network (IPv6 counted per /64) and
 *   per actor host;
 * - an actor whose fetch failed is not fetched again within the failure
 *   window, from any network;
 * - refreshes of cached actors caused by requests that do not authenticate
 *   are limited the same way;
 * - correctly signed requests from cached actors still succeed, with no
 *   fetch, from an exhausted network and for an exhausted host;
 * - lookups whose request authenticates do not count against the limits.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_aplimits_ci_* tables are created
 * and removed.
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
        fwrite(STDERR, $required . " is required.\n");
        exit(1);
    }
}

$source = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/bms-aplimits-' . bin2hex(random_bytes(6));
$prefix = 'bms_aplimits_ci_' . bin2hex(random_bytes(4)) . '_';
$checks = 0;
$failures = [];
$installed = false;

function lim_expect(bool $condition, string $label): void
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

function lim_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($path);
}

/** @return array{private:string,public:string} */
function lim_key_pair(): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $private = '';
    if ($key === false || !openssl_pkey_export($key, $private)) {
        throw new RuntimeException('A fixture RSA key could not be generated.');
    }
    $details = openssl_pkey_get_details($key);
    return ['private' => $private, 'public' => is_array($details) ? (string)$details['key'] : ''];
}

function lim_actor_document(string $actorUri, string $publicKey): array
{
    return [
        'id' => $actorUri, 'type' => 'Person', 'preferredUsername' => basename($actorUri), 'name' => 'Fixture actor',
        'inbox' => $actorUri . '/inbox',
        'publicKey' => ['id' => $actorUri . '#main-key', 'owner' => $actorUri, 'publicKeyPem' => $publicKey],
    ];
}

/**
 * A legacy HTTP Signature request. With no private key the signature is
 * well formed but made of random bytes, so it never verifies.
 *
 * @param array<string,string|null> $headerOverrides A null value removes the header.
 */
function lim_legacy_request(array $activity, string $keyId, ?string $privateKey, int $now, string $remoteAddress, array $headerOverrides = [], string $signedHeaders = '(request-target) host date digest'): array
{
    $body = (string)json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = [
        'host' => 'example.test',
        'date' => gmdate('D, d M Y H:i:s', $now) . ' GMT',
        'digest' => 'SHA-256=' . base64_encode(hash('sha256', $body, true)),
        'content-type' => 'application/activity+json',
        'content-length' => (string)strlen($body),
    ];
    foreach ($headerOverrides as $name => $value) {
        if ($value === null) {
            unset($headers[$name]);
        } else {
            $headers[$name] = $value;
        }
    }
    $lines = [];
    foreach (explode(' ', $signedHeaders) as $name) {
        $lines[] = $name === '(request-target)' ? '(request-target): post /activitypub/inbox' : $name . ': ' . (string)($headers[$name] ?? '');
    }
    $signature = random_bytes(256);
    if ($privateKey !== null && !openssl_sign(implode("\n", $lines), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('A fixture request could not be signed.');
    }
    if (!array_key_exists('signature', $headerOverrides)) {
        $headers['signature'] = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="' . $signedHeaders . '",signature="' . base64_encode($signature) . '"';
    } elseif ($headerOverrides['signature'] === null) {
        unset($headers['signature']);
    }
    return ['method' => 'POST', 'request_target' => '/activitypub/inbox', 'body' => $body, 'headers' => $headers, 'remote_address' => $remoteAddress];
}

/** An RFC 9421 request. With no private key the signature never verifies. */
function lim_rfc9421_request(array $activity, string $keyId, ?string $privateKey, int $created, string $remoteAddress, ?string $contentDigest = null): array
{
    $body = (string)json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $targetUri = 'https://example.test/activitypub/inbox';
    $contentDigest ??= 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
    $params = '("@method" "@target-uri" "content-digest");created=' . $created . ';keyid="' . $keyId . '";alg="rsa-v1_5-sha256"';
    $base = '"@method": POST' . "\n\"@target-uri\": " . $targetUri . "\n\"content-digest\": " . $contentDigest . "\n\"@signature-params\": " . $params;
    $signature = random_bytes(256);
    if ($privateKey !== null && !openssl_sign($base, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('A fixture RFC 9421 request could not be signed.');
    }
    return [
        'method' => 'POST', 'request_target' => '/activitypub/inbox', 'target_uri' => $targetUri, 'body' => $body, 'remote_address' => $remoteAddress,
        'headers' => [
            'host' => 'example.test', 'content-digest' => $contentDigest, 'content-type' => 'application/activity+json',
            'content-length' => (string)strlen($body), 'signature-input' => 'sig1=' . $params, 'signature' => 'sig1=:' . base64_encode($signature) . ':',
        ],
    ];
}

function lim_like(string $actorUri, string $suffix): array
{
    return ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $actorUri . '/activities/' . $suffix, 'type' => 'Like', 'actor' => $actorUri, 'object' => 'https://example.test/activitypub/objects/999'];
}

try {
    // A disposable install with ActivityPub enabled.
    mkdir($temporary, 0700);
    $root = $temporary . '/site';
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file) use ($source): bool {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1));
        return !$file->isLink() && !in_array($relative, ['.git', '_bonumark_stream/config.php', '_bonumark_stream/installed.lock', '_bonumark_stream/setup-key.php', '_bonumark_stream/tmp', '_bonumark_stream/backups', 'media'], true);
    }), RecursiveIteratorIterator::SELF_FIRST);
    mkdir($root, 0700);
    foreach ($iterator as $file) {
        $target = $root . substr($file->getPathname(), strlen($source));
        $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
    }
    $config = [
        'site_name' => 'Inbox Limits Fixture', 'base_url' => 'https://example.test', 'base_path' => '', 'public_path' => '',
        'security_salt' => bin2hex(random_bytes(24)), 'timezone' => 'UTC',
        'database' => ['host' => (string)getenv('BMS_DB_HOST'), 'name' => (string)getenv('BMS_DB_NAME'), 'user' => (string)getenv('BMS_DB_USER'),
            'password' => (string)getenv('BMS_DB_PASS'), 'charset' => 'utf8mb4', 'prefix' => $prefix],
    ];
    file_put_contents($root . '/_bonumark_stream/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    touch($root . '/_bonumark_stream/installed.lock');
    require_once $root . '/_bonumark_stream/app/database.php';
    require_once $root . '/_bonumark_stream/app/activitypub-inbox.php';
    $pdo = bms_db();
    bms_install_schema($pdo, $prefix);
    $installed = true;
    bms_db_insert_initial_data(
        ['site_name' => 'Inbox Limits Fixture', 'site_tagline' => '', 'timezone' => 'UTC', 'base_url' => 'https://example.test', 'base_path' => '', 'public_path' => ''],
        ['username' => 'owner', 'display_name' => 'Owner', 'email' => 'owner@example.test', 'password' => bin2hex(random_bytes(12))]
    );
    $pdo->prepare('INSERT INTO ' . bms_table('settings') . " (setting_key, setting_value, updated_at) VALUES ('activitypub_enabled', '1', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute();
    bms_activitypub_create_signing_key();
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    // Local fixtures: a fetcher that records every actor fetch and serves
    // only the documents below, and a resolver that answers every host
    // with one public address without asking DNS.
    $fetches = [];
    $documents = [];
    $fetcher = static function (string $url) use (&$fetches, &$documents): array {
        $fetches[] = $url;
        if (isset($documents[$url])) {
            return ['document' => $documents[$url], 'url' => $url];
        }
        throw new BmsActivityPubSecurityException('The remote actor document was not available.', 404);
    };
    $resolver = static fn(string $host): array => ['93.184.216.34'];
    $fetchesOf = static function (string $needle) use (&$fetches): int {
        return count(array_filter($fetches, static fn(string $url): bool => str_contains($url, $needle)));
    };
    $now = time();
    $receive = static function (array $request) use ($fetcher, $resolver, &$now): array {
        try {
            $result = bms_activitypub_receive_inbox($request, $fetcher, $resolver, $now);
            return ['status' => 202, 'result' => (string)($result['result_code'] ?? '')];
        } catch (BmsActivityPubSecurityException $e) {
            return ['status' => $e->httpStatus(), 'result' => $e->getMessage()];
        }
    };
    $statuses = static fn(array $responses): array => array_count_values(array_map(static fn(array $response): int => $response['status'], $responses));

    $keys = lim_key_pair();
    $known = 'https://popular.example/users/known';
    $steady = 'https://steady.example/users/steady';
    foreach ([$known, $steady] as $actorUri) {
        $documents[$actorUri] = lim_actor_document($actorUri, $keys['public']);
    }

    // Cache two known actors through ordinary authenticated requests.
    $first = $receive(lim_legacy_request(lim_like($known, 'cache-1'), $known . '#main-key', $keys['private'], $now, '203.0.113.1'));
    $second = $receive(lim_legacy_request(lim_like($steady, 'cache-1'), $steady . '#main-key', $keys['private'], $now, '203.0.113.1'));
    lim_expect($first['status'] === 202 && $second['status'] === 202 && count($fetches) === 2 && is_array(bms_activitypub_cached_remote_actor($known)), 'A new actor is fetched once and cached after its request authenticates');

    // 1. Checks that need no network run before any fetch.
    $newActor = 'https://cheap.example/users/new';
    $documents[$newActor] = lim_actor_document($newActor, $keys['public']);
    $newKeyId = $newActor . '#main-key';
    $cheap = [
        'no Signature header' => [lim_legacy_request(lim_like($newActor, 'c1'), $newKeyId, $keys['private'], $now, '203.0.113.2', ['signature' => null]), 401],
        'a body digest that does not match' => [lim_legacy_request(lim_like($newActor, 'c2'), $newKeyId, $keys['private'], $now, '203.0.113.2', ['digest' => 'SHA-256=' . base64_encode(hash('sha256', 'other body', true))]), 400],
        'a Host of another site' => [lim_legacy_request(lim_like($newActor, 'c3'), $newKeyId, $keys['private'], $now, '203.0.113.2', ['host' => 'other.example']), 401],
        'a Date an hour old' => [lim_legacy_request(lim_like($newActor, 'c4'), $newKeyId, $keys['private'], $now, '203.0.113.2', ['date' => gmdate('D, d M Y H:i:s', $now - 3600) . ' GMT']), 401],
        'a malformed Date' => [lim_legacy_request(lim_like($newActor, 'c5'), $newKeyId, $keys['private'], $now, '203.0.113.2', ['date' => 'tomorrow']), 401],
        'a signature that does not cover the digest' => [lim_legacy_request(lim_like($newActor, 'c6'), $newKeyId, $keys['private'], $now, '203.0.113.2', [], '(request-target) host date'), 401],
        'an RFC 9421 Content-Digest that does not match' => [lim_rfc9421_request(lim_like($newActor, 'c7'), $newKeyId, $keys['private'], $now, '203.0.113.2', 'sha-256=:' . base64_encode(hash('sha256', 'other body', true)) . ':'), 400],
        'an expired RFC 9421 signature' => [lim_rfc9421_request(lim_like($newActor, 'c8'), $newKeyId, $keys['private'], $now - 3600, '203.0.113.2'), 401],
    ];
    foreach ($cheap as $label => [$request, $status]) {
        $before = count($fetches);
        $response = $receive($request);
        lim_expect($response['status'] === $status && count($fetches) === $before, 'A request with ' . $label . ' is rejected with HTTP ' . $status . ' before any fetch');
    }

    // 2. A burst from one network naming distinct new actors.
    $policy = function_exists('bms_activitypub_inbox_discovery_policy') ? bms_activitypub_inbox_discovery_policy() : ['network_failures' => 10, 'host_failures' => 30];
    $burst = [];
    for ($index = 1; $index <= 25; $index++) {
        $actor = 'https://burst-' . $index . '.example/users/x';
        $burst[] = $receive(lim_legacy_request(lim_like($actor, 'b'), $actor . '#main-key', null, $now, '198.51.100.20'));
    }
    lim_expect($fetchesOf('://burst-') === $policy['network_failures'], 'A burst of 25 unauthenticated requests from one network fetches only ' . $policy['network_failures'] . ' actors (fetched ' . $fetchesOf('://burst-') . ')');
    lim_expect($statuses($burst) === [404 => 10, 429 => 15], 'The rest of the burst is refused with HTTP 429');
    if (function_exists('bms_activitypub_inbox_discovery_hash')) {
        $rows = $pdo->prepare('SELECT COUNT(*) FROM ' . bms_table('api_rate_limit_attempts') . ' WHERE identifier_hash = :identifier_hash AND route = :route');
        $rows->execute(['identifier_hash' => bms_activitypub_inbox_discovery_hash('network', '198.51.100.20'), 'route' => 'activitypub_discovery_network']);
        lim_expect((int)$rows->fetchColumn() === 10, 'Refused lookups leave no records behind');
    } else {
        lim_expect(false, 'Refused lookups leave no records behind');
    }

    $sameNetwork = [];
    for ($index = 1; $index <= 14; $index++) {
        $actor = 'https://v6-' . $index . '.example/users/x';
        $sameNetwork[] = $receive(lim_legacy_request(lim_like($actor, 'b'), $actor . '#main-key', null, $now, '2001:db8:45:1::' . dechex($index)));
    }
    lim_expect($fetchesOf('://v6-') === $policy['network_failures'], 'Addresses in one IPv6 /64 count as one network (fetched ' . $fetchesOf('://v6-') . ')');

    // 3. A burst from many networks naming new actors on one host.
    $hostBurst = [];
    for ($index = 1; $index <= 40; $index++) {
        $actor = 'https://popular.example/users/new-' . $index;
        $documents[$actor] = lim_actor_document($actor, $keys['public']);
        $hostBurst[] = $receive(lim_legacy_request(lim_like($actor, 'h'), $actor . '#main-key', null, $now, '192.0.2.' . $index));
    }
    lim_expect($fetchesOf('popular.example/users/new-') === $policy['host_failures'], 'Unauthenticated requests from 40 networks fetch only ' . $policy['host_failures'] . ' actors from one host (fetched ' . $fetchesOf('popular.example/users/new-') . ')');
    lim_expect($statuses($hostBurst) === [401 => 30, 429 => 10], 'Requests past the host limit are refused with HTTP 429');

    // 4. A failed actor is not fetched again within the failure window.
    $gone = 'https://gone.example/users/x';
    $repeat = [];
    foreach (['203.0.113.50', '203.0.113.50', '203.0.113.50', '203.0.113.51'] as $index => $network) {
        $repeat[] = $receive(lim_legacy_request(lim_like($gone, 'g' . $index), $gone . '#main-key', null, $now, $network));
    }
    lim_expect($fetchesOf('gone.example') === 1, 'An actor whose fetch failed is fetched once, not on every repeat (fetched ' . $fetchesOf('gone.example') . ')');
    lim_expect(array_map(static fn(array $response): int => $response['status'], $repeat) === [404, 429, 429, 429], 'Repeats of the failed actor are refused from any network');

    // 5. Refreshes of a cached actor caused by bad signatures are limited.
    $refreshes = [];
    for ($index = 1; $index <= 15; $index++) {
        $refreshes[] = $receive(lim_legacy_request(lim_like($steady, 'r' . $index), $steady . '#main-key', null, $now, '203.0.113.60'));
    }
    lim_expect($fetchesOf('steady.example') - 1 === $policy['network_failures'], 'Bad signatures for a cached actor refresh it at most ' . $policy['network_failures'] . ' times (refreshed ' . ($fetchesOf('steady.example') - 1) . ')');
    $rotated = [];
    for ($index = 1; $index <= 12; $index++) {
        $rotated[] = $receive(lim_legacy_request(lim_like($steady, 'k' . $index), $steady . '#other-key', null, $now, '203.0.113.61'));
    }
    lim_expect($fetchesOf('steady.example') - 1 === 2 * $policy['network_failures'], 'Unknown key IDs for a cached actor refresh it at most ' . $policy['network_failures'] . ' times');

    // 6. Correctly signed requests from cached actors are never limited.
    $fetchCount = count($fetches);
    $legacy = $receive(lim_legacy_request(lim_like($known, 'ok-1'), $known . '#main-key', $keys['private'], $now, '198.51.100.20'));
    $rfc = $receive(lim_rfc9421_request(lim_like($known, 'ok-2'), $known . '#main-key', $keys['private'], $now, '198.51.100.20'));
    $refreshed = $receive(lim_legacy_request(lim_like($steady, 'ok-3'), $steady . '#main-key', $keys['private'], $now, '203.0.113.60'));
    lim_expect($legacy['status'] === 202 && $legacy['result'] === 'like_unknown_target', 'A correctly signed request from a cached actor succeeds from an exhausted network and for an exhausted host');
    lim_expect($rfc['status'] === 202 && $refreshed['status'] === 202, 'RFC 9421 requests and requests from an actor with limited refreshes also succeed');
    lim_expect(count($fetches) === $fetchCount, 'Requests from cached actors cause no fetch');

    // 7. Lookups whose request authenticates do not count.
    $fresh = [];
    for ($index = 1; $index <= 12; $index++) {
        $actor = 'https://fresh.example/users/ok-' . $index;
        $documents[$actor] = lim_actor_document($actor, $keys['public']);
        $fresh[] = $receive(lim_legacy_request(lim_like($actor, 'f'), $actor . '#main-key', $keys['private'], $now, '203.0.113.70'));
    }
    $unknownAfter = $receive(lim_legacy_request(lim_like('https://fresh.example/users/missing', 'f'), 'https://fresh.example/users/missing#main-key', null, $now, '203.0.113.70'));
    lim_expect($statuses($fresh) === [202 => 12] && $fetchesOf('fresh.example/users/ok-') === 12, 'Twelve new actors whose requests authenticate are all accepted from one network');
    lim_expect($unknownAfter['status'] === 404 && $fetchesOf('fresh.example/users/missing') === 1, 'Authenticated lookups leave the network limit unused');

    fwrite(STDOUT, "\nActivityPub inbox limits test: " . ($checks - count($failures)) . ' passed, ' . count($failures) . " failed.\n");
} catch (Throwable $e) {
    lim_expect(false, 'The inbox limits test ran without error (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    if ($installed) {
        try {
            foreach (bms_db()->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                bms_db()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$table) . '`');
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'Could not remove test tables: ' . $e->getMessage() . "\n");
        }
    }
    lim_remove_tree($temporary);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED ' . $failure . "\n");
    }
    exit(1);
}
