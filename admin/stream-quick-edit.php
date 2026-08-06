<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function bms_stream_quick_edit_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!bms_is_logged_in()) {
    bms_stream_quick_edit_json(['ok' => false, 'message' => 'Your session expired. Sign in again before saving.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bms_stream_quick_edit_json(['ok' => false, 'message' => 'POST required.'], 405);
}

try {
    bms_verify_csrf();

    $file = basename((string)($_POST['file'] ?? ''));
    if ($file === '') {
        bms_stream_quick_edit_json(['ok' => false, 'message' => 'Stream post was not identified.'], 400);
    }

    $page = function_exists('bms_find_database_content_by_markdown_path')
        ? bms_find_database_content_by_markdown_path('published', $file)
        : null;
    if (!$page || (string)($page['post_type'] ?? $page['content_type'] ?? 'stream') !== 'stream') {
        bms_stream_quick_edit_json(['ok' => false, 'message' => 'Stream post was not found.'], 404);
    }

    $subject = function_exists('bms_content_subject_for_file')
        ? bms_content_subject_for_file('published', $file, $page)
        : $page;
    if (!bms_current_user_can('edit_content', $subject)) {
        bms_stream_quick_edit_json(['ok' => false, 'message' => 'You do not have permission to edit this post.'], 403);
    }

    $expectedHash = strtolower(trim((string)($_POST['content_hash'] ?? '')));
    $currentHash = hash('sha256', (string)($page['raw'] ?? bms_database_content_raw($page)));
    if ($expectedHash !== '' && !hash_equals($currentHash, $expectedHash)) {
        bms_stream_quick_edit_json([
            'ok' => false,
            'message' => 'This post changed after the page loaded. Reload the stream before saving your edit.',
        ], 409);
    }

    $body = str_replace(["\r\n", "\r"], "\n", (string)($_POST['body'] ?? ''));
    $body = trim($body);
    if (strlen($body) > 1024 * 1024 * 2) {
        bms_stream_quick_edit_json(['ok' => false, 'message' => 'Stream post text is too large. Keep it under 2 MB.'], 422);
    }

    $hasMedia = trim((string)($page['featured_media'] ?? '')) !== ''
        || count(bms_normalize_media_gallery($page['media_gallery'] ?? [], (string)($page['featured_media'] ?? ''))) > 0;
    $hasLinkPreview = trim((string)($page['link_preview_url'] ?? '')) !== '';
    $hasLocation = trim((string)($page['location_name'] ?? '')) !== '';
    if ($body === '' && !$hasMedia && !$hasLinkPreview && !$hasLocation) {
        bms_stream_quick_edit_json(['ok' => false, 'message' => 'A Stream Post cannot be completely empty.'], 422);
    }

    if ($body !== trim((string)($page['body'] ?? ''))) {
        $authorId = function_exists('bms_content_author_id_for_file')
            ? bms_content_author_id_for_file('published', $file)
            : null;
        if ($authorId === null && (int)($page['author_id'] ?? 0) > 0) {
            $authorId = (int)$page['author_id'];
        }
        if (function_exists('bms_record_revision_from_page')) {
            bms_record_revision_from_page($page, 'published', $file, $authorId);
        }
        $page = bms_update_stream_post_body($page, $body);
    }

    $newHash = hash('sha256', (string)($page['raw'] ?? bms_database_content_raw($page)));
    bms_stream_quick_edit_json([
        'ok' => true,
        'message' => 'Post updated.',
        'body' => (string)($page['body'] ?? $body),
        'body_html' => trim((string)($page['body'] ?? $body)) !== '' ? bms_markdown_to_html((string)($page['body'] ?? $body)) : '',
        'content_hash' => $newHash,
    ]);
} catch (Throwable $e) {
    bms_log_admin_exception('stream-quick-edit', $e);
    bms_stream_quick_edit_json(['ok' => false, 'message' => 'Quick edit could not be saved. Please try again.'], 500);
}
