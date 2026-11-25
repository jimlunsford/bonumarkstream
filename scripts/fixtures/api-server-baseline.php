<?php
/** Work 39 negative control: baseline 358871f launcher and HTTP reader.
 * Instrumentation: force selected port; replace application assertions with a callback;
 * capture the log before baseline finally deletes it. No readiness/process logic changed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
function bms_server_baseline(string $root, int $forcedPort, callable $assertion): void
{
    $port = $forcedPort;
    $log = $root . '/activitypub-route-server.log';
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
    $process = proc_open($command, $descriptors, $pipes, $root, array_merge($_ENV, getenv()));
    if (!is_resource($process)) {
        throw new RuntimeException('The ActivityPub route test server could not be started.');
    }
    fclose($pipes[0]);
    $base = 'http://127.0.0.1:' . $port . '/index.php?__bonumark_route=';
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $probe = bms_api_smoke_http_request($base . 'activitypub_actor', 'GET', ['Accept: application/activity+json']);
                if ((int)$probe['status'] > 0) {
                    $ready = true;
                    break;
                }
            } catch (Throwable $e) {
            }
            usleep(100000);
        }
        if (!$ready) {
            $serverLog = is_file($log) ? trim((string)file_get_contents($log)) : '';
            throw new RuntimeException('The ActivityPub route test server did not become ready.' . ($serverLog !== '' ? ' ' . $serverLog : ''));
        }

        $assertion($base);
    } finally {
        proc_terminate($process);
        proc_close($process);
        $GLOBALS['bms_server_baseline_log'] = (string)file_get_contents($log);
        fwrite(STDERR, $GLOBALS['bms_server_baseline_log']);
        @unlink($log);
    }
}
function bms_api_smoke_http_request(string $url, string $method = 'GET', array $headers = [], string $requestBody = ''): array
{
    $parts = parse_url($url);
    $host = is_array($parts) ? (string)($parts['host'] ?? '') : '';
    $port = is_array($parts) ? (int)($parts['port'] ?? 80) : 0;
    $target = is_array($parts) ? (string)($parts['path'] ?? '/') : '/';
    if (is_array($parts) && isset($parts['query'])) {
        $target .= '?' . (string)$parts['query'];
    }
    $errorNumber = 0;
    $errorMessage = '';
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errorNumber, $errorMessage, 2);
    if (!is_resource($socket)) {
        throw new RuntimeException('The route smoke request failed: ' . $errorMessage);
    }
    stream_set_timeout($socket, 5);
    $requestHeaders = array_merge(['Host: ' . $host . ':' . $port, 'Connection: close', 'Content-Length: ' . strlen($requestBody)], $headers);
    $request = strtoupper($method) . ' ' . $target . " HTTP/1.1\r\n" . implode("\r\n", $requestHeaders) . "\r\n\r\n";
    fwrite($socket, $request . $requestBody);
    $raw = stream_get_contents($socket);
    fclose($socket);
    if (!is_string($raw) || !str_contains($raw, "\r\n\r\n")) {
        throw new RuntimeException('The route smoke server returned an invalid HTTP response.');
    }
    [$headerText, $body] = explode("\r\n\r\n", $raw, 2);
    preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $headerText, $statusMatch);
    $status = (int)($statusMatch[1] ?? 0);
    $responseHeaders = [];
    foreach (preg_split('/\r?\n/', $headerText) ?: [] as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
}
