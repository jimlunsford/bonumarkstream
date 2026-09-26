<?php
require_once __DIR__ . '/functions.php';

/** Submission receipts are session UI state, never a second content store. */
function bms_composer_request_key(): string
{
    $key = bin2hex(random_bytes(16));
    $_SESSION['bms_composer_requests'][$key] = ['state' => 'ready', 'user_id' => bms_current_user_id(), 'created' => time()];
    // Bound old ready/completed forms. An uncertain request remains blocked.
    foreach ($_SESSION['bms_composer_requests'] as $id => $entry) {
        if (($entry['state'] ?? '') !== 'working' && (int)($entry['created'] ?? 0) < time() - 86400) {
            unset($_SESSION['bms_composer_requests'][$id]);
        }
    }
    return $key;
}

function bms_composer_begin_request(string $key): ?string
{
    $entry = $_SESSION['bms_composer_requests'][$key] ?? null;
    if (!is_array($entry) || ($entry['user_id'] ?? null) !== bms_current_user_id()) {
        throw new InvalidArgumentException('This composer has expired. Copy your text, reload the page, and try again.');
    }
    if (($entry['state'] ?? '') === 'complete') {
        return (string)$entry['return_to'];
    }
    if (($entry['state'] ?? '') !== 'ready') {
        throw new InvalidArgumentException('The previous save could not be confirmed. Check your Stream and Drafts before starting another post. Your text is still here.');
    }
    $_SESSION['bms_composer_requests'][$key]['state'] = 'working';
    // Persist the guard before a database write. A worker failure must not enable a blind retry.
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!session_write_close()) {
            throw new RuntimeException('The save guard could not be stored. Your post has not been submitted.');
        }
        bms_start_secure_session();
    }
    return null;
}

function bms_composer_complete_request(string $key, string $returnTo): void
{
    $_SESSION['bms_composer_requests'][$key]['state'] = 'complete';
    $_SESSION['bms_composer_requests'][$key]['return_to'] = $returnTo;
    if (($_SESSION['bms_composer_recovery']['composer_request_key'] ?? '') === $key) {
        unset($_SESSION['bms_composer_recovery']);
    }
}

function bms_composer_preserve_request(string $key, array $input, bool $safeToRetry): void
{
    if ($safeToRetry && isset($_SESSION['bms_composer_requests'][$key])) {
        $_SESSION['bms_composer_requests'][$key]['state'] = 'ready';
    }
    $fields = ['stream_body', 'stream_title', 'stream_slug', 'stream_description', 'stream_seo_title',
        'stream_robots', 'stream_scheduled_at', 'stream_submit_action', 'stream_schedule_enabled',
        'activitypub_reply_object_uri', 'location_place_id', 'location_display_mode',
        'link_preview_enabled', 'link_preview_url', 'link_preview_title', 'link_preview_description',
        'link_preview_image', 'link_preview_site_name', 'return_to'];
    $recovery = ['composer_request_key' => $key, 'user_id' => bms_current_user_id()];
    foreach ($fields as $field) {
        if (isset($input[$field]) && is_string($input[$field]) && strlen($input[$field]) <= 2 * 1024 * 1024) {
            $recovery[$field] = $input[$field];
        }
    }
    $_SESSION['bms_composer_recovery'] = $recovery;
}

function bms_composer_recovery(string $returnTo, string $replyUri): array
{
    $recovery = $_SESSION['bms_composer_recovery'] ?? [];
    if (($recovery['user_id'] ?? null) !== bms_current_user_id()
        || ($recovery['return_to'] ?? '') !== $returnTo
        || ($recovery['activitypub_reply_object_uri'] ?? '') !== $replyUri) {
        return [];
    }
    return $recovery;
}

function bms_composer_response(bool $ok, string $returnTo, string $message = '', int $status = 422): never
{
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        http_response_code($ok ? 200 : $status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $ok, 'redirect' => $ok ? $returnTo : null, 'message' => $message], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if ($message !== '') {
        bms_flash($message, $ok ? 'success' : 'error');
    }
    bms_redirect($returnTo);
}
