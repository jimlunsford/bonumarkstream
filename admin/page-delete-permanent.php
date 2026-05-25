<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
mp_require_login();
mp_require_capability('manage_pages');
mp_verify_csrf();
$empty = !empty($_POST['empty_page_trash']);
$id = (int)($_POST['trash_id'] ?? 0);
try {
    if ($empty) {
        $count = mp_empty_page_trash();
        mp_flash('Page Trash emptied. ' . $count . ' page' . ($count === 1 ? '' : 's') . ' permanently deleted.', 'success');
    } else {
        $item = mp_delete_page_trash_item_permanently($id);
        if (!$item) {
            throw new RuntimeException('Page trash item not found.');
        }
        mp_flash('Permanently deleted page “' . ($item['title'] ?? 'content') . '”.', 'success');
    }
} catch (Throwable $e) {
    mp_flash('Permanent delete failed. ' . $e->getMessage(), 'error');
}
mp_redirect(mp_admin_url('pages.php?status=trash'));
