<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/database.php';
bms_require_login();
bms_verify_csrf();

$empty = !empty($_POST['empty_trash']);
$id = (int)($_POST['trash_id'] ?? 0);
try {
    if ($empty) {
        bms_require_capability('restore_trash');
        $count = bms_empty_trash();
        bms_flash('Trash emptied. ' . $count . ' item' . ($count === 1 ? '' : 's') . ' permanently deleted.', 'success');
    } else {
        bms_require_trash_item_access($id);
        $item = bms_delete_trash_item_permanently($id);
        if (!$item) {
            throw new RuntimeException('Trash item not found.');
        }
        bms_flash('Permanently deleted “' . ($item['title'] ?? 'content') . '”.', 'success');
    }
} catch (Throwable $e) {
    bms_flash('Permanent delete failed. ' . $e->getMessage(), 'error');
}
bms_redirect(bms_admin_url('content.php?status=trash'));
