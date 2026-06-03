<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/../_bonumark_stream/app/link-preview.php';
bms_require_login();
bms_require_capability('edit_content');

$returnTo = bms_stream_safe_return_url((string)($_POST['return_to'] ?? ($_SERVER['HTTP_REFERER'] ?? bms_url_path())));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bms_redirect($returnTo);
}

bms_verify_csrf();

$body = trim((string)($_POST['stream_body'] ?? ''));
$bodyLength = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
if ($bodyLength > 5000) {
    bms_flash('Stream post is too long. Keep quick posts under 5,000 characters.', 'error');
    bms_redirect($returnTo);
}

$featuredMedia = '';
try {
    $file = $_FILES['stream_media'] ?? ($_FILES['stream_image'] ?? null);
    if (is_array($file) && (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        if (($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('The selected media file is too large for this server or the configured upload limit.');
        }
        $media = bms_media_upload($file, '', '');
        $featuredMedia = trim((string)($media['public_path'] ?? ''));
    }

    if ($body === '' && $featuredMedia === '') {
        bms_flash('Write something, attach media, or do both before posting.', 'error');
        bms_redirect($returnTo);
    }

    $now = date('Y-m-d H:i:s');
    $baseSlug = bms_stream_slug_base($body, $now, is_array($media ?? null) ? $media : []);
    $slug = bms_stream_unique_slug($baseSlug);

    $title = bms_stream_admin_title_from_body($body, $now, $featuredMedia, is_array($media ?? null) ? $media : []);
    $seoTitle = bms_stream_generated_seo_title($body, $now, $featuredMedia, is_array($media ?? null) ? $media : []);
    $description = bms_stream_generated_description($body, $now, $featuredMedia);
    $linkPreviewFields = function_exists('bms_link_preview_payload_from_request') ? bms_link_preview_front_matter_fields(bms_link_preview_payload_from_request()) : [];
    $targetStatus = 'published';
    $targetSection = 'published';

    $raw = bms_build_markdown_document([
        'title' => $title,
        'slug' => $slug,
        'status' => $targetStatus,
        'content_type' => 'stream',
        'date' => date('Y-m-d'),
        'description' => $description,
        'category' => 'Stream',
        'tags' => [],
        'featured_media' => $featuredMedia,
        'stream_created_at' => $now,
        'seo_title' => $seoTitle,
    ] + $linkPreviewFields, $body);

    $page = bms_parse_markdown_string($raw);
    $filename = $page['slug'] . '.md';
    if (function_exists('bms_sync_stream_metadata')) {
        bms_sync_stream_metadata($page, $targetSection, $filename, bms_current_user_id());
    }


    bms_flash($featuredMedia !== '' && $body === '' ? 'Media posted to the stream.' : 'Posted to the stream.', 'success');
} catch (Throwable $e) {
    bms_flash('Stream post failed. ' . $e->getMessage(), 'error');
}

bms_redirect($returnTo);
