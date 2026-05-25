<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
mp_require_login();
mp_verify_csrf();

$file = basename((string)($_POST['file'] ?? ''));
if ($file === '') {
    mp_flash('Submit for review failed. Missing draft file.', 'error');
    mp_redirect(mp_admin_url('content.php?status=draft'));
}

mp_require_content_file_access('drafts', $file, 'edit_content');

try {
    $page = function_exists('mp_find_database_content_by_markdown_path') ? mp_find_database_content_by_markdown_path('drafts', $file) : null;
    if (!$page && !is_file(mp_content_path('drafts/' . $file))) {
        throw new RuntimeException('Draft not found.');
    }
    if (function_exists('mp_mark_draft_pending_review')) {
        mp_mark_draft_pending_review($file);
    }
    mp_flash('Draft submitted for review. An admin can publish it from the review queue.', 'success');
    mp_redirect(mp_admin_url('edit.php?type=draft&file=' . urlencode($file)));
} catch (Throwable $e) {
    mp_flash('Submit for review failed. ' . $e->getMessage(), 'error');
    mp_redirect(mp_admin_url('content.php?status=draft'));
}
