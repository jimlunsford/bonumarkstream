<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
bms_require_login();
bms_require_capability('manage_pages');
bms_verify_csrf();
$file = basename((string)($_POST['file'] ?? ''));
try {
    $page = function_exists('bms_find_database_content_by_markdown_path') ? bms_find_database_content_by_markdown_path('pages/drafts', $file) : null;
    if ($file === '' || !$page) {
        throw new RuntimeException('Draft page not found.');
    }
    bms_require_content_file_access('pages/drafts', $file, 'publish_content', $page);
    $authorId = bms_content_author_id_for_file('pages/drafts', $file);
    if ($authorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $authorId = (int)$page['author_id'];
    }
    $published = bms_database_content_page_for_status($page, 'published', 'page');
    $publishedFile = bms_database_content_filename_for_page($published);
    if (function_exists('bms_find_database_content_by_slug_status') && bms_find_database_content_by_slug_status((string)$published['slug'], 'published', 'page')) {
        throw new RuntimeException('A published page already uses this slug.');
    }
    if (function_exists('bms_delete_post_metadata_by_filename')) {
        bms_delete_post_metadata_by_filename('pages/drafts', $file);
    }
    bms_sync_page_metadata($published, 'pages/published', $publishedFile, $authorId);
    bms_flash('Page published. “' . $published['title'] . '” is live through dynamic rendering.', 'success');
    bms_redirect(bms_admin_url('page-edit.php?type=published&file=' . urlencode($publishedFile)));
} catch (Throwable $e) {
    bms_log_admin_exception('page-publish', $e);

    bms_flash('Publish failed. Please try again.', 'error');
    bms_redirect(bms_admin_url('pages.php'));
}
