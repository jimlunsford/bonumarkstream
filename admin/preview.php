<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/preview.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

$type = $_GET['type'] ?? 'draft';
$file = basename($_GET['file'] ?? '');
$isPagePreview = $type === 'page-draft' || $type === 'page-published';
if ($type === 'page-published') {
    $section = 'pages/published';
} elseif ($type === 'page-draft') {
    $section = 'pages/drafts';
} else {
    $section = $type === 'published' ? 'published' : 'drafts';
}

if ($file === '') {
    bms_admin_error_page('Content not found', 'The requested content record could not be found.', 404);
}

$page = null;
if (function_exists('bms_find_database_content_by_markdown_path')) {
    $page = bms_find_database_content_by_markdown_path($section, $file);
}

if (!$page) {
    bms_admin_error_page('Content not found', 'The requested database record could not be found.', 404);
}


if ($isPagePreview) {
    bms_require_capability('manage_pages');
} else {
    bms_require_content_file_access($section, $file, 'edit_content', $page);
}

header('X-Robots-Tag: noindex, nofollow', true);
$editUrl = $isPagePreview ? bms_admin_url('page-edit.php?type=' . ($section === 'pages/published' ? 'published' : 'draft') . '&file=' . urlencode($file)) : bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file));
$label = str_contains($section, 'published') ? 'Saved published database record' : 'Saved draft database record';
echo bms_admin_preview_document($page, $label, $editUrl);
exit;
