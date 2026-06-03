<?php
declare(strict_types=1);

$__bms_like_buffer_level = ob_get_level();
ob_start();

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/interactions.php';

$__bms_like_boot_noise = ob_get_clean();

function bms_stream_like_json(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Bonumark-Stream-Endpoint: likes');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!bms_is_installed()) {
        bms_stream_like_json(['ok' => false, 'message' => 'Bonumark Stream is not installed.'], 503);
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));

    if ($method === 'OPTIONS') {
        header('Allow: GET, POST, OPTIONS');
        bms_stream_like_json(['ok' => true, 'data' => ['allow' => 'GET, POST, OPTIONS']]);
    }

    if ($method === 'GET') {
        $raw = (string)($_GET['slugs'] ?? $_GET['slug'] ?? '');
        $slugs = preg_split('/[\s,]+/', $raw) ?: [];
        $slugs = array_values(array_filter(array_map('bms_slugify', $slugs)));

        if (!$slugs) {
            bms_stream_like_json(['ok' => false, 'message' => 'Missing stream posts.'], 400);
        }

        $data = bms_stream_like_status_for_slugs($slugs);
        bms_stream_like_json(['ok' => true, 'data' => $data]);
    }

    if ($method !== 'POST') {
        bms_stream_like_json(['ok' => false, 'message' => 'Invalid request method.'], 405);
    }

    $slug = bms_slugify((string)($_POST['slug'] ?? ''));

    if ($slug === '') {
        $rawInput = trim((string)file_get_contents('php://input'));
        if ($rawInput !== '') {
            $json = json_decode($rawInput, true);
            if (is_array($json)) {
                $slug = bms_slugify((string)($json['slug'] ?? ''));
            }
        }
    }

    if ($slug === '') {
        bms_stream_like_json(['ok' => false, 'message' => 'Missing stream post.'], 400);
    }

    $result = bms_stream_register_like($slug);
    bms_stream_like_json(['ok' => true, 'data' => $result]);
} catch (Throwable $e) {
    error_log('Bonumark Stream like endpoint failed: ' . $e->getMessage());
    bms_stream_like_json(['ok' => false, 'message' => 'Could not process like request.'], 422);
}
