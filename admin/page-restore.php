<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
mp_require_login();
mp_require_capability('manage_pages');
mp_verify_csrf();
$id = (int)($_POST['trash_id'] ?? 0);
try {
    $page = mp_restore_page_trash_item($id);
    $status = ($page['restored_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    mp_flash('Restored page “' . ($page['title'] ?? 'Untitled Page') . '” as ' . ($status === 'published' ? 'a published page' : 'a draft page') . '.', 'success');
    mp_redirect(mp_admin_url('pages.php?status=' . ($status === 'published' ? 'published' : 'draft')));
} catch (Throwable $e) {
    mp_flash('Page restore failed. ' . $e->getMessage(), 'error');
    mp_redirect(mp_admin_url('pages.php?status=trash'));
}
