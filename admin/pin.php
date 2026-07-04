<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
bms_require_login();
bms_verify_csrf();

$file = basename((string)($_POST['file'] ?? ''));
$action = (string)($_POST['action'] ?? '');
$returnTo = bms_stream_safe_return_url((string)($_POST['return_to'] ?? ''));
if ($returnTo === '') {
    $returnTo = bms_admin_url('content.php?status=published');
}

try {
    if (!in_array($action, ['pin', 'unpin'], true) || $file === '') {
        throw new RuntimeException('Choose a published stream post to pin or unpin.');
    }

    $page = bms_find_database_content_by_markdown_path('published', $file);
    bms_require_content_file_access('published', $file, 'publish_content', is_array($page) ? $page : null);
    if (!is_array($page) || !bms_is_stream_post($page)) {
        throw new RuntimeException('Only published stream posts can be pinned.');
    }

    $updated = bms_set_stream_post_pinned_state($file, $action === 'pin');
    bms_flash(($action === 'pin' ? 'Pinned' : 'Unpinned') . ' “' . (string)($updated['title'] ?? 'Stream post') . '”.', 'success');
} catch (Throwable $e) {
    bms_log_admin_exception('pin', $e);

    bms_flash('Pinned post update failed. Please try again.', 'error');
}

bms_redirect($returnTo);
