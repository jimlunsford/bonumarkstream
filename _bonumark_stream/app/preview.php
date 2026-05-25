<?php
require_once __DIR__ . '/renderer.php';
require_once __DIR__ . '/pages.php';

function mp_admin_preview_document(array $page, string $label = 'Preview', string $editUrl = ''): string
{
    $contentType = function_exists('mp_normalize_content_type') ? mp_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'stream')) : 'stream';
    $html = $contentType === 'page' && function_exists('mp_render_page') ? mp_render_page($page) : mp_render_public_content_page($page);
    $labelSafe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $editSafe = $editUrl !== '' ? htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') : '';
    $stylesheet = '<link rel="stylesheet" href="' . htmlspecialchars(mp_asset_url('assets/admin.css'), ENT_QUOTES, 'UTF-8') . '">';
    $bar = '<div class="bonumark-preview-bar"><div><strong>Bonumark Preview</strong> <span>' . $labelSafe . '</span></div>' . ($editSafe !== '' ? '<a href="' . $editSafe . '" target="_top">Back to Editor</a>' : '') . '</div>';

    if (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $stylesheet . '</head>', $html, 1) ?? $html;
    }
    if (preg_match('/<body\b[^>]*>/i', $html)) {
        $html = preg_replace('/(<body\b[^>]*>)/i', '$1' . $bar, $html, 1) ?? $html;
    } else {
        $html = $bar . $html;
    }
    return $html;
}

function mp_preview_current_page_from_request(): array
{
    $contentKind = (string)($_POST['content_kind'] ?? 'stream');
    if ($contentKind === 'page') {
        mp_require_capability('manage_pages');
    }
    $type = (string)($_POST['type'] ?? $_POST['stream_status'] ?? 'draft');
    $status = $type === 'published' ? 'published' : 'draft';
    $currentSlug = '';
    $file = basename((string)($_POST['file'] ?? ''));
    if ($file !== '') {
        $section = $contentKind === 'page' ? ($status === 'published' ? 'pages/published' : 'pages/drafts') : ($status === 'published' ? 'published' : 'drafts');
        $existing = null;
        if (function_exists('mp_find_database_content_by_markdown_path')) {
            $existing = mp_find_database_content_by_markdown_path($section, $file);
        }
        if (!$existing) {
            $path = mp_content_path($section . '/' . $file);
            if (is_file($path)) {
                $existing = mp_parse_markdown_file($path);
                $existing['content_storage'] = 'legacy-markdown';
            }
        }
        if ($existing) {
            mp_require_content_file_access($section, $file, 'edit_content', $existing);
            $currentSlug = (string)($existing['slug'] ?? pathinfo($file, PATHINFO_FILENAME));
        }
    }

    $raw = $contentKind === 'page'
        ? mp_build_page_markdown_from_request($status, $currentSlug)
        : mp_build_markdown_from_request($status, $currentSlug);
    return mp_parse_markdown_string($raw);
}
