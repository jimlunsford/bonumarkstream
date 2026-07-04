<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
bms_require_login();
bms_require_capability('manage_pages');
bms_verify_csrf();
$empty = !empty($_POST['empty_page_trash']);
$id = (int)($_POST['trash_id'] ?? 0);
try {
    if ($empty) {
        $count = bms_empty_page_trash();
        bms_flash('Page Trash emptied. ' . $count . ' page' . ($count === 1 ? '' : 's') . ' permanently deleted.', 'success');
    } else {
        $item = bms_delete_page_trash_item_permanently($id);
        if (!$item) {
            throw new RuntimeException('Page trash item not found.');
        }
        bms_flash('Permanently deleted page “' . ($item['title'] ?? 'content') . '”.', 'success');
    }
} catch (Throwable $e) {
    bms_log_admin_exception('page-delete-permanent', $e);

    bms_flash('Permanent delete failed. Please try again.', 'error');
}
bms_redirect(bms_admin_url('pages.php?status=trash'));
