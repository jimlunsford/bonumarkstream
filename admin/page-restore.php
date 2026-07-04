<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
bms_require_login();
bms_require_capability('manage_pages');
bms_verify_csrf();
$id = (int)($_POST['trash_id'] ?? 0);
try {
    $page = bms_restore_page_trash_item($id);
    $status = ($page['restored_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    bms_flash('Restored page “' . ($page['title'] ?? 'Untitled Page') . '” as ' . ($status === 'published' ? 'a published page' : 'a draft page') . '.', 'success');
    bms_redirect(bms_admin_url('pages.php?status=' . ($status === 'published' ? 'published' : 'draft')));
} catch (Throwable $e) {
    bms_log_admin_exception('page-restore', $e);

    bms_flash('Page restore failed. Please try again.', 'error');
    bms_redirect(bms_admin_url('pages.php?status=trash'));
}
