<?php
/** Owned, bounded PHP HTTP fixtures for the Remote API smoke suite only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

final class BmsApiTestServer
{
    public string $base = '';
    public int $pid = 0;
    public array $diagnostics = [];
    private $process = null;
    private string $directory = '';

    /** The selector and observer are deterministic regression seams, never environment switches. */
    public static function start(string $root, ?string $router = null, array $phpOptions = [], ?callable $selectPort = null, ?callable $observe = null): self
    {
        if (!is_dir($root) || ($router !== null && !is_file($router))) {
            throw new RuntimeException('Invalid API test-server root or router.');
        }
        $server = new self();
        $overallDeadline = hrtime(true) + 12_000_000_000;
        for ($attempt = 1; $attempt <= 4 && hrtime(true) < $overallDeadline; $attempt++) {
            $ready = false;
            $retry = false;
            $reason = 'Readiness deadline exceeded';
            try {
                $port = $selectPort !== null ? $selectPort($attempt) : self::availablePort();
                if (!is_int($port) || $port < 1 || $port > 65535) {
                    throw new RuntimeException('Invalid API test-server port.');
                }
                $nonce = bin2hex(random_bytes(32));
                $server->directory = sys_get_temp_dir() . '/bms-api-server-' . $nonce;
                if (!mkdir($server->directory, 0700)) {
                    throw new RuntimeException('Cannot create API test-server launch directory.');
                }
                $path = '/__bms_test_ready_' . $nonce;
                $wrapper = $server->directory . '/router.php';
                $code = "<?php\nif (parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) === " . var_export($path, true) . ") {\n"
                    . "header('Content-Type: text/plain'); echo " . var_export($nonce . ':', true) . " . getmypid(); return;\n}\n"
                    . ($router === null ? "return false;\n" : 'require ' . var_export($router, true) . ";\n");
                if (file_put_contents($wrapper, $code) !== strlen($code)) {
                    throw new RuntimeException('Cannot write API test-server router.');
                }
                $log = $server->directory . '/server.log';
                $env = array_merge($_ENV, getenv());
                // One directly owned process, even when a developer's shell enables PHP workers.
                unset($env['PHP_CLI_SERVER_WORKERS']);
                $server->process = proc_open(array_merge([PHP_BINARY], $phpOptions, ['-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_reporting=-1', '-d', 'error_log=' . $log, '-S', '127.0.0.1:' . $port, '-t', $root, $wrapper]),
                    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
                if (!is_resource($server->process)) {
                    throw new RuntimeException('Could not launch API test-server child.');
                }
                fclose($pipes[0]);
                $state = proc_get_status($server->process);
                $server->pid = (int)$state['pid'];
                $server->base = 'http://127.0.0.1:' . $port;
                if ($observe !== null) {
                    $observe('spawn', ['attempt' => $attempt, 'pid' => $server->pid, 'directory' => $server->directory, 'path' => $path]);
                }
                $deadline = min($overallDeadline, hrtime(true) + 2_000_000_000);
                $mismatch = false;
                do {
                    $state = proc_get_status($server->process);
                    if (!$state['running']) {
                        $output = (string)@file_get_contents($log);
                        // Only this child's bind failure authorizes retry. A foreign response never does.
                        $retry = str_contains($output, 'Failed to listen on 127.0.0.1:' . $port . ' (reason: Address already in use)');
                        $reason = $retry ? 'Confirmed bind collision' : 'Child exited before readiness (exit ' . $state['exitcode'] . ')';
                        break;
                    }
                    $response = $mismatch ? null : self::probe($port, $path, min($deadline, hrtime(true) + 150_000_000));
                    if ($response !== null) {
                        $expected = $nonce . ':' . $server->pid;
                        $parts = explode("\r\n\r\n", $response, 2);
                        $owned = preg_match('~^HTTP/1\.[01] 200(?: |\r)~', $parts[0]) === 1
                            && isset($parts[1]) && hash_equals($expected, $parts[1]);
                        $mismatch = $mismatch || !$owned;
                        if ($owned && !$mismatch && proc_get_status($server->process)['running']) {
                            $ready = true;
                            break;
                        }
                    }
                    usleep(10000);
                } while (hrtime(true) < $deadline);
                if ($mismatch) {
                    $reason .= '; readiness identity mismatch';
                }
                if ($ready) {
                    return $server;
                }
            } catch (Throwable $e) {
                $reason = $e->getMessage();
                $retry = false;
            } finally {
                if (!$ready) {
                    $output = $server->stop();
                    $message = 'API test-server attempt ' . $attempt . ': ' . $reason . "\n" . $output;
                    $server->diagnostics[] = $message;
                    fwrite(STDERR, $message . "\n");
                    if ($observe !== null) {
                        $observe('cleanup', ['attempt' => $attempt, 'pid' => $server->pid, 'diagnostic' => $message]);
                    }
                }
            }
            if (!$retry) {
                throw new RuntimeException(implode("\n", $server->diagnostics));
            }
        }
        throw new RuntimeException("API test-server startup attempts exhausted.\n" . implode("\n", $server->diagnostics));
    }

    public static function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('Cannot select loopback port: ' . $error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket); // Reservation-to-bind race is handled by start(), not assumed away.
        return (int)substr((string)$address, strrpos((string)$address, ':') + 1);
    }

    /** Absolute read deadline also bounds a silent or trickling unrelated listener. */
    private static function probe(int $port, string $path, int $deadline): ?string
    {
        $remaining = max(0.001, ($deadline - hrtime(true)) / 1e9);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, $remaining);
        if ($socket === false) {
            return null;
        }
        try {
            stream_set_blocking($socket, false);
            $request = "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n";
            if (fwrite($socket, $request) !== strlen($request)) {
                return null;
            }
            $response = '';
            while (!feof($socket) && hrtime(true) < $deadline && strlen($response) < 8192) {
                $chunk = fread($socket, 8192 - strlen($response));
                if ($chunk === false) {
                    return null;
                }
                $response .= $chunk;
                if ($chunk === '') {
                    usleep(1000);
                }
            }
            return feof($socket) && $response !== '' ? $response : null;
        } finally {
            fclose($socket);
        }
    }

    /** Returns the log before removing owned files. Safe to call repeatedly. */
    public function stop(): string
    {
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process);
                $deadline = hrtime(true) + 500_000_000;
                while (proc_get_status($this->process)['running'] && hrtime(true) < $deadline) {
                    usleep(10000);
                }
                if (proc_get_status($this->process)['running']) {
                    proc_terminate($this->process, 9);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
        $log = '';
        if ($this->directory !== '') {
            $log = (string)@file_get_contents($this->directory . '/server.log');
            @unlink($this->directory . '/server.log');
            @unlink($this->directory . '/router.php');
            @rmdir($this->directory);
            $this->directory = '';
        }
        return $log;
    }
}
