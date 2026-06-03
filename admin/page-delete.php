<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
bms_require_login();
bms_require_capability('manage_pages');
bms_verify_csrf();
$type = (string)($_POST['type'] ?? 'draft');
$type = $type === 'published' ? 'published' : 'draft';
$file = basename((string)($_POST['file'] ?? ''));
$section = $type === 'published' ? 'pages/published' : 'pages/drafts';
try {
    $page = function_exists('bms_find_database_content_by_markdown_path') ? bms_find_database_content_by_markdown_path($section, $file) : null;
    if ($file === '' || !$page) {
        throw new RuntimeException('Page not found.');
    }
    bms_require_content_file_access($section, $file, 'edit_content', $page);
    $originalStatus = $section === 'pages/published' ? 'published' : 'draft';
    $trashFile = date('Ymd-His') . '-page-' . $originalStatus . '-' . $file;
    if (function_exists('bms_record_trashed_page')) {
        bms_record_trashed_page($page, $originalStatus, $file, $trashFile, '');
    }
    if (function_exists('bms_delete_post_metadata_by_filename')) {
        bms_delete_post_metadata_by_filename($section, $file);
    }
    if ($section === 'pages/published') {
    }
    bms_flash('Moved page “' . ($page['title'] ?? 'Untitled Page') . '” to trash.', 'success');
} catch (Throwable $e) {
    bms_flash('Move to trash failed. ' . $e->getMessage(), 'error');
}
bms_redirect(bms_admin_url('pages.php'));
