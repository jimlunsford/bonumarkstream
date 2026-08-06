<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';

function bms_stream_trash_wants_json(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
}

function bms_stream_trash_finish(array $payload, int $status, string $returnTo): void
{
    if (bms_stream_trash_wants_json()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($payload['message'])) {
        bms_flash((string)$payload['message'], !empty($payload['ok']) ? 'success' : 'error');
    }
    bms_redirect($returnTo);
}

$returnTo = bms_stream_safe_return_url((string)($_POST['return_to'] ?? bms_stream_home_url()));

if (!bms_is_logged_in()) {
    if (bms_stream_trash_wants_json()) {
        bms_stream_trash_finish(['ok' => false, 'message' => 'Your session expired. Sign in again before moving this post to Trash.'], 401, $returnTo);
    }
    bms_redirect(bms_admin_url('login.php?return_to=' . rawurlencode($returnTo)));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bms_stream_trash_finish(['ok' => false, 'message' => 'POST required.'], 405, $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
    bms_stream_trash_finish(['ok' => false, 'message' => 'Invalid request token. Reload the stream and try again.'], 403, $returnTo);
}

try {
    $file = basename((string)($_POST['file'] ?? ''));
    if ($file === '') {
        bms_stream_trash_finish(['ok' => false, 'message' => 'Stream post was not identified.'], 400, $returnTo);
    }

    $page = function_exists('bms_find_database_content_by_markdown_path')
        ? bms_find_database_content_by_markdown_path('published', $file)
        : null;
    if (!$page || (string)($page['post_type'] ?? $page['content_type'] ?? 'stream') !== 'stream') {
        bms_stream_trash_finish(['ok' => false, 'message' => 'Stream post was not found. It may already be in Trash.'], 404, $returnTo);
    }

    $subject = function_exists('bms_content_subject_for_file')
        ? bms_content_subject_for_file('published', $file, $page)
        : $page;
    if (!bms_current_user_can('edit_content', $subject)) {
        bms_stream_trash_finish(['ok' => false, 'message' => 'You do not have permission to move this post to Trash.'], 403, $returnTo);
    }

    $expectedHash = strtolower(trim((string)($_POST['content_hash'] ?? '')));
    $currentHash = hash('sha256', (string)($page['raw'] ?? bms_database_content_raw($page)));
    if ($expectedHash !== '' && !hash_equals($currentHash, $expectedHash)) {
        bms_stream_trash_finish([
            'ok' => false,
            'message' => 'This post changed after the page loaded. Reload the stream before moving it to Trash.',
        ], 409, $returnTo);
    }

    $deleted = bms_delete_content_file('published', $file);
    bms_stream_trash_finish([
        'ok' => true,
        'message' => 'Post moved to Trash.',
        'title' => (string)($deleted['title'] ?? 'Untitled'),
        'redirect_url' => $returnTo,
        'trash_url' => bms_admin_url('content.php?status=trash'),
    ], 200, $returnTo);
} catch (Throwable $e) {
    bms_log_admin_exception('stream-trash', $e);
    bms_stream_trash_finish(['ok' => false, 'message' => 'Post could not be moved to Trash. Please try again.'], 500, $returnTo);
}
