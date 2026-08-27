<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

require_once __DIR__ . '/../_bonumark_stream/app/functions.php';
require_once __DIR__ . '/../_bonumark_stream/app/activitypub-inbox.php';

function bms_ap_security_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function bms_ap_security_throws(callable $callback, int $status, string $message): void
{
    try {
        $callback();
    } catch (BmsActivityPubSecurityException $e) {
        bms_ap_security_assert($e->httpStatus() === $status, $message . ' Expected HTTP ' . $status . ', received ' . $e->httpStatus() . '.');
        return;
    }
    throw new RuntimeException($message . ' No security exception was thrown.');
}

$publicResolver = static fn(string $host): array => ['93.184.216.34'];
$privateResolver = static fn(string $host): array => ['127.0.0.1'];

bms_ap_security_assert(bms_activitypub_ip_is_public('93.184.216.34'), 'A public address should pass validation.');
foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '::1', 'fe80::1'] as $privateIp) {
    bms_ap_security_assert(!bms_activitypub_ip_is_public($privateIp), 'Non-public address was accepted: ' . $privateIp);
}
bms_ap_security_throws(static fn() => bms_activitypub_validate_remote_url('http://remote.example/actor', $publicResolver), 400, 'Plain HTTP must be rejected.');
bms_ap_security_throws(static fn() => bms_activitypub_validate_remote_url('https://user:pass@remote.example/actor', $publicResolver), 400, 'URL credentials must be rejected.');
bms_ap_security_throws(static fn() => bms_activitypub_validate_remote_url('https://remote.example/actor', $privateResolver), 400, 'Private DNS destinations must be rejected.');
bms_ap_security_throws(static fn() => bms_activitypub_validate_remote_url('https://169.254.169.254/latest/meta-data'), 400, 'Metadata-service addresses must be rejected.');

$redirectCalls = 0;
$redirectTransport = static function (array $target, array $request) use (&$redirectCalls): array {
    $redirectCalls++;
    return ['status' => 302, 'headers' => ['location' => ['https://127.0.0.1/private']], 'body' => '', 'primary_ip' => '93.184.216.34'];
};
bms_ap_security_throws(
    static fn() => bms_activitypub_http_request('https://remote.example/actor', ['max_redirects' => 2], $redirectTransport, $publicResolver),
    400,
    'A public redirect to a private destination must be rejected before connection.'
);
bms_ap_security_assert($redirectCalls === 1, 'The redirect-to-private test must not make a second transport call.');

bms_ap_security_throws(static fn() => bms_activitypub_decode_json_document(str_repeat('x', 1025), 1024), 413, 'Oversized remote JSON must be rejected.');
$deep = str_repeat('{"x":', bms_activitypub_json_max_depth() + 2) . '1' . str_repeat('}', bms_activitypub_json_max_depth() + 2);
bms_ap_security_throws(static fn() => bms_activitypub_decode_json_document($deep, 65536), 400, 'Excessively deep JSON must be rejected.');
bms_ap_security_throws(static fn() => bms_activitypub_inbox_read_body(str_repeat('x', bms_activitypub_inbox_max_bytes() + 1)), 413, 'Oversized inbox bodies must be rejected.');

$keyResource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
bms_ap_security_assert($keyResource !== false, 'The test RSA key could not be generated.');
$privateKey = '';
bms_ap_security_assert(openssl_pkey_export($keyResource, $privateKey), 'The test private key could not be exported.');
$details = openssl_pkey_get_details($keyResource);
$publicKey = is_array($details) ? (string)$details['key'] : '';
bms_ap_security_assert($publicKey !== '', 'The test public key could not be exported.');

$body = '{"id":"https://remote.example/activities/1","type":"Follow","actor":"https://remote.example/actor","object":"https://local.example/activitypub/actor"}';
$now = 1770000000;
$date = gmdate('D, d M Y H:i:s', $now) . ' GMT';
$digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
$signingString = "(request-target): post /activitypub/inbox\nhost: local.example\ndate: {$date}\ndigest: {$digest}";
$rawSignature = '';
bms_ap_security_assert(openssl_sign($signingString, $rawSignature, $privateKey, OPENSSL_ALGO_SHA256), 'The test request could not be signed.');
$signature = 'keyId="https://remote.example/actor#main-key",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode($rawSignature) . '"';
$request = [
    'method' => 'POST',
    'request_target' => '/activitypub/inbox',
    'resolver' => $publicResolver,
    'body' => $body,
    'headers' => ['host' => 'local.example', 'date' => $date, 'digest' => $digest, 'signature' => $signature],
];
$verified = bms_activitypub_verify_http_signature($request, $publicKey, $now);
bms_ap_security_assert((string)$verified['key_id'] === 'https://remote.example/actor#main-key', 'A valid HTTP signature should return its key ID.');
bms_ap_security_assert((string)$verified['format'] === 'legacy', 'The legacy signature format should be identified explicitly.');

$authorizationRequest = $request;
unset($authorizationRequest['headers']['signature']);
$authorizationRequest['headers']['authorization'] = 'Signature ' . $signature;
bms_ap_security_assert(isset(bms_activitypub_verify_http_signature($authorizationRequest, $publicKey, $now)['fingerprint']), 'Authorization: Signature should be supported.');

$contentDigest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
$contentDigestSigning = "(request-target): post /activitypub/inbox\nhost: local.example\ndate: {$date}\ncontent-digest: {$contentDigest}";
$contentDigestSignature = '';
bms_ap_security_assert(openssl_sign($contentDigestSigning, $contentDigestSignature, $privateKey, OPENSSL_ALGO_SHA256), 'The Content-Digest fixture could not be signed.');
$contentDigestRequest = $request;
unset($contentDigestRequest['headers']['digest']);
$contentDigestRequest['headers']['content-digest'] = $contentDigest;
$contentDigestRequest['headers']['signature'] = 'keyId="https://remote.example/actor#main-key",algorithm="rsa-sha256",headers="(request-target) host date content-digest",signature="' . base64_encode($contentDigestSignature) . '"';
bms_ap_security_assert(isset(bms_activitypub_verify_http_signature($contentDigestRequest, $publicKey, $now)['fingerprint']), 'A signed RFC-style Content-Digest should be supported.');

$rfcContentDigest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
$rfcSignatureParams = '("@method" "@target-uri" "content-digest");created=' . $now
    . ';keyid="https://remote.example/actor#main-key";alg="rsa-v1_5-sha256"';
$rfcSigningString = '"@method": POST'
    . "\n\"@target-uri\": https://local.example/activitypub/inbox"
    . "\n\"content-digest\": " . $rfcContentDigest
    . "\n\"@signature-params\": " . $rfcSignatureParams;
$rfcRawSignature = '';
bms_ap_security_assert(openssl_sign($rfcSigningString, $rfcRawSignature, $privateKey, OPENSSL_ALGO_SHA256), 'The RFC 9421 test request could not be signed.');
$rfcRequest = [
    'method' => 'POST',
    'request_target' => '/activitypub/inbox',
    'target_uri' => 'https://local.example/activitypub/inbox',
    'resolver' => $publicResolver,
    'body' => $body,
    'headers' => [
        'host' => 'local.example',
        'content-digest' => $rfcContentDigest,
        'signature-input' => 'sig1=' . $rfcSignatureParams,
        'signature' => 'sig1=:' . base64_encode($rfcRawSignature) . ':',
    ],
];
$rfcVerified = bms_activitypub_verify_http_signature($rfcRequest, $publicKey, $now);
bms_ap_security_assert((string)$rfcVerified['format'] === 'rfc9421', 'A valid RFC 9421 signature should be verified and identified.');
bms_ap_security_assert((string)bms_activitypub_signature_metadata($rfcRequest)['key_id'] === 'https://remote.example/actor#main-key', 'RFC 9421 key metadata should be available before actor discovery.');

$rfcSplitTargetParams = '("@method" "@authority" "@path" "content-digest");created=' . $now
    . ';keyid="https://remote.example/actor#main-key"';
$rfcSplitTargetSigning = '"@method": POST'
    . "\n\"@authority\": local.example"
    . "\n\"@path\": /activitypub/inbox"
    . "\n\"content-digest\": " . $rfcContentDigest
    . "\n\"@signature-params\": " . $rfcSplitTargetParams;
$rfcSplitTargetSignature = '';
bms_ap_security_assert(openssl_sign($rfcSplitTargetSigning, $rfcSplitTargetSignature, $privateKey, OPENSSL_ALGO_SHA256), 'The split-target RFC 9421 fixture could not be signed.');
$rfcSplitTargetRequest = $rfcRequest;
$rfcSplitTargetRequest['headers']['signature-input'] = 'sig2=' . $rfcSplitTargetParams;
$rfcSplitTargetRequest['headers']['signature'] = 'sig2=:' . base64_encode($rfcSplitTargetSignature) . ':';
bms_ap_security_assert(isset(bms_activitypub_verify_http_signature($rfcSplitTargetRequest, $publicKey, $now)['fingerprint']), 'RFC 9421 authority and path target coverage should be accepted.');

$rfcBadDigest = $rfcRequest;
$rfcBadDigest['headers']['content-digest'] = 'sha-256=:' . base64_encode(hash('sha256', 'tampered', true)) . ':';
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($rfcBadDigest, $publicKey, $now), 400, 'An invalid RFC 9421 Content-Digest must be rejected.');
$rfcMissingCoverage = $rfcRequest;
$rfcMissingCoverage['headers']['signature-input'] = 'sig1=("@method" "@target-uri");created=' . $now . ';keyid="https://remote.example/actor#main-key"';
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($rfcMissingCoverage, $publicKey, $now), 401, 'RFC 9421 must cover the request-body digest.');
$rfcExpired = $rfcRequest;
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($rfcExpired, $publicKey, $now + bms_activitypub_signature_window_seconds() + 1), 401, 'An expired RFC 9421 signature must be rejected.');
$rfcAmbiguous = $rfcRequest;
$rfcAmbiguous['headers']['signature'] .= ', sig2=:' . base64_encode($rfcRawSignature) . ':';
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($rfcAmbiguous, $publicKey, $now), 401, 'Multiple RFC 9421 signatures must not create selection ambiguity.');
$rfcMismatchedLabel = $rfcRequest;
$rfcMismatchedLabel['headers']['signature'] = 'other=:' . base64_encode($rfcRawSignature) . ':';
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($rfcMismatchedLabel, $publicKey, $now), 401, 'RFC 9421 Signature and Signature-Input labels must match.');
$rfcWrongTarget = $rfcRequest;
$rfcWrongTarget['target_uri'] = 'https://local.example/activitypub/other';
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($rfcWrongTarget, $publicKey, $now), 401, 'RFC 9421 target URI must match the request target.');

$badDigest = $request;
$badDigest['headers']['digest'] = 'SHA-256=' . base64_encode(hash('sha256', 'tampered', true));
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($badDigest, $publicKey, $now), 400, 'An invalid digest must be rejected.');
$malformed = $request;
$malformed['headers']['signature'] = 'not-a-signature';
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($malformed, $publicKey, $now), 401, 'A malformed signature must be rejected.');
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($request, $publicKey, $now + bms_activitypub_signature_window_seconds() + 1), 401, 'An expired signature must be rejected.');
$badDate = $request;
$badDate['headers']['date'] = 'tomorrow';
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($badDate, $publicKey, $now), 401, 'A malformed HTTP Date must be rejected.');

$otherKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$otherDetails = $otherKey !== false ? openssl_pkey_get_details($otherKey) : false;
$otherPublic = is_array($otherDetails) ? (string)$otherDetails['key'] : '';
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($request, $otherPublic, $now), 401, 'A signature made by another key must be rejected.');
bms_ap_security_throws(static fn() => bms_activitypub_verify_http_signature($rfcRequest, $otherPublic, $now), 401, 'An RFC 9421 signature made by another key must be rejected.');

$actorDocument = [
    'id' => 'https://remote.example/actor',
    'type' => 'Person',
    'inbox' => 'https://remote.example/inbox',
    'publicKey' => ['id' => 'https://remote.example/actor#main-key', 'owner' => 'https://remote.example/actor', 'publicKeyPem' => $publicKey],
];
$actor = bms_activitypub_validate_remote_actor_document($actorDocument, 'https://remote.example/actor', $publicResolver);
bms_ap_security_assert((string)$actor['key_owner_uri'] === (string)$actor['actor_uri'], 'The actor and key owner should match.');
$spoofedOwner = $actorDocument;
$spoofedOwner['publicKey']['owner'] = 'https://attacker.example/actor';
bms_ap_security_throws(static fn() => bms_activitypub_validate_remote_actor_document($spoofedOwner, 'https://remote.example/actor', $publicResolver), 502, 'A key-owner mismatch must be rejected.');
$spoofedId = $actorDocument;
$spoofedId['id'] = 'https://attacker.example/actor';
bms_ap_security_throws(static fn() => bms_activitypub_validate_remote_actor_document($spoofedId, 'https://remote.example/actor', $publicResolver), 502, 'A mismatched actor document ID must be rejected.');

fwrite(STDOUT, "ActivityPub Stage 3 security test passed.\n");
