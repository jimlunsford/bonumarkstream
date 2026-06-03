<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/link-preview.php';
bms_require_login();
bms_require_capability('edit_content');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function bms_link_preview_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        bms_link_preview_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }

    bms_verify_csrf();
    $url = bms_link_preview_clean_url((string)($_POST['url'] ?? ''));
    if ($url === '') {
        $url = bms_link_preview_first_url((string)($_POST['text'] ?? ''));
    }
    if ($url === '') {
        bms_link_preview_json(['ok' => false, 'message' => 'No previewable link was found.'], 400);
    }

    $preview = bms_link_preview_from_url($url);
    bms_link_preview_json(['ok' => true, 'preview' => $preview]);
} catch (Throwable $e) {
    bms_link_preview_json(['ok' => false, 'message' => $e->getMessage()], 422);
}
