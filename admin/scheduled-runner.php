<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';

bms_require_login();
bms_require_capability('publish_content');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required.']);
        exit;
    }
    bms_verify_csrf();
    $result = bms_maybe_run_due_tasks('heartbeat');
    echo json_encode([
        'ok' => !empty($result['ok']),
        'status' => (string)($result['status'] ?? 'completed'),
        'published' => (int)($result['scheduled_posts_published'] ?? 0),
        'message' => (string)($result['message'] ?? ''),
        'checked_at' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Scheduled task check failed.']);
}
