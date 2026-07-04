<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
bms_require_login();
bms_verify_csrf();

$id = (int)($_POST['trash_id'] ?? 0);
try {
    bms_require_trash_item_access($id);
    $page = bms_restore_trash_item($id);
    $restoredStatus = (string)($page['restored_status'] ?? 'draft');
    $status = in_array($restoredStatus, ['published', 'scheduled'], true) ? $restoredStatus : 'draft';
    bms_flash('Restored “' . $page['title'] . '” as ' . ($status === 'published' ? 'published stream post' : ($status === 'scheduled' ? 'scheduled stream post' : 'a draft')) . '.', 'success');
    bms_redirect(bms_admin_url('content.php?status=' . ($status === 'published' ? 'published' : ($status === 'scheduled' ? 'scheduled' : 'draft'))));
} catch (Throwable $e) {
    bms_log_admin_exception('restore', $e);

    bms_flash('Restore failed. Please try again.', 'error');
    bms_redirect(bms_admin_url('content.php?status=trash'));
}
