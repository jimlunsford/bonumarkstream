<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
mp_require_login();
mp_require_capability('manage_pages');
mp_verify_csrf();
$file = basename((string)($_POST['file'] ?? ''));
try {
    $page = function_exists('mp_find_database_content_by_markdown_path') ? mp_find_database_content_by_markdown_path('pages/published', $file) : null;
    $legacyPath = mp_content_path('pages/published/' . $file);
    if (!$page && $file !== '' && is_file($legacyPath)) {
        $page = mp_parse_markdown_file($legacyPath);
        $page['filename'] = $file;
        $page['section'] = 'pages/published';
    }
    if ($file === '' || !$page) {
        throw new RuntimeException('Published page not found.');
    }
    mp_require_content_file_access('pages/published', $file, 'publish_content', $page);
    $authorId = mp_content_author_id_for_file('pages/published', $file);
    if ($authorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $authorId = (int)$page['author_id'];
    }
    $draft = mp_database_content_page_for_status($page, 'draft', 'page');
    $draftFile = mp_database_content_filename_for_page($draft);
    if (function_exists('mp_find_database_content_by_slug_status') && mp_find_database_content_by_slug_status((string)$draft['slug'], 'draft', 'page')) {
        throw new RuntimeException('A draft page already uses this slug.');
    }
    if (function_exists('mp_delete_post_metadata_by_filename')) {
        mp_delete_post_metadata_by_filename('pages/published', $file);
    }
    if (is_file($legacyPath)) {
        @unlink($legacyPath);
    }
    mp_sync_page_metadata($draft, 'pages/drafts', $draftFile, $authorId);
    mp_flash('Moved page “' . $draft['title'] . '” back to drafts.', 'success');
    mp_redirect(mp_admin_url('page-edit.php?type=draft&file=' . urlencode($draftFile)));
} catch (Throwable $e) {
    mp_flash('Unpublish failed. ' . $e->getMessage(), 'error');
    mp_redirect(mp_admin_url('pages.php'));
}
