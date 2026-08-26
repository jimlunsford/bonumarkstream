<?php
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';

$result = bms_run_due_tasks('server_cron', true, 50);
echo json_encode([
    'ok' => !empty($result['ok']),
    'status' => (string)($result['status'] ?? 'error'),
    'scheduled_posts_published' => (int)($result['scheduled_posts_published'] ?? 0),
    'message' => (string)($result['message'] ?? ''),
    'checked_at' => gmdate('c', (int)($result['completed_at_unix'] ?? time())),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
