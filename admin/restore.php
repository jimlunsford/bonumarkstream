<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
mp_require_login();
mp_verify_csrf();

$id = (int)($_POST['trash_id'] ?? 0);
try {
    mp_require_trash_item_access($id);
    $page = mp_restore_trash_item($id);
    $status = ($page['restored_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    mp_flash('Restored “' . $page['title'] . '” as ' . ($status === 'published' ? 'published stream post' : 'a draft') . '.', 'success');
    mp_redirect(mp_admin_url('content.php?status=' . ($status === 'published' ? 'published' : 'draft')));
} catch (Throwable $e) {
    mp_flash('Restore failed. ' . $e->getMessage(), 'error');
    mp_redirect(mp_admin_url('content.php?status=trash'));
}
