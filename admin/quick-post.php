<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/../_bonumark_stream/app/link-preview.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/../_bonumark_stream/app/places.php';
require_once __DIR__ . '/../_bonumark_stream/app/composer.php';
bms_require_login();
bms_require_capability('edit_content');

$returnTo = bms_stream_safe_return_url((string)($_POST['return_to'] ?? ($_SERVER['HTTP_REFERER'] ?? bms_url_path())));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bms_redirect($returnTo);
}

bms_verify_csrf();

$hiddenSubmitAction = strtolower(trim((string)($_POST['stream_submit_action'] ?? 'publish')));
$buttonSubmitAction = strtolower(trim((string)($_POST['stream_submit_action_button'] ?? '')));
$submitAction = $hiddenSubmitAction === 'schedule' ? 'schedule' : ($buttonSubmitAction !== '' ? $buttonSubmitAction : $hiddenSubmitAction);
if (!in_array($submitAction, ['publish', 'schedule', 'draft', 'continue'], true)) {
    $submitAction = 'publish';
}
if (in_array($submitAction, ['publish', 'schedule'], true)) {
    bms_require_capability('publish_content');
}

$targetStatus = $submitAction === 'schedule' ? 'scheduled' : (in_array($submitAction, ['draft', 'continue'], true) ? 'draft' : 'published');
$targetSection = $targetStatus === 'scheduled' ? 'scheduled' : ($targetStatus === 'draft' ? 'drafts' : 'published');
$replyObjectUri = trim((string)($_POST['activitypub_reply_object_uri'] ?? ''));
$featuredMedia = '';
$mediaGallery = [];
$uploadedMedia = [];
$requestKey = (string)($_POST['composer_request_key'] ?? '');
$requestStarted = false;
$transactionStarted = false;
$commitAttempted = false;

try {
    $previousResult = bms_composer_begin_request($requestKey);
    if ($previousResult !== null) {
        bms_composer_response(true, $previousResult, 'This submission was already saved.');
    }
    $requestStarted = true;
    $body = trim((string)($_POST['stream_body'] ?? ''));
    $bodyLength = bms_text_length($body);
    if ($bodyLength > 5000) {
        throw new InvalidArgumentException('Stream post is too long. Keep it under 5,000 characters.');
    }

    $advancedFields = [
        'stream_title' => [180, 'Internal title'],
        'stream_slug' => [180, 'Slug'],
        'stream_description' => [300, 'Meta description'],
        'stream_seo_title' => [180, 'Search title'],
    ];
    foreach ($advancedFields as $field => [$limit, $label]) {
        $value = trim((string)($_POST[$field] ?? ''));
        $length = bms_text_length($value);
        if ($length > $limit) {
            throw new InvalidArgumentException($label . ' is too long. Keep it under ' . $limit . ' characters.');
        }
    }
    $robots = trim((string)($_POST['stream_robots'] ?? ''));
    if (!in_array($robots, ['', 'index,follow', 'noindex,follow'], true)) {
        $robots = '';
    }


    if (preg_match('//u', $body) !== 1) { throw new InvalidArgumentException('Post text must be valid UTF-8.'); }
    $locationFields = function_exists('bms_place_request_fields') ? bms_place_request_fields('') : [];
    $uploadField = $_FILES['stream_media'] ?? ($_FILES['stream_image'] ?? null);
    $files = function_exists('bms_media_upload_files') ? bms_media_upload_files($uploadField) : (is_array($uploadField) ? [$uploadField] : []);
    if (count($files) > 4) {
        throw new RuntimeException('Attach no more than four photos to one stream post.');
    }

    $validatedFiles = [];
    foreach ($files as $file) {
        $error = (int)($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('One of the selected media files is too large for this server or the configured upload limit.');
        }
        $validatedFiles[] = bms_media_validate_upload($file);
    }
    if (count($validatedFiles) > 1) {
        foreach ($validatedFiles as $valid) {
            if (!str_starts_with((string)($valid['mime'] ?? ''), 'image/')) {
                throw new RuntimeException('Multiple attachments must all be image files. Audio, video, and documents remain single attachments.');
            }
        }
    }

    bms_db()->beginTransaction();
    $transactionStarted = true;
    foreach ($files as $file) {
        $media = bms_media_upload($file, '', '', ['image_only' => count($files) > 1]);
        $uploadedMedia[] = $media;
        $publicPath = trim((string)($media['public_path'] ?? ''));
        if ($publicPath !== '') {
            $mediaGallery[] = $publicPath;
        }
    }
    $mediaGallery = bms_normalize_media_gallery($mediaGallery);
    $featuredMedia = $mediaGallery ? (string)$mediaGallery[0] : '';

    if ($body === '' && $featuredMedia === '' && empty($locationFields['location_place_id'])) {
        throw new RuntimeException('Write something, attach media, or add a location before saving.');
    }

    $now = date('Y-m-d H:i:s');
    $scheduledAtUtc = null;
    if ($targetStatus === 'scheduled') {
        $scheduledAtUtc = bms_scheduled_input_to_utc((string)($_POST['stream_scheduled_at'] ?? ''));
    }

    $fields = [
        'title' => trim((string)($_POST['stream_title'] ?? '')),
        'slug' => trim((string)($_POST['stream_slug'] ?? '')),
        'status' => $targetStatus,
        'content_type' => 'stream',
        'date' => date('Y-m-d'),
        'description' => trim((string)($_POST['stream_description'] ?? '')),
        'category' => 'Stream',
        'tags' => [],
        'featured_media' => $featuredMedia,
        'media_gallery' => $mediaGallery,
        'stream_created_at' => $now,
        'scheduled_at' => $scheduledAtUtc ?? '',
        'seo_title' => trim((string)($_POST['stream_seo_title'] ?? '')),
        'robots' => $robots,
    ];
    if (function_exists('bms_link_preview_payload_from_request')) {
        $fields = array_merge($fields, bms_link_preview_front_matter_fields(bms_link_preview_payload_from_request()));
    }
    $fields = array_merge($fields, $locationFields);
    $fields = bms_stream_prepare_metadata_fields($fields, $body);

    $raw = bms_build_markdown_document($fields, $body);
    $page = bms_parse_markdown_string($raw);
    $slug = bms_slugify((string)($page['slug'] ?? ''));
    if ($slug === '') {
        throw new RuntimeException('Bonumark Stream could not create a valid post URL. Add more post text or enter a slug under Advanced.');
    }

    if (function_exists('bms_find_database_content_by_slug_status')) {
        foreach (['draft', 'published', 'scheduled'] as $existingStatus) {
            if (bms_find_database_content_by_slug_status($slug, $existingStatus, 'stream')) {
                throw new RuntimeException('Another stream post already uses this slug. Change the Advanced slug or edit the existing post.');
            }
        }
    }

    $filename = $slug . '.md';
    if ($replyObjectUri !== '') {
        bms_activitypub_save_owner_reply_post($page, $targetSection, $filename, bms_current_user_id(), $replyObjectUri);
    } elseif ($targetStatus === 'scheduled' && function_exists('bms_schedule_post_page')) {
        bms_schedule_post_page($page, 'scheduled', $filename, bms_current_user_id(), (string)$scheduledAtUtc);
    } elseif (function_exists('bms_sync_stream_metadata')) {
        bms_sync_stream_metadata($page, $targetSection, $filename, bms_current_user_id());
    }

    $commitAttempted = true;
    bms_db()->commit();
    $transactionStarted = false;
    $successReturn = $submitAction === 'continue' ? bms_admin_url('edit.php?type=draft&file=' . rawurlencode($filename)) : $returnTo;
    bms_composer_complete_request($requestKey, $successReturn);

    if ($submitAction === 'continue') {
        bms_flash(($replyObjectUri !== '' ? 'Reply draft created.' : 'Draft created.') . ' Continue in the full editor for formatting, media-library insertion, preview, revisions, or longer writing.', 'success');
        bms_composer_response(true, $successReturn);
    }

    if ($targetStatus === 'scheduled') {
        bms_flash(($replyObjectUri !== '' ? 'Reply' : 'Stream post') . ' scheduled for ' . bms_format_scheduled_datetime((string)$scheduledAtUtc) . '.', 'success');
    } elseif ($targetStatus === 'draft') {
        bms_flash(($replyObjectUri !== '' ? 'Reply draft saved.' : 'Draft saved.') . ' Open Drafts in Admin when you are ready to continue editing or publish it.', 'success');
    } elseif (count($mediaGallery) > 1 && $body === '') {
        bms_flash(count($mediaGallery) . ' photos posted to the stream.', 'success');
    } else {
        bms_flash($replyObjectUri !== '' ? 'Reply posted.' : ($featuredMedia !== '' && $body === '' ? 'Media posted to the stream.' : 'Posted to the stream.'), 'success');
    }
} catch (Throwable $e) {
    $rolledBack = false;
    if ($transactionStarted && bms_db()->inTransaction()) {
        bms_db()->rollBack();
        $rolledBack = true;
    }
    $safeToRetry = $requestStarted && (!$commitAttempted || $rolledBack);
    bms_composer_preserve_request($requestKey, $_POST, $safeToRetry);
    foreach (array_reverse($uploadedMedia) as $uploadedItem) {
        if ($safeToRetry && function_exists('bms_media_discard_new_upload')) {
            bms_media_discard_new_upload($uploadedItem);
        }
    }
    bms_log_admin_exception('quick-post', $e);

    $message = ($e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !$e instanceof PDOException)) ? trim($e->getMessage()) : '';
    if (!$safeToRetry && $requestStarted) {
        $message = 'The save result could not be confirmed. Check your Stream and Drafts before starting another post. Your text is preserved.';
    }
    bms_composer_response(false, $returnTo, $message !== '' ? $message : 'The post could not be saved. Your text is preserved. Please try again.');
}

bms_composer_response(true, $returnTo);
