<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
bms_require_login();
bms_require_capability('manage_pages');
bms_verify_csrf();
$file = basename((string)($_POST['file'] ?? ''));
try {
    $page = function_exists('bms_find_database_content_by_markdown_path') ? bms_find_database_content_by_markdown_path('pages/published', $file) : null;
    if ($file === '' || !$page) {
        throw new RuntimeException('Published page not found.');
    }
    bms_require_content_file_access('pages/published', $file, 'publish_content', $page);
    $authorId = bms_content_author_id_for_file('pages/published', $file);
    if ($authorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $authorId = (int)$page['author_id'];
    }
    $draft = bms_database_content_page_for_status($page, 'draft', 'page');
    $draftFile = bms_database_content_filename_for_page($draft);
    if (function_exists('bms_find_database_content_by_slug_status') && bms_find_database_content_by_slug_status((string)$draft['slug'], 'draft', 'page')) {
        throw new RuntimeException('A draft page already uses this slug.');
    }
    if (function_exists('bms_delete_post_metadata_by_filename')) {
        bms_delete_post_metadata_by_filename('pages/published', $file);
    }
    bms_sync_page_metadata($draft, 'pages/drafts', $draftFile, $authorId);
    bms_flash('Moved page “' . $draft['title'] . '” back to drafts.', 'success');
    bms_redirect(bms_admin_url('page-edit.php?type=draft&file=' . urlencode($draftFile)));
} catch (Throwable $e) {
    bms_flash('Unpublish failed. ' . $e->getMessage(), 'error');
    bms_redirect(bms_admin_url('pages.php'));
}
