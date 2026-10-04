<?php
/** Real HTTP media failure regression, invoked only by the disposable DB suite. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

function bms_api_smoke_media_error_sanitization(): void
{
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    };
    $root = (string)$GLOBALS['bms_api_smoke_temp_root'];
    $data = bms_api_create_token('Media boundary regression', ['status:read', 'media:upload', 'stream:draft'], null, 1);
    $headers = ['Authorization: Bearer ' . $data['plain_token'], 'Content-Type: application/json'];
    $image = imagecreatetruecolor(1280, 32);
    imagefilledrectangle($image, 0, 0, 1279, 31, imagecolorallocate($image, 30, 90, 150));
    ob_start();
    imagepng($image);
    $png = (string)ob_get_clean();
    imagedestroy($image);
    $upload = ['filename' => 'boundary-regression.png', 'content_base64' => base64_encode($png), 'alt_text' => 'A blue test image'];
    $files = static function () use ($root): array {
        $result = [];
        if (!is_dir($root . '/media')) { return []; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/media', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) { $result[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
        }
        ksort($result);
        return $result;
    };
    $beforeFiles = $files();
    $beforeMedia = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('media'))->fetchColumn();
    $beforePosts = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('posts'))->fetchColumn();
    $port = random_int(45100, 45999);
    $log = $root . '/media-error-server.log';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge($_ENV, getenv()));
    $assert(is_resource($process), 'Could not start the media HTTP regression server.');
    fclose($pipes[0]);
    $base = 'http://127.0.0.1:' . $port;
    $request = static function (string $path, array $payload, ?array $customHeaders = null) use ($base, $headers): array {
        return bms_api_smoke_http_request($base . $path, 'POST', $customHeaders ?? $headers, (string)json_encode($payload));
    };
    $expect = static function (array $response, int $status, string $code) use ($assert): array {
        $json = json_decode($response['body'], true);
        $assert($response['status'] === $status && ($json['ok'] ?? null) === false && ($json['error']['code'] ?? '') === $code,
            'Unexpected media error status/code; response intentionally omitted.');
        return $json;
    };
    $marker = 'synthetic_media_private_marker';
    $password = (string)getenv('BMS_DB_PASS');
    $failureColumnAdded = false;
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                bms_api_smoke_http_request($base . '/api/v1/status.php');
                $ready = true;
                break;
            } catch (Throwable $e) { usleep(100000); }
        }
        $assert($ready, 'Media HTTP regression server did not become ready.');
        // Ordinary table ALTER privileges suffice on MySQL with binary logging enabled.
        // The missing mandatory value produces a real PDO error naming this synthetic column.
        bms_db()->exec('ALTER TABLE ' . bms_table('media') . ' ADD COLUMN ' . $marker . ' INT NOT NULL');
        $failureColumnAdded = true;
        foreach (['standalone', 'embedded'] as $surface) {
            $response = $surface === 'standalone'
                ? $request('/api/v1/media.php', $upload)
                : $request('/api/v1/stream/posts.php', ['content' => 'Media failure draft', 'status' => 'draft', 'media_upload' => $upload],
                    array_merge($headers, ['Idempotency-Key: media-boundary-failure']));
            $assert($files() === $beforeFiles, 'Failed upload left an original or derivative file.');
            $wire = $response['body'] . json_encode($response['headers']);
            foreach ([$marker, 'SQLSTATE', 'INSERT INTO', bms_table('media'), 'private_media', $password] as $secret) {
                if ($secret !== '') {
                    $assert(!str_contains($wire, $secret), $surface . ' HTTP response exposed private database diagnostics.');
                }
            }
            $json = $expect($response, 500, 'server_error');
            $assert($json === ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The API request could not be completed.']],
                'Infrastructure error did not use the exact sanitized envelope.');
        }
        $assert((int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('media'))->fetchColumn() === $beforeMedia,
            'Failed media persistence left a media record.');
        $assert((int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('posts'))->fetchColumn() === $beforePosts,
            'Embedded media failure created a post.');
        $assert((int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('api_idempotency_keys') . " WHERE idempotency_key = 'media-boundary-failure'")->fetchColumn() === 0,
            'Embedded pre-creation failure retained its own reservation.');
        $audit = json_encode(bms_db()->query('SELECT message FROM ' . bms_table('api_audit_log'))->fetchAll());
        $assert(!str_contains($audit, $marker) && !str_contains($audit, 'INSERT INTO'), 'API audit messages contain raw diagnostics.');
        bms_db()->exec('ALTER TABLE ' . bms_table('media') . ' DROP COLUMN ' . $marker);
        $failureColumnAdded = false;

        $invalid = array_replace($upload, ['filename' => 'bad.exe']);
        $json = $expect($request('/api/v1/media.php', $invalid), 422, 'media_upload_invalid');
        $assert(str_starts_with($json['error']['message'], 'Unsupported media type.'), 'Safe extension validation message was lost.');
        $expect($request('/api/v1/media.php', array_replace($upload, ['content_base64' => base64_encode('not an image')])), 422, 'media_upload_invalid');
        $json = $expect($request('/api/v1/media.php', array_replace($upload, ['filename' => 'plain.txt', 'content_base64' => base64_encode('ordinary text')])), 422, 'media_upload_invalid');
        $assert($json['error']['message'] === 'This upload must be an image file.', 'Image-only validation was lost.');
        $json = $expect($request('/api/v1/media.php', array_replace($upload, ['alt_text' => str_repeat('a', 256)])), 422, 'alt_text_invalid');
        $assert(str_contains($json['error']['message'], '255 characters'), 'Safe alt-text length error was lost.');
        // Multipart can carry invalid UTF-8, whereas JSON correctly rejects it at parsing.
        $boundary = 'bms-media-boundary';
        $multipart = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"alt_text\"\r\n\r\n" . "\xFF"
            . "\r\n--" . $boundary . "\r\nContent-Disposition: form-data; name=\"media_file\"; filename=\"test.png\"\r\nContent-Type: image/png\r\n\r\n"
            . $png . "\r\n--" . $boundary . "--\r\n";
        $expect(bms_api_smoke_http_request($base . '/api/v1/media.php', 'POST',
            [$headers[0], 'Content-Type: multipart/form-data; boundary=' . $boundary], $multipart), 422, 'alt_text_invalid');
        $expect($request('/api/v1/media/import.php', ['image_url' => 'http://127.0.0.1/private.png']), 422, 'unsafe_media_import_url');
        $expect($request('/api/v1/stream/posts.php', ['content' => 'Unsafe import', 'status' => 'draft', 'media_import_url' => 'http://127.0.0.1/private.png']), 422, 'unsafe_media_import_url');
        // Auth and feature errors must retain precedence over media validation.
        $expect($request('/api/v1/media.php', $invalid, ['Content-Type: application/json']), 401, 'missing_bearer_token');
        $expect($request('/api/v1/media.php', $invalid, ['Content-Type: application/json', 'Authorization: Bearer invalid']), 401, 'invalid_bearer_token');
        $readToken = bms_api_create_token('Read-only media regression', ['status:read'], null, 1);
        $expect($request('/api/v1/media.php', $invalid, ['Content-Type: application/json', 'Authorization: Bearer ' . $readToken['plain_token']]), 403, 'missing_scope');
        bms_api_smoke_set_setting('remote_media_upload_enabled', '0');
        $expect($request('/api/v1/media.php', $invalid), 403, 'remote_media_upload_disabled');
        bms_api_smoke_set_setting('remote_media_upload_enabled', '1');
        bms_api_smoke_set_setting('remote_posting_enabled', '0');
        $expect($request('/api/v1/media.php', $invalid), 403, 'remote_posting_disabled');
        bms_api_smoke_set_setting('remote_posting_enabled', '1');

        $success = $request('/api/v1/media.php', $upload);
        $json = json_decode($success['body'], true);
        $assert($success['status'] === 201 && (int)($json['media']['media_id'] ?? 0) > 0, 'Valid media upload failed after recovery.');
        $media = bms_media_find((int)$json['media']['media_id']);
        $assert(count(json_decode((string)$media['image_variants_json'], true) ?: []) > 0, 'Test image did not exercise derivative generation.');
        bms_api_smoke_media_import_classification($root, $assert);
        $privateLog = (string)file_get_contents($log);
        $assert(str_contains($privateLog, '[api-media-upload]'), 'Internal upload failure was not logged privately.');
        $assert($password === '' || !str_contains($privateLog, $password), 'Private diagnostic log duplicated the database password.');
        // Token rate accounting still applies to the actual media endpoint.
        bms_api_smoke_set_setting('remote_posting_rate_limit_per_minute', '5');
        $expect($request('/api/v1/media.php', $upload), 429, 'rate_limited');
    } finally {
        if ($failureColumnAdded) { bms_db()->exec('ALTER TABLE ' . bms_table('media') . ' DROP COLUMN ' . $marker); }
        proc_terminate($process);
        proc_close($process);
        @unlink($log);
    }
}

/** Load unchanged function bodies under a test namespace with deterministic dependencies.
 * No production hook, network request, or relaxed production SSRF rule is introduced.
 */
function bms_api_smoke_media_import_classification(string $root, callable $assert): void
{
    $source = static function (string $name): string {
        $reflection = new ReflectionFunction($name);
        return implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1));
    };
    $fixture = $root . '/media-import-classification.php';
    $code = <<<'FIXTURE'
<?php
namespace BmsMediaImportRegression;
use \BMS_Api_Exception;
use \BMS_Media_Validation_Exception;
use \RuntimeException;
use \Throwable;
function bms_api_remote_media_upload_enabled(): bool { return true; }
function bms_api_remote_media_import_url(array $payload): string { return 'https://example.test/image.png'; }
function bms_import_download_remote_image(string $url): array { return \BmsMediaDownloadRegression\bms_import_download_remote_image($url); }
function bms_api_create_remote_media(array $payload, array $token, array $file): array { throw $GLOBALS['media_fixture_upload_error']; }
function bms_log_sanitized_exception(string $context, Throwable $e): void { $GLOBALS['media_fixture_log'][] = $context; }
class Response extends RuntimeException {
    public function __construct(public array $payload, public int $status) { parent::__construct('Captured response'); }
}
function bms_api_json_response(array $payload, int $status = 200): never { throw new Response($payload, $status); }
FIXTURE;
    $code .= "\n" . $source('bms_api_import_remote_media') . "\n" . $source('bms_api_log_media_failure') . "\n" . $source('bms_api_error_response');
    $code .= <<<'FIXTURE'
namespace BmsMediaDownloadRegression;
use \BMS_Media_Validation_Exception;
use \RuntimeException;
use \Throwable;
function bms_import_remote_image_url_is_safe(string $url): bool { return !str_contains($url, 'unsafe'); }
function bms_import_remote_image_max_bytes(): int { return 1024; }
function bms_import_fetch_remote_image_once(string $url, int $limit): array {
    $value = $GLOBALS['media_fixture_response'];
    if ($value instanceof Throwable) { throw $value; }
    return $value;
}
function tempnam(string $dir, string $prefix): string|false {
    if (($GLOBALS['media_fixture_io'] ?? '') === 'temp') { return false; }
    return $GLOBALS['media_fixture_temp'] = \tempnam($dir, $prefix);
}
function file_put_contents(string $path, string $data): int|false {
    if (($GLOBALS['media_fixture_io'] ?? '') === 'write') { return false; }
    if (($GLOBALS['media_fixture_io'] ?? '') === 'partial') { return \file_put_contents($path, substr($data, 0, -1)); }
    return \file_put_contents($path, $data);
}
FIXTURE;
    $code .= "\n" . $source('bms_import_download_remote_image');
    $code .= <<<'FIXTURE'
namespace BmsMediaFetchRegression;
use \BMS_Media_Validation_Exception;
use \RuntimeException;
function bms_import_remote_image_fetch_target(string $url): ?array {
    return ['host' => 'example.test', 'port' => 443, 'ips' => ['8.8.8.8']];
}
function curl_init(string $url): object { return new \stdClass(); }
function curl_setopt_array(object $ch, array $options): bool { return true; }
function curl_exec(object $ch): bool { return false; }
function curl_error(object $ch): string { return 'synthetic_transport_private_marker password=fixture-secret'; }
function curl_getinfo(object $ch, int $option): mixed {
    return match ($option) { CURLINFO_RESPONSE_CODE => 0, CURLINFO_PRIMARY_IP => '8.8.8.8', default => '' };
}
function curl_close(object $ch): void {}
FIXTURE;
    $code .= "\n" . $source('bms_import_fetch_remote_image_once');
    file_put_contents($fixture, $code);
    require $fixture;
    $call = static function (): array {
        try {
            try { \BmsMediaImportRegression\bms_api_import_remote_media([], []); }
            catch (Throwable $e) { \BmsMediaImportRegression\bms_api_error_response($e); }
        } catch (\BmsMediaImportRegression\Response $response) {
            return [$response->status, $response->payload];
        }
        throw new RuntimeException('Import classification did not return an error.');
    };
    $GLOBALS['media_fixture_upload_error'] = new PDOException('synthetic_upload_private_marker password=fixture-secret');
    try {
        foreach ([
            ['status' => 404, 'mime' => '', 'location' => '', 'data' => 'missing'],
            ['status' => 200, 'mime' => '', 'location' => '', 'data' => ''],
            ['status' => 302, 'mime' => '', 'location' => 'https://example.test/again.png', 'data' => ''],
            ['status' => 302, 'mime' => '', 'location' => 'https://unsafe.test/private', 'data' => ''],
            new BMS_Media_Validation_Exception('Remote image exceeds the import size limit.'),
        ] as $response) {
            $GLOBALS['media_fixture_response'] = $response;
            [$status, $payload] = $call();
            $assert($status === 422 && $payload['error']['code'] === 'media_import_failed', 'Safe remote-resource failure lost its 4xx classification.');
        }
        // Use a URL without an image extension to exercise unsupported content.
        try {
            $GLOBALS['media_fixture_response'] = ['status' => 200, 'mime' => 'text/plain', 'location' => '', 'data' => 'plain text'];
            \BmsMediaDownloadRegression\bms_import_download_remote_image('https://example.test/file');
            throw new RuntimeException('Unsupported imported type was accepted.');
        } catch (BMS_Media_Validation_Exception $e) {
            $assert($e->getMessage() === 'Remote file is not a supported image type.', 'Unexpected safe imported-type message.');
        }
        foreach ([new RuntimeException('synthetic_internal_marker password=fixture-secret'), new PDOException('synthetic_database_marker'), new Error('synthetic_php_marker')] as $error) {
            $GLOBALS['media_fixture_response'] = $error;
            [$status, $payload] = $call();
            $assert($status === 500 && $payload === ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The API request could not be completed.']],
                'Unexpected importer exception crossed the public boundary.');
        }
        try {
            \BmsMediaFetchRegression\bms_import_fetch_remote_image_once('https://example.test/file.png', 1024);
            throw new RuntimeException('Transport fixture did not fail.');
        } catch (RuntimeException $e) {
            $assert(!$e instanceof BMS_Media_Validation_Exception && str_contains($e->getMessage(), 'synthetic_transport_private_marker'),
                'Low-level cURL diagnostics were classified public-safe.');
            $GLOBALS['media_fixture_response'] = $e;
            [$status, $payload] = $call();
            $assert($status === 500 && !str_contains(json_encode($payload), 'synthetic_transport'), 'Transport diagnostics escaped the import adapter.');
        }
        $GLOBALS['media_fixture_response'] = ['status' => 200, 'mime' => 'image/png', 'location' => '', 'data' => 'fixture'];
        foreach (['temp', 'write', 'partial', ''] as $failure) {
            $GLOBALS['media_fixture_io'] = $failure;
            [$status, $payload] = $call();
            $assert($status === 500 && $payload['error']['code'] === 'server_error', 'Import storage failure was classified as client validation.');
            $temp = (string)($GLOBALS['media_fixture_temp'] ?? '');
            $assert($temp === '' || !is_file($temp), 'Import failure left its temporary file.');
        }
        $assert(in_array('api-media-import', $GLOBALS['media_fixture_log'] ?? [], true), 'Internal import error was not logged privately.');
    } finally {
        @unlink((string)($GLOBALS['media_fixture_temp'] ?? ''));
        @unlink($fixture);
    }
}
