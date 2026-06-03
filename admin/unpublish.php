<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
bms_require_login();
bms_verify_csrf();

$file = basename($_POST['file'] ?? '');
bms_require_content_file_access('published', $file, 'publish_content');
try {
    $page = bms_unpublish_file($file);
    bms_flash('Moved “' . $page['title'] . '” back to drafts. The public stream post was removed.', 'success');
    bms_redirect(bms_admin_url('content.php?status=draft'));
} catch (Throwable $e) {
    bms_flash('Move to drafts failed. ' . $e->getMessage(), 'error');
    bms_redirect(bms_admin_url('content.php?status=published'));
}
