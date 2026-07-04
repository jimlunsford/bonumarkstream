<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
bms_require_login();
bms_verify_csrf();

$type = $_POST['type'] ?? 'draft';
$file = basename($_POST['file'] ?? '');
$type = in_array($type, ['published', 'scheduled', 'draft'], true) ? $type : 'draft';
$section = $type === 'published' ? 'published' : ($type === 'scheduled' ? 'scheduled' : 'drafts');
bms_require_content_file_access($section, $file, 'edit_content');

try {
    $page = bms_delete_content_file($type, $file);
    bms_flash('Moved “' . $page['title'] . '” to Trash. You can restore it or delete it permanently later.', 'success');
} catch (Throwable $e) {
    bms_log_admin_exception('delete', $e);

    bms_flash('Move to Trash failed. Please try again.', 'error');
}

bms_redirect(bms_admin_url('content.php'));
