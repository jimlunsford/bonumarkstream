<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
mp_require_login();
mp_require_capability('manage_pages');
mp_verify_csrf();
$type = (string)($_POST['type'] ?? 'draft');
$type = $type === 'published' ? 'published' : 'draft';
$file = basename((string)($_POST['file'] ?? ''));
$section = $type === 'published' ? 'pages/published' : 'pages/drafts';
try {
    $page = function_exists('mp_find_database_content_by_markdown_path') ? mp_find_database_content_by_markdown_path($section, $file) : null;
    $legacyPath = mp_content_path($section . '/' . $file);
    if (!$page && $file !== '' && is_file($legacyPath)) {
        $page = mp_parse_markdown_file($legacyPath);
        $page['filename'] = $file;
        $page['section'] = $section;
    }
    if ($file === '' || !$page) {
        throw new RuntimeException('Page not found.');
    }
    mp_require_content_file_access($section, $file, 'edit_content', $page);
    $originalStatus = $section === 'pages/published' ? 'published' : 'draft';
    $trashFile = date('Ymd-His') . '-page-' . $originalStatus . '-' . $file;
    if (function_exists('mp_record_trashed_page')) {
        mp_record_trashed_page($page, $originalStatus, $file, $trashFile, '');
    }
    if (function_exists('mp_delete_post_metadata_by_filename')) {
        mp_delete_post_metadata_by_filename($section, $file);
    }
    if (is_file($legacyPath)) {
        @unlink($legacyPath);
    }
    if ($section === 'pages/published') {
    }
    mp_flash('Moved page “' . ($page['title'] ?? 'Untitled Page') . '” to trash.', 'success');
} catch (Throwable $e) {
    mp_flash('Move to trash failed. ' . $e->getMessage(), 'error');
}
mp_redirect(mp_admin_url('pages.php'));
