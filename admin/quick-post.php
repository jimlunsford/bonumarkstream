<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/../_bonumark_stream/app/link-preview.php';
mp_require_login();
mp_require_capability('edit_content');

$returnTo = mp_stream_safe_return_url((string)($_POST['return_to'] ?? ($_SERVER['HTTP_REFERER'] ?? mp_url_path())));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mp_redirect($returnTo);
}

mp_verify_csrf();

$body = trim((string)($_POST['stream_body'] ?? ''));
$bodyLength = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
if ($bodyLength > 5000) {
    mp_flash('Stream post is too long. Keep quick posts under 5,000 characters.', 'error');
    mp_redirect($returnTo);
}

$featuredMedia = '';
try {
    $file = $_FILES['stream_media'] ?? ($_FILES['stream_image'] ?? null);
    if (is_array($file) && (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        if (($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('The selected media file is too large for this server or your role limit.');
        }
        $media = mp_media_upload($file, '', '');
        $featuredMedia = trim((string)($media['public_path'] ?? ''));
    }

    if ($body === '' && $featuredMedia === '') {
        mp_flash('Write something, attach media, or do both before posting.', 'error');
        mp_redirect($returnTo);
    }

    $now = date('Y-m-d H:i:s');
    $baseSlug = mp_stream_slug_base($body, $now, is_array($media ?? null) ? $media : []);
    $slug = mp_stream_unique_slug($baseSlug);

    $title = mp_stream_admin_title_from_body($body, $now, $featuredMedia, is_array($media ?? null) ? $media : []);
    $seoTitle = mp_stream_generated_seo_title($body, $now, $featuredMedia, is_array($media ?? null) ? $media : []);
    $description = mp_stream_generated_description($body, $now, $featuredMedia);
    $linkPreviewFields = function_exists('mp_link_preview_payload_from_request') ? mp_link_preview_front_matter_fields(mp_link_preview_payload_from_request()) : [];
    $requiresReview = function_exists('mp_current_user_requires_post_review') && mp_current_user_requires_post_review();
    $targetStatus = $requiresReview ? 'draft' : 'published';
    $targetSection = $targetStatus === 'published' ? 'published' : 'drafts';

    $raw = mp_build_markdown_document([
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

    $page = mp_parse_markdown_string($raw);
    $filename = $page['slug'] . '.md';
    if (function_exists('mp_sync_stream_metadata')) {
        mp_sync_stream_metadata($page, $targetSection, $filename, mp_current_user_id());
    }

    if ($requiresReview && function_exists('mp_mark_draft_pending_review')) {
        mp_mark_draft_pending_review($filename);
    }

    if ($targetStatus === 'published') {
        mp_flash($featuredMedia !== '' && $body === '' ? 'Media posted to the stream.' : 'Posted to the stream.', 'success');
    } else {
        mp_flash($featuredMedia !== '' && $body === '' ? 'Media saved as a draft for review.' : 'Stream post submitted for review.', 'success');
    }
} catch (Throwable $e) {
    mp_flash('Stream post failed. ' . $e->getMessage(), 'error');
}

mp_redirect($returnTo);
