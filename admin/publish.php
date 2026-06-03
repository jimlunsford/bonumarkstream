<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
bms_require_login();
bms_verify_csrf();

$file = basename($_POST['file'] ?? '');
$return = (string)($_POST['return'] ?? 'edit');
bms_require_content_file_access('drafts', $file, 'publish_content');
try {
    $page = bms_publish_file($file);
    $publishedFile = basename($page['slug'] . '.md');
    $autosaveKey = trim((string)($_POST['autosave_key'] ?? ''));
    if ($autosaveKey !== '' && function_exists('bms_delete_autosave')) {
        bms_delete_autosave($autosaveKey);
    }
    bms_flash('Published “' . $page['title'] . '”. The stream post is live.', 'success');

    if ($return === 'content') {
        bms_redirect(bms_admin_url('content.php?status=published'));
    }

    if ($return === 'view') {
        bms_redirect(bms_stream_url_for_post($page));
    }

    bms_redirect(bms_admin_url('edit.php?type=published&file=' . urlencode($publishedFile)));
} catch (Throwable $e) {
    bms_flash('Publish failed. ' . $e->getMessage(), 'error');
    bms_redirect(bms_admin_url('content.php?status=draft'));
}
