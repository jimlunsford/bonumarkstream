<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/preview.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

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
    mp_admin_error_page('Content not found', 'The requested content record could not be found.', 404);
}

$page = null;
if (function_exists('mp_find_database_content_by_markdown_path')) {
    $page = mp_find_database_content_by_markdown_path($section, $file);
}

if (!$page) {
    $path = mp_content_path($section . '/' . $file);
    if (!is_file($path)) {
        mp_admin_error_page('Content not found', 'The requested database record or legacy Markdown source could not be found.', 404);
    }
    $page = mp_parse_markdown_file($path);
    $page['filename'] = $file;
    $page['section'] = $section;
    $page['content_storage'] = 'legacy-markdown';
}

if ($isPagePreview) {
    mp_require_capability('manage_pages');
} else {
    mp_require_content_file_access($section, $file, 'edit_content', $page);
}

header('X-Robots-Tag: noindex, nofollow', true);
$editUrl = $isPagePreview ? mp_admin_url('page-edit.php?type=' . ($section === 'pages/published' ? 'published' : 'draft') . '&file=' . urlencode($file)) : mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file));
$label = ($page['content_storage'] ?? '') === 'database'
    ? (str_contains($section, 'published') ? 'Saved published database record' : 'Saved draft database record')
    : (str_contains($section, 'published') ? 'Legacy published Markdown source' : 'Legacy draft Markdown source');
echo mp_admin_preview_document($page, $label, $editUrl);
exit;
