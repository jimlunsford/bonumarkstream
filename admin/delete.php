<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
mp_require_login();
mp_verify_csrf();

$type = $_POST['type'] ?? 'draft';
$file = basename($_POST['file'] ?? '');
$section = $type === 'published' ? 'published' : 'drafts';
mp_require_content_file_access($section, $file, 'edit_content');

try {
    $page = mp_delete_content_file($type === 'published' ? 'published' : 'draft', $file);
    mp_flash('Moved “' . $page['title'] . '” to Trash. You can restore it or delete it permanently later.', 'success');
} catch (Throwable $e) {
    mp_flash('Move to Trash failed. ' . $e->getMessage(), 'error');
}

mp_redirect(mp_admin_url('content.php'));
