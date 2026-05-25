<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
mp_require_login();
mp_verify_csrf();

$file = basename($_POST['file'] ?? '');
$return = (string)($_POST['return'] ?? 'edit');
mp_require_content_file_access('drafts', $file, 'publish_content');
try {
    $page = mp_publish_file($file);
    $publishedFile = basename($page['slug'] . '.md');
    $autosaveKey = trim((string)($_POST['autosave_key'] ?? ''));
    if ($autosaveKey !== '' && function_exists('mp_delete_autosave')) {
        mp_delete_autosave($autosaveKey);
    }
    mp_flash('Published “' . $page['title'] . '”. The stream post is live.', 'success');

    if ($return === 'content') {
        mp_redirect(mp_admin_url('content.php?status=published'));
    }

    if ($return === 'view') {
        mp_redirect(mp_stream_url_for_post($page));
    }

    mp_redirect(mp_admin_url('edit.php?type=published&file=' . urlencode($publishedFile)));
} catch (Throwable $e) {
    mp_flash('Publish failed. ' . $e->getMessage(), 'error');
    mp_redirect(mp_admin_url('content.php?status=draft'));
}
