<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
mp_require_login();
mp_verify_csrf();

$file = basename($_POST['file'] ?? '');
mp_require_content_file_access('published', $file, 'publish_content');
try {
    $page = mp_unpublish_file($file);
    mp_flash('Moved “' . $page['title'] . '” back to drafts. The public stream post was removed.', 'success');
    mp_redirect(mp_admin_url('content.php?status=draft'));
} catch (Throwable $e) {
    mp_flash('Move to drafts failed. ' . $e->getMessage(), 'error');
    mp_redirect(mp_admin_url('content.php?status=published'));
}
