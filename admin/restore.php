<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
bms_require_login();
bms_verify_csrf();

$id = (int)($_POST['trash_id'] ?? 0);
try {
    bms_require_trash_item_access($id);
    $page = bms_restore_trash_item($id);
    $status = ($page['restored_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    bms_flash('Restored “' . $page['title'] . '” as ' . ($status === 'published' ? 'published stream post' : 'a draft') . '.', 'success');
    bms_redirect(bms_admin_url('content.php?status=' . ($status === 'published' ? 'published' : 'draft')));
} catch (Throwable $e) {
    bms_flash('Restore failed. ' . $e->getMessage(), 'error');
    bms_redirect(bms_admin_url('content.php?status=trash'));
}
