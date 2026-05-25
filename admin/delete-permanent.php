<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/database.php';
mp_require_login();
mp_verify_csrf();

$empty = !empty($_POST['empty_trash']);
$id = (int)($_POST['trash_id'] ?? 0);
try {
    if ($empty) {
        mp_require_capability('restore_trash');
        $count = mp_empty_trash();
        mp_flash('Trash emptied. ' . $count . ' item' . ($count === 1 ? '' : 's') . ' permanently deleted.', 'success');
    } else {
        mp_require_trash_item_access($id);
        $item = mp_delete_trash_item_permanently($id);
        if (!$item) {
            throw new RuntimeException('Trash item not found.');
        }
        mp_flash('Permanently deleted “' . ($item['title'] ?? 'content') . '”.', 'success');
    }
} catch (Throwable $e) {
    mp_flash('Permanent delete failed. ' . $e->getMessage(), 'error');
}
mp_redirect(mp_admin_url('content.php?status=trash'));
