<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/appearance.php';
require_once __DIR__ . '/interactions.php';
require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/link-preview.php';





function bms_render_stream_index(array $pages, bool $includeComposer = false, int $pageNumber = 1, string $context = 'home'): string
{
    $siteNameRaw = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $taglineRaw = (string)bms_setting_or_config('site_tagline', 'A self-hosted microblog stream for owning short-form publishing.');
    $pageNumber = max(1, $pageNumber);
    $perPage = bms_stream_posts_per_page();
    $allStreamPosts = bms_sort_stream_posts(bms_filter_stream_posts($pages));
    $totalPosts = count($allStreamPosts);
    $totalPages = max(1, (int)ceil($totalPosts / max(1, $perPage)));
    if ($pageNumber > $totalPages) {
        $pageNumber = $totalPages;
    }
    $streamPosts = array_slice($allStreamPosts, ($pageNumber - 1) * $perPage, $perPage);
    $isArchive = $context === 'archive';
    $pinnedPosts = (!$isArchive && $pageNumber === 1) ? bms_list_pinned_stream_posts() : [];
    $pinnedSlugs = [];
    foreach ($pinnedPosts as $pinnedPost) {
        $slug = bms_slugify((string)($pinnedPost['slug'] ?? ''));
        if ($slug !== '') {
            $pinnedSlugs[$slug] = true;
        }
    }
    if ($pinnedSlugs) {
        $streamPosts = array_values(array_filter($streamPosts, static function (array $page) use ($pinnedSlugs): bool {
            return !isset($pinnedSlugs[bms_slugify((string)($page['slug'] ?? ''))]);
        }));
    }

    $streamItems = bms_render_stream_cards($streamPosts);
    if ($streamItems === '' && !$pinnedPosts) {
        $streamItems = bms_render_public_theme_template('empty', [
            'include_composer' => $includeComposer,
            'context' => $context,
            'title' => 'No stream posts yet.',
            'message' => $includeComposer ? 'Write your first stream post above.' : 'No stream posts have been published yet.',
        ]);
    }
    $items = bms_render_public_flash_notices() . bms_render_pinned_stream_posts($pinnedPosts) . $streamItems;

    $composer = '';
    if (!$isArchive && $pageNumber === 1) {
        $composer = $includeComposer ? bms_render_stream_composer() : bms_render_stream_composer_mount();
    }
    $pagination = bms_render_stream_pagination($pageNumber, $totalPages, $context);
    $titleText = $isArchive ? 'Stream | ' . $siteNameRaw : $siteNameRaw;
    if ($isArchive && $pageNumber > 1) {
        $titleText = 'Stream, Page ' . $pageNumber . ' | ' . $siteNameRaw;
    }
    $descriptionText = bms_site_identity_plain_text($taglineRaw) !== '' ? bms_site_identity_plain_text($taglineRaw) : 'Short-form updates from ' . $siteNameRaw . '.';
    $canonicalPath = $isArchive ? ($pageNumber > 1 ? 'stream/page/' . $pageNumber . '/' : 'stream/') : '';
    $bodyContext = $isArchive ? 'stream-archive' : 'home stream-home';
    $navCurrentPath = $isArchive ? ($pageNumber > 1 ? 'stream/page/' . $pageNumber . '/' : 'stream/') : '/';

    $view = [
        'title' => $titleText,
        'description' => $descriptionText,
        'canonical' => bms_site_url($canonicalPath),
        'feed_title' => $siteNameRaw . ' Stream Feed',
        'feed_url' => bms_site_url('stream/feed.xml'),
        'style_url' => bms_asset_url('assets/style.css'),
        'script_url' => bms_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => bms_public_theme_stylesheet_links(),
        'favicon_tags' => function_exists('bms_site_favicon_tags') ? bms_site_favicon_tags() : '',
        'theme_script_tags' => bms_public_theme_script_tags(),
        'body_class' => bms_public_theme_class($bodyContext),
        'header_html' => bms_render_public_header($isArchive ? 'stream' : 'home', $totalPosts, $navCurrentPath),
        'footer_html' => bms_render_public_footer($navCurrentPath),
        'composer_html' => $composer,
        'items_html' => $items,
        'pagination_html' => $pagination,
        'total_posts' => $totalPosts,
        'page_number' => $pageNumber,
        'total_pages' => $totalPages,
        'context' => $context,
    ];

    return bms_render_public_theme_template($isArchive ? 'archive' : 'home', $view);
}

function bms_stream_pagination_view_data(int $pageNumber, int $totalPages, string $context = 'archive'): array
{
    $pageNumber = max(1, $pageNumber);
    $totalPages = max(1, $totalPages);
    $hasOlder = $pageNumber < $totalPages;
    $nextPage = $pageNumber + 1;
    $olderPath = $hasOlder ? 'index.php?__bonumark_route=stream&stream_page=' . $nextPage : '';
    $olderAjaxPath = $olderPath;
    $olderCanonicalPath = $hasOlder ? 'stream/page/' . $nextPage . '/' : '';

    return [
        'page_number' => $pageNumber,
        'total_pages' => $totalPages,
        'context' => $context,
        'has_older' => $hasOlder,
        'older_url' => $hasOlder ? bms_url_path($olderPath) : '',
        'older_ajax_url' => $hasOlder ? bms_url_path($olderAjaxPath) : '',
        'older_canonical_url' => $hasOlder ? bms_url_path($olderCanonicalPath) : '',
        'older_label' => 'Load More',
        'complete_label' => 'No more posts',
        'back_to_top_url' => '#site-main',
        'back_to_top_label' => 'Back to top',
        'status_id' => 'stream-load-status',
    ];
}

function bms_render_stream_pagination(int $pageNumber, int $totalPages, string $context = 'archive'): string
{
    if ($totalPages <= 1) {
        return '';
    }

    return bms_render_public_theme_template('pagination', bms_stream_pagination_view_data($pageNumber, $totalPages, $context));
}

function bms_render_stream_composer_mount(): string
{
    $endpoint = htmlspecialchars(bms_admin_url('stream-composer.php'), ENT_QUOTES, 'UTF-8');
    return '<section class="stream-composer-mount" data-stream-composer-mount data-stream-composer-endpoint="' . $endpoint . '" aria-live="polite"></section>';
}


function bms_stream_composer_view_data(?string $returnToOverride = null): ?array
{
    $canPublish = function_exists('bms_current_user_can') && bms_current_user_can('publish_content');
    if (!function_exists('bms_is_logged_in') || !bms_is_logged_in() || !$canPublish || !bms_stream_composer_enabled()) {
        return null;
    }

    $flashes = [];
    if (function_exists('bms_get_flash')) {
        foreach (bms_get_flash() as $flash) {
            $type = preg_replace('/[^a-z0-9_-]+/i', '', (string)($flash['type'] ?? 'info')) ?: 'info';
            $message = trim((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashes[] = [
                    'type' => $type,
                    'message' => $message,
                    'class' => $type === 'success' ? 'is-success' : ($type === 'error' ? 'is-error' : 'is-warning'),
                ];
            }
        }
    }

    $prefillBody = '';
    if (function_exists('bms_share_target_take_pending_payload') && function_exists('bms_share_target_body_from_payload')) {
        $sharedPayload = bms_share_target_take_pending_payload();
        if (!empty($sharedPayload) && !bms_share_target_payload_is_empty($sharedPayload)) {
            $prefillBody = bms_share_target_body_from_payload($sharedPayload);
            $flashes[] = [
                'type' => 'success',
                'message' => 'Shared content is ready. Edit it, then hit Post.',
                'class' => 'is-success',
            ];
        }
    }

    $returnSource = $returnToOverride !== null ? $returnToOverride : (string)($_SERVER['REQUEST_URI'] ?? bms_url_path());

    return [
        'action_url' => bms_admin_url('quick-post.php'),
        'csrf' => function_exists('bms_csrf_token') ? bms_csrf_token() : '',
        'return_to' => bms_stream_safe_return_url($returnSource),
        'accept' => function_exists('bms_allowed_media_accept_attribute') ? bms_allowed_media_accept_attribute() : 'image/*,audio/*,video/*,.pdf,.doc,.docx,.txt',
        'textarea_id' => 'stream_body',
        'file_id' => 'stream_media',
        'help_id' => 'stream-compose-help',
        'preview_id' => 'stream-compose-preview',
        'link_preview_id' => 'stream-link-preview',
        'link_preview_endpoint' => bms_admin_url('link-preview.php'),
        'scheduled_runner_url' => bms_admin_url('scheduled-runner.php'),
        'placeholder' => 'What is happening?',
        'body_value' => $prefillBody,
        'submit_label' => 'Post',
        'busy_label' => 'Posting...',
        'attach_label' => 'Attach media',
        'help_text' => 'You can attach one image, audio, video, or document file.',
        'timezone_label' => function_exists('bms_site_timezone_name') ? bms_site_timezone_name() : 'UTC',
        'flashes' => $flashes,
    ];
}

function bms_render_stream_composer(?string $returnToOverride = null): string
{
    $view = bms_stream_composer_view_data($returnToOverride);
    if ($view === null) {
        return '';
    }

    return bms_render_public_theme_template('composer', $view);
}

function bms_render_public_flash_notices(): string
{
    if (!function_exists('bms_is_logged_in') || !bms_is_logged_in() || !function_exists('bms_get_flash')) {
        return '';
    }

    $items = [];
    foreach (bms_get_flash() as $flash) {
        $message = trim((string)($flash['message'] ?? ''));
        if ($message === '') {
            continue;
        }
        $type = preg_replace('/[^a-z0-9_-]+/i', '', (string)($flash['type'] ?? 'info')) ?: 'info';
        $items[] = '<p class="stream-public-notice is-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    return $items ? '<div class="stream-public-notices" role="status" aria-live="polite">' . implode('', $items) . '</div>' : '';
}

function bms_render_pinned_stream_posts(array $pages): string
{
    if (!$pages) {
        return '';
    }

    $items = bms_render_stream_cards($pages, true);
    if ($items === '') {
        return '';
    }

    return '<section class="stream-pinned-posts" aria-labelledby="stream-pinned-heading">'
        . '<div class="stream-pinned-heading"><span id="stream-pinned-heading" class="stream-pinned-label">Pinned</span></div>'
        . '<div class="stream-pinned-feed">' . $items . '</div>'
        . '</section>';
}

function bms_render_stream_cards(array $pages, bool $pinned = false): string
{
    $items = '';
    foreach ($pages as $index => $page) {
        $items .= bms_render_stream_card($page, false, (int)$index, $pinned);
    }

    if ($items !== '' && function_exists('bms_markdown_prioritize_first_image')) {
        $items = bms_markdown_prioritize_first_image($items);
    }

    return $items;
}

function bms_stream_public_datetime(array $page): array
{
    $status = (string)($page['status'] ?? $page['content_status'] ?? '');
    $scheduledAt = trim((string)($page['scheduled_at'] ?? ($page['front_matter']['scheduled_at'] ?? '')));
    $publishedAt = trim((string)($page['published_at'] ?? ''));
    if (($status === 'published' || $status === 'scheduled') && $scheduledAt !== '') {
        return ['value' => $scheduledAt, 'timezone' => 'utc'];
    }
    if ($status === 'published' && $publishedAt !== '') {
        $isUtc = function_exists('bms_stream_published_at_is_utc') && bms_stream_published_at_is_utc($page);
        return ['value' => $publishedAt, 'timezone' => $isUtc ? 'utc' : 'local'];
    }
    $createdAt = trim((string)($page['stream_created_at'] ?? $page['front_matter']['stream_created_at'] ?? $page['date'] ?? ''));
    return ['value' => $createdAt, 'timezone' => 'local'];
}

function bms_stream_public_datetime_value(array $page): string
{
    $date = bms_stream_public_datetime($page);
    return (string)($date['value'] ?? '');
}

function bms_stream_public_datetime_timestamp(array $page): ?int
{
    $date = bms_stream_public_datetime($page);
    $raw = trim((string)($date['value'] ?? ''));
    if ($raw === '') {
        return null;
    }
    try {
        $timezone = (string)($date['timezone'] ?? '') === 'utc' && function_exists('bms_utc_timezone')
            ? bms_utc_timezone()
            : (function_exists('bms_site_timezone') ? bms_site_timezone() : null);
        $dt = $timezone instanceof DateTimeZone ? new DateTimeImmutable($raw, $timezone) : new DateTimeImmutable($raw);
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        $time = strtotime($raw);
        return $time === false ? null : (int)$time;
    }
}

function bms_stream_public_datetime_iso(array $page): string
{
    $timestamp = bms_stream_public_datetime_timestamp($page);
    if ($timestamp === null) {
        return bms_stream_public_datetime_value($page);
    }
    try {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(bms_site_timezone())->format('c');
    } catch (Throwable $e) {
        return bms_stream_public_datetime_value($page);
    }
}

function bms_stream_display_date(array $page): string
{
    $raw = bms_stream_public_datetime_value($page);
    if ($raw === '') {
        return '';
    }
    $timestamp = bms_stream_public_datetime_timestamp($page);
    if ($timestamp === null) {
        return $raw;
    }
    try {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(bms_site_timezone())->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return $raw;
    }
}

function bms_stream_media_url(array $page): string
{
    $media = trim((string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? ''));
    if ($media === '') {
        return '';
    }

    $media = html_entity_decode(str_replace('\\', '/', $media), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('bms_media_resolve_existing_public_relative_from_url')) {
        $resolved = bms_media_resolve_existing_public_relative_from_url($media);
        if ($resolved !== '') {
            return bms_url_path($resolved);
        }
    }

    if (function_exists('bms_media_public_relative_from_url')) {
        $relative = bms_media_public_relative_from_url($media);
        if ($relative !== '' && is_file(bms_public_path($relative))) {
            return bms_url_path($relative);
        }
    }

    if (preg_match('#^https?://#i', $media) === 1) {
        $clean = bms_clean_url($media);
        return $clean !== '#' ? $clean : '';
    }

    $media = ltrim($media, '/');
    if (!str_starts_with($media, 'media/')) {
        return '';
    }
    if (!is_file(bms_public_path($media))) {
        return '';
    }
    return bms_url_path($media);
}

function bms_stream_media_mime_from_url(string $mediaUrl): string
{
    $path = parse_url($mediaUrl, PHP_URL_PATH);
    $extension = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        default => 'application/octet-stream',
    };
}

function bms_stream_media_label_from_url(string $mediaUrl): string
{
    $path = parse_url($mediaUrl, PHP_URL_PATH);
    $name = basename((string)$path);
    $name = rawurldecode($name);
    return $name !== '' ? $name : 'Attached media';
}


function bms_stream_media_view_data(array $page, array $options = []): ?array
{
    $mediaUrl = bms_stream_media_url($page);
    if ($mediaUrl === '') {
        return null;
    }

    $mime = bms_stream_media_mime_from_url($mediaUrl);
    $type = 'file';
    if (str_starts_with($mime, 'image/')) {
        $type = 'image';
    } elseif (str_starts_with($mime, 'audio/')) {
        $type = 'audio';
    } elseif (str_starts_with($mime, 'video/')) {
        $type = 'video';
    }

    $alt = bms_stream_media_alt($page);
    $imageAttributes = '';
    if ($type === 'image' && function_exists('bms_media_image_attributes')) {
        $imageAttributes = bms_media_image_attributes($mediaUrl, $alt, [
            'loading' => (string)($options['loading'] ?? 'lazy'),
            'decoding' => 'async',
            'fetchpriority' => (string)($options['fetchpriority'] ?? ''),
            'sizes' => '(max-width: 720px) calc(100vw - 2rem), min(100vw - 4rem, 900px)',
            'widths' => [320, 480, 640, 768, 960, 1280, 1600],
        ]);
    }

    return [
        'url' => $mediaUrl,
        'mime' => $mime,
        'type' => $type,
        'alt' => $alt,
        'label' => bms_stream_media_label_from_url($mediaUrl),
        'image_attributes' => $imageAttributes,
        'page' => $page,
    ];
}

function bms_render_stream_media_attachment(array $page, array $options = []): string
{
    $view = bms_stream_media_view_data($page, $options);
    if ($view === null) {
        return '';
    }

    return bms_render_public_theme_template('media', $view);
}

function bms_stream_link_preview_view_data(array $page): ?array
{
    if (!function_exists('bms_link_preview_from_page')) {
        return null;
    }
    $payload = bms_link_preview_from_page($page);
    if (($payload['url'] ?? '') === '') {
        return null;
    }
    if (($payload['title'] ?? '') === '') {
        $payload['title'] = (string)(parse_url((string)$payload['url'], PHP_URL_HOST) ?: $payload['url']);
    }
    $payload['page'] = $page;
    return $payload;
}

function bms_render_stream_link_preview(array $page): string
{
    $view = bms_stream_link_preview_view_data($page);
    if ($view === null) {
        return '';
    }
    return bms_render_public_theme_template('link-preview', $view);
}

function bms_stream_media_alt(array $page): string
{
    $title = trim((string)($page['title'] ?? ''));
    if ($title !== '' && !str_starts_with(strtolower($title), 'stream post:')) {
        return $title;
    }
    $preview = function_exists('bms_stream_preview_text') ? bms_stream_preview_text($page, 80) : '';
    return $preview !== '' && $preview !== 'Media post' ? $preview : 'Stream post media';
}

function bms_stream_edit_url(array $page): string
{
    $filename = basename((string)($page['filename'] ?? ''));
    if ($filename === '') {
        return '';
    }
    $type = ((string)($page['section'] ?? 'published')) === 'drafts' ? 'draft' : 'published';
    return bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($filename));
}

function bms_stream_author_initials(): string
{
    $author = trim((string)bms_setting_or_config('author_name', 'Admin'));
    if ($author === '') {
        return 'B';
    }
    $parts = preg_split('/\s+/', $author) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($initials) >= 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'B';
}

function bms_stream_absolute_url_for_post(array $page): string
{
    $relative = bms_stream_url_for_post($page);
    $basePath = bms_base_path();
    if ($basePath !== '' && str_starts_with($relative, $basePath . '/')) {
        $relative = substr($relative, strlen($basePath) + 1);
    } else {
        $relative = ltrim($relative, '/');
    }
    return bms_site_url($relative);
}


function bms_stream_card_view_data(array $page, bool $single = false, int $index = 0, bool $pinned = false): array
{
    $title = trim((string)($page['title'] ?? ''));
    $dateRaw = bms_stream_public_datetime_value($page);
    $dateLabel = bms_stream_display_date($page);
    $dateIso = bms_stream_public_datetime_iso($page);
    $authorUser = function_exists('bms_author_for_stream_page') ? bms_author_for_stream_page($page) : [];
    $authorName = (string)($authorUser['display_name'] ?? bms_setting_or_config('author_name', 'Admin'));
    $avatarMarkup = function_exists('bms_user_avatar_markup') ? bms_user_avatar_markup($authorUser, '', 96, 96, false) : '<span class="stream-author-avatar stream-author-initials">' . htmlspecialchars(bms_stream_author_initials(), ENT_QUOTES, 'UTF-8') . '</span>';
    $authorProfileUrl = ((int)($authorUser['id'] ?? 0) > 0 && (string)($authorUser['profile_visibility'] ?? 'public') === 'public' && function_exists('bms_public_profile_url_for_user')) ? bms_public_profile_url_for_user($authorUser) : '';
    $previewMode = function_exists('bms_public_preview_mode') && bms_public_preview_mode();
    $pageUrl = $previewMode ? '#preview' : bms_stream_url_for_post($page);
    $body = trim((string)($page['body'] ?? ''));
    $bodyHtml = $body !== '' ? bms_markdown_to_html($body) : '';
    $mediaOptions = [
        'loading' => 'lazy',
        'fetchpriority' => '',
    ];
    $mediaHtml = bms_render_stream_media_attachment($page, $mediaOptions);
    $linkPreviewHtml = bms_render_stream_link_preview($page);
    $slug = (string)($page['slug'] ?? '');
    $likeCount = !$previewMode && function_exists('bms_stream_like_count_for_slug') ? bms_stream_like_count_for_slug($slug) : 0;
    $liked = !$previewMode && function_exists('bms_stream_visitor_liked_slug') ? bms_stream_visitor_liked_slug($slug) : false;
    $likeLabel = function_exists('bms_stream_like_label') ? bms_stream_like_label($likeCount) : ((string)$likeCount . ' likes');
    $commentCount = !$previewMode && function_exists('bms_comment_count_for_slug') ? bms_comment_count_for_slug($slug) : 0;
    $commentLabel = function_exists('bms_comment_label') ? bms_comment_label($commentCount) : ((string)$commentCount . ' Comments');
    $editUrl = '';
    $pinAction = '';
    $pinActionUrl = '';
    $pinCsrf = '';
    $pinReturnTo = '';
    if (function_exists('bms_is_logged_in') && bms_is_logged_in()) {
        $subject = $page;
        $filename = basename((string)($page['filename'] ?? ''));
        if ($filename !== '' && function_exists('bms_content_subject_for_file')) {
            $section = ((string)($page['section'] ?? 'published')) === 'drafts' ? 'drafts' : 'published';
            $subject = bms_content_subject_for_file($section, $filename, $page);
        }
        if (bms_stream_show_edit_links() && function_exists('bms_current_user_can') && bms_current_user_can('edit_content', $subject)) {
            $editUrl = bms_stream_edit_url($page);
        }
        if (!$previewMode && function_exists('bms_current_user_can') && bms_current_user_can('publish_content', $subject) && bms_is_stream_post($page)) {
            $pinAction = bms_is_pinned_stream_post($page) ? 'unpin' : 'pin';
            $pinActionUrl = bms_admin_url('pin.php');
            $pinCsrf = function_exists('bms_csrf_token') ? bms_csrf_token() : '';
            $pinReturnTo = bms_stream_safe_return_url((string)($_SERVER['REQUEST_URI'] ?? bms_stream_home_url()));
        }
    }

    $classes = ['stream-card'];
    $classes[] = $single ? 'stream-single-card' : 'stream-card-clickable';
    if ($mediaHtml !== '') {
        $classes[] = 'has-stream-media';
    }
    if ($bodyHtml === '') {
        $classes[] = 'has-no-text';
    }
    if ($previewMode) {
        $classes[] = 'is-preview-mode';
    }
    if ($pinned) {
        $classes[] = 'stream-card-pinned';
    }

    $previewEditUrl = $previewMode ? bms_stream_edit_url($page) : '';

    return [
        'page' => $page,
        'single' => $single,
        'classes' => implode(' ', $classes),
        'title' => $title,
        'show_title' => false,
        'page_url' => $pageUrl,
        'preview_mode' => $previewMode,
        'body_html' => $bodyHtml,
        'media_html' => $mediaHtml,
        'link_preview_html' => $linkPreviewHtml,
        'author' => $authorUser,
        'author_name' => $authorName,
        'author_profile_url' => $authorProfileUrl,
        'author_handle' => '',
        'avatar_html' => $avatarMarkup,
        'date_label' => $dateLabel,
        'date_iso' => $dateIso,
        'show_dates' => bms_stream_show_dates(),
        'like' => [
            'enabled' => !$previewMode,
            'slug' => $slug,
            'count' => $likeCount,
            'label' => $likeLabel,
            'liked' => $liked,
            'endpoint' => bms_url_path('stream-like.php'),
            'endpoint_alt' => bms_admin_url('stream-like.php'),
            'action_label' => $liked ? 'Post liked.' : 'Like this post.',
        ],
        'comments' => [
            'count' => $commentCount,
            'label' => $commentLabel,
            'url' => $single ? '#comments' : $pageUrl . '#comments',
            'enabled' => !$previewMode && function_exists('bms_comment_count_for_slug') && function_exists('bms_comment_label'),
        ],
        'back_url' => $single ? ($previewMode && $previewEditUrl !== '' ? $previewEditUrl : (function_exists('bms_stream_home_url') ? bms_stream_home_url() : bms_url_path())) : '',
        'back_label' => $previewMode ? 'Back to editor' : 'Back to stream',
        'edit_url' => $previewMode ? '' : $editUrl,
        'pin_action' => $previewMode ? '' : $pinAction,
        'pin_action_url' => $previewMode ? '' : $pinActionUrl,
        'pin_csrf' => $previewMode ? '' : $pinCsrf,
        'pin_return_to' => $previewMode ? '' : $pinReturnTo,
        'pin_filename' => $previewMode ? '' : (string)($page['filename'] ?? ''),
    ];
}

function bms_render_stream_card(array $page, bool $single = false, int $index = 0, bool $pinned = false): string
{
    $html = bms_render_public_theme_template('card', bms_stream_card_view_data($page, $single, $index, $pinned));
    if ($single && $html !== '' && function_exists('bms_markdown_prioritize_first_image')) {
        $html = bms_markdown_prioritize_first_image($html);
    }
    return $html;
}

function bms_stream_seo_title(array $page): string
{
    $seoTitle = trim((string)($page['seo_title'] ?? $page['front_matter']['seo_title'] ?? ''));
    if ($seoTitle !== '') {
        return bms_stream_limit_text($seoTitle, 65, '…');
    }

    return bms_stream_generated_seo_title(
        (string)($page['body'] ?? ''),
        bms_stream_public_datetime_value($page),
        (string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? ''),
        []
    );
}

function bms_stream_seo_description(array $page, int $limit = 160): string
{
    $description = trim((string)($page['description'] ?? ''));
    if ($description !== '') {
        return bms_plain_excerpt($description, $limit);
    }

    return bms_stream_generated_description(
        (string)($page['body'] ?? ''),
        bms_stream_public_datetime_value($page),
        (string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? ''),
        $limit
    );
}

function bms_plain_excerpt(string $text, int $limit = 160): string
{
    $text = bms_stream_clean_text_for_seo($text);
    if ($text === '') {
        return '';
    }
    return bms_stream_limit_text($text, $limit, '…');
}

function bms_stream_robots_directive(array $page): string
{
    $explicit = strtolower(trim((string)($page['robots'] ?? $page['front_matter']['robots'] ?? '')));
    if ($explicit !== '') {
        return preg_replace('/[^a-z,\s-]+/', '', $explicit) ?? $explicit;
    }

    $policy = function_exists('bms_stream_index_policy') ? bms_stream_index_policy() : 'smart';
    if ($policy === 'all') {
        return '';
    }
    if ($policy === 'noindex') {
        return 'noindex,follow';
    }

    $bodyText = bms_stream_clean_text_for_seo((string)($page['body'] ?? ''));
    $hasMedia = trim((string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? '')) !== '';
    if ($hasMedia && $bodyText === '') {
        return 'noindex,follow';
    }

    return '';
}

function bms_stream_media_absolute_url(array $page): string
{
    $relative = bms_stream_media_url($page);
    if ($relative === '') {
        return '';
    }
    $basePath = bms_base_path();
    if ($basePath !== '' && str_starts_with($relative, $basePath . '/')) {
        $relative = substr($relative, strlen($basePath) + 1);
    } else {
        $relative = ltrim($relative, '/');
    }
    return bms_site_url($relative);
}

function bms_render_stream_single(array $page): string
{
    $siteNameRaw = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $titleRaw = bms_stream_seo_title($page);
    $descriptionRaw = bms_stream_seo_description($page);
    $streamSlug = bms_slugify((string)($page['slug'] ?? ''));
    $canonical = bms_site_url($streamSlug !== '' ? 'stream/' . $streamSlug . '/' : '');
    $publishedRaw = bms_stream_public_datetime_value($page);
    $publishedIso = bms_stream_public_datetime_iso($page);
    $publishedMeta = '';
    if ($publishedRaw !== '') {
        $publishedMeta = '<meta property="article:published_time" content="' . htmlspecialchars($publishedIso, ENT_QUOTES, 'UTF-8') . '">';
    }
    $imageUrl = bms_stream_media_absolute_url($page);
    $imageMime = $imageUrl !== '' ? bms_stream_media_mime_from_url((string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? '')) : '';
    $imageMeta = ($imageUrl !== '' && str_starts_with($imageMime, 'image/')) ? '<meta property="og:image" content="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '">' : '';
    $robotsDirective = bms_stream_robots_directive($page);
    $robotsMeta = $robotsDirective !== '' ? '<meta name="robots" content="' . htmlspecialchars($robotsDirective, ENT_QUOTES, 'UTF-8') . '">' : '';

    $previewMode = function_exists('bms_public_preview_mode') && bms_public_preview_mode();

    $view = [
        'site_name' => $siteNameRaw,
        'title' => $titleRaw,
        'seo_title_primary' => function_exists('bms_seo_strip_site_title') ? bms_seo_strip_site_title($titleRaw, $siteNameRaw) : $titleRaw,
        'description' => $descriptionRaw,
        'canonical' => $canonical,
        'published_meta' => $publishedMeta,
        'image_meta' => $imageMeta,
        'robots_meta' => $robotsMeta,
        'style_url' => bms_asset_url('assets/style.css'),
        'script_url' => bms_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => bms_public_theme_stylesheet_links(),
        'favicon_tags' => function_exists('bms_site_favicon_tags') ? bms_site_favicon_tags() : '',
        'theme_script_tags' => bms_public_theme_script_tags(),
        'body_class' => bms_public_theme_class($previewMode ? 'stream-preview' : 'stream-single'),
        'preview_mode' => $previewMode,
        'header_html' => bms_render_public_header($previewMode ? 'preview' : 'stream-single', null, $previewMode ? null : bms_stream_relative_directory_for_post($page) . '/'),
        'footer_html' => bms_render_public_footer($previewMode ? null : bms_stream_relative_directory_for_post($page) . '/'),
        'card_html' => bms_render_stream_card($page, true),
        'comments_html' => function_exists('bms_render_comments_mount') ? bms_render_comments_mount($page) : '',
        'page' => $page,
    ];

    return bms_render_public_theme_template('single', $view);
}

function bms_render_public_content_page(array $page): string
{
    if (bms_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'stream')) === 'page') {
        require_once __DIR__ . '/pages.php';
        return bms_render_page($page);
    }
    return bms_render_stream_single($page);
}




function bms_filter_stream_posts_for_search(array $pages, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $needle = function_exists('mb_strtolower') ? mb_strtolower($query) : strtolower($query);
    $matches = [];
    foreach (bms_sort_stream_posts(bms_filter_stream_posts($pages)) as $page) {
        $haystack = implode(' ', [
            (string)($page['title'] ?? ''),
            (string)($page['slug'] ?? ''),
            (string)($page['description'] ?? ''),
            (string)($page['body'] ?? ''),
        ]);
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack) : strtolower($haystack);
        if ($needle !== '' && str_contains($haystack, $needle)) {
            $matches[] = $page;
        }
    }
    return $matches;
}

function bms_render_stream_search(string $query = ''): string
{
    $siteNameRaw = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $query = trim($query);
    $matches = bms_filter_stream_posts_for_search(bms_list_content_records('published'), $query);
    $items = $query !== '' ? bms_render_stream_cards($matches) : '';
    if ($query !== '' && $items === '') {
        $items = bms_render_public_theme_template('empty', [
            'include_composer' => false,
            'context' => 'search',
            'title' => 'No matching stream posts.',
            'message' => 'Try a different search term.',
        ]);
    }

    $titleText = $query !== '' ? 'Search results for ' . $query . ' | ' . $siteNameRaw : 'Search | ' . $siteNameRaw;
    $view = [
        'site_name' => $siteNameRaw,
        'title' => $titleText,
        'description' => 'Search stream posts from ' . $siteNameRaw . '.',
        'canonical' => bms_site_url('search.php' . ($query !== '' ? '?q=' . rawurlencode($query) : '')),
        'style_url' => bms_asset_url('assets/style.css'),
        'script_url' => bms_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => bms_public_theme_stylesheet_links(),
        'favicon_tags' => function_exists('bms_site_favicon_tags') ? bms_site_favicon_tags() : '',
        'theme_script_tags' => bms_public_theme_script_tags(),
        'body_class' => bms_public_theme_class('search-page'),
        'header_html' => bms_render_public_header('search', null, 'search.php'),
        'footer_html' => bms_render_public_footer('search.php'),
        'query' => $query,
        'items_html' => $items,
        'results' => $matches,
        'result_count' => count($matches),
        'search_url' => bms_url_path('search.php'),
    ];

    return bms_render_public_theme_template('search', $view);
}

function bms_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function bms_rss_date(string $raw): string
{
    $time = strtotime($raw);
    return date(DATE_RSS, $time !== false ? $time : time());
}

function bms_cdata(string $value): string
{
    return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
}

function bms_render_rss_feed(array $pages, string $feedType = 'stream'): string
{
    $siteName = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $tagline = (string)bms_setting_or_config('site_tagline', 'A self-hosted microblog stream for owning short-form publishing.');
    $feedTitle = $siteName . ' Stream';
    $feedDescription = bms_site_identity_plain_text($tagline) !== '' ? bms_site_identity_plain_text($tagline) : 'Short-form updates from ' . $siteName . '.';
    $feedLink = bms_site_url('stream/');
    $selfLink = $feedType === 'root' ? bms_site_url('feed.xml') : bms_site_url('stream/feed.xml');
    $items = bms_sort_stream_posts(bms_filter_stream_posts($pages));
    $items = array_slice($items, 0, max(20, min(50, bms_stream_posts_per_page())));

    $xmlItems = '';
    foreach ($items as $page) {
        $title = bms_stream_seo_title($page);
        if ($title === '') {
            $title = 'Stream Post';
        }
        $link = bms_site_url(trim(bms_stream_relative_directory_for_post($page), '/') . '/');
        $rawDate = (string)($page['stream_created_at'] ?? $page['date'] ?? '');
        $description = bms_stream_seo_description($page);
        $bodyHtml = bms_markdown_to_html((string)($page['body'] ?? ''));
        if ($bodyHtml === '') {
            $bodyHtml = '<p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $xmlItems .= '    <item>' . "\n"
            . '      <title>' . bms_xml_escape($title) . '</title>' . "\n"
            . '      <link>' . bms_xml_escape($link) . '</link>' . "\n"
            . '      <guid isPermaLink="true">' . bms_xml_escape($link) . '</guid>' . "\n"
            . '      <pubDate>' . bms_rss_date($rawDate) . '</pubDate>' . "\n"
            . '      <description>' . bms_cdata($description) . '</description>' . "\n"
            . '      <content:encoded>' . bms_cdata($bodyHtml) . '</content:encoded>' . "\n"
            . '    </item>' . "\n";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
        . '  <channel>' . "\n"
        . '    <title>' . bms_xml_escape($feedTitle) . '</title>' . "\n"
        . '    <link>' . bms_xml_escape($feedLink) . '</link>' . "\n"
        . '    <description>' . bms_xml_escape($feedDescription) . '</description>' . "\n"
        . '    <language>en-us</language>' . "\n"
        . '    <lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>' . "\n"
        . '    <atom:link href="' . bms_xml_escape($selfLink) . '" rel="self" type="application/rss+xml" />' . "\n"
        . $xmlItems
        . '  </channel>' . "\n"
        . '</rss>' . "\n";
}


function bms_clean_static_export_stream_output(array $streamPosts, ?string $targetRoot = null): void
{
    $streamRoot = bms_static_site_export_path('stream', $targetRoot);
    if (!is_dir($streamRoot)) {
        return;
    }

    $currentSlugs = [];
    foreach ($streamPosts as $post) {
        $slug = bms_slugify((string)($post['slug'] ?? ''));
        if ($slug !== '') {
            $currentSlugs[$slug] = true;
        }
    }

    foreach (array_diff(scandir($streamRoot) ?: [], ['.', '..']) as $item) {
        $path = $streamRoot . '/' . $item;
        if (!is_dir($path) || $item === 'page') {
            continue;
        }
        if (!isset($currentSlugs[$item])) {
            bms_delete_directory($path);
        }
    }
}

function bms_generate_static_stream_archive(?array $pages = null, ?string $targetRoot = null): void
{
    $pages = $pages ?? bms_list_content_records('published');
    $streamPosts = bms_sort_stream_posts(bms_filter_stream_posts($pages));
    $perPage = bms_stream_posts_per_page();
    $totalPages = max(1, (int)ceil(count($streamPosts) / max(1, $perPage)));

    bms_delete_directory(bms_static_site_export_path('stream/page', $targetRoot));
    bms_clean_static_export_stream_output($streamPosts, $targetRoot);
    bms_write_file(bms_static_site_export_path('stream/index.html', $targetRoot), bms_render_stream_index($pages, false, 1, 'archive'));

    for ($page = 2; $page <= $totalPages; $page++) {
        bms_write_file(bms_static_site_export_path('stream/page/' . $page . '/index.html', $targetRoot), bms_render_stream_index($pages, false, $page, 'archive'));
    }
}

function bms_generate_static_feeds(?array $pages = null, ?string $targetRoot = null): void
{
    $pages = $pages ?? bms_list_content_records('published');
    bms_write_file(bms_static_site_export_path('stream/feed.xml', $targetRoot), bms_render_rss_feed($pages, 'stream'));
    bms_write_file(bms_static_site_export_path('feed.xml', $targetRoot), bms_render_rss_feed($pages, 'root'));
}

function bms_generate_static_discovery_files(?array $streamPosts = null, ?array $pages = null, ?string $targetRoot = null): void
{
    if (!is_file(__DIR__ . '/sitemap.php')) {
        return;
    }
    if (is_file(__DIR__ . '/pages.php')) {
        require_once __DIR__ . '/pages.php';
    }
    require_once __DIR__ . '/sitemap.php';
    if (function_exists('bms_generate_static_sitemap')) {
        bms_generate_static_sitemap($streamPosts, $pages, $targetRoot);
    }
}

function bms_unpublish_file(string $publishedFilename): array
{
    $filename = basename($publishedFilename);
    $page = function_exists('bms_find_database_content_by_markdown_path') ? bms_find_database_content_by_markdown_path('published', $filename) : null;
    if (!$page) {
        throw new RuntimeException('Published stream post not found.');
    }

    $authorId = function_exists('bms_content_author_id_for_file') ? bms_content_author_id_for_file('published', $filename) : null;
    if ($authorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $authorId = (int)$page['author_id'];
    }
    if (function_exists('bms_record_revision_from_page')) {
        bms_record_revision_from_page($page, 'published', $filename, $authorId);
    }
    $draft = function_exists('bms_database_content_page_for_status') ? bms_database_content_page_for_status($page, 'draft', 'stream') : $page;
    $draftFilename = function_exists('bms_database_content_filename_for_page') ? bms_database_content_filename_for_page($draft) : $filename;
    if (function_exists('bms_find_database_content_by_slug_status') && bms_find_database_content_by_slug_status((string)($draft['slug'] ?? ''), 'draft', 'stream')) {
        throw new RuntimeException('A draft with this slug already exists. Delete or rename the draft first.');
    }

    if (function_exists('bms_delete_post_metadata_by_filename')) {
        bms_delete_post_metadata_by_filename('published', $filename);
    }
    if (function_exists('bms_sync_stream_metadata')) {
        bms_sync_stream_metadata($draft, 'drafts', $draftFilename, $authorId);
    }

    return $draft + ['filename' => $draftFilename];
}

function bms_delete_content_file(string $type, string $filename): array
{
    $section = $type === 'published' ? 'published' : ($type === 'scheduled' ? 'scheduled' : 'drafts');
    $originalStatus = $section === 'published' ? 'published' : ($section === 'scheduled' ? 'scheduled' : 'draft');
    $filename = basename($filename);
    $page = function_exists('bms_find_database_content_by_markdown_path') ? bms_find_database_content_by_markdown_path($section, $filename) : null;
    if (!$page) {
        throw new RuntimeException('Content record not found.');
    }

    $trashFilename = date('Ymd-His') . '-' . $originalStatus . '-' . $filename;
    if (function_exists('bms_record_trashed_content')) {
        bms_record_trashed_content($page, $originalStatus, $filename, $trashFilename);
    }
    if (function_exists('bms_delete_post_metadata_by_filename')) {
        bms_delete_post_metadata_by_filename($section, $filename);
    }
    return $page;
}

function bms_publish_file(string $draftFilename): array
{
    $filename = basename($draftFilename);
    $sourceSection = 'drafts';
    $page = function_exists('bms_find_database_content_by_markdown_path') ? bms_find_database_content_by_markdown_path('drafts', $filename) : null;
    if (!$page && function_exists('bms_find_database_content_by_markdown_path')) {
        $page = bms_find_database_content_by_markdown_path('scheduled', $filename);
        if ($page) {
            $sourceSection = 'scheduled';
        }
    }
    if (!$page) {
        throw new RuntimeException('Draft or scheduled post not found.');
    }

    $authorId = function_exists('bms_content_author_id_for_file') ? bms_content_author_id_for_file($sourceSection, $filename) : null;
    if ($authorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $authorId = (int)$page['author_id'];
    }
    $published = function_exists('bms_database_content_page_for_status') ? bms_database_content_page_for_status($page, 'published', 'stream') : $page;
    $publishedFilename = function_exists('bms_database_content_filename_for_page') ? bms_database_content_filename_for_page($published) : basename((string)($published['slug'] ?? pathinfo($filename, PATHINFO_FILENAME)) . '.md');
    $existingPublished = function_exists('bms_find_database_content_by_slug_status') ? bms_find_database_content_by_slug_status((string)($published['slug'] ?? ''), 'published', 'stream') : null;
    if ($existingPublished) {
        throw new RuntimeException('A published stream post already uses this slug.');
    }

    if (function_exists('bms_delete_post_metadata_by_filename')) {
        bms_delete_post_metadata_by_filename($sourceSection, $filename);
    }
    if (function_exists('bms_sync_stream_metadata')) {
        bms_sync_stream_metadata($published, 'published', $publishedFilename, $authorId);
    }

    return $published + ['filename' => $publishedFilename];
}

function bms_generate_static_site_index(?array $pages = null, ?string $targetRoot = null): void
{
    $pages = $pages ?? bms_list_content_records('published');
    bms_write_file(bms_static_site_export_path('index.html', $targetRoot), bms_render_stream_index($pages));
    bms_generate_static_stream_archive($pages, $targetRoot);
    bms_generate_static_feeds($pages, $targetRoot);
    bms_generate_static_discovery_files($pages, null, $targetRoot);
    bms_delete_directory(bms_static_site_export_path('categories', $targetRoot));
    bms_delete_directory(bms_static_site_export_path('tags', $targetRoot));
}


function bms_generate_static_site_export(?string $targetRoot = null): int
{
    $targetRoot = $targetRoot !== null && trim($targetRoot) !== '' ? $targetRoot : bms_static_site_export_root('current');
    bms_delete_directory($targetRoot);
    $pages = bms_list_content_records('published');
    $count = 0;
    foreach ($pages as $page) {
        $pageIndexPath = bms_stream_export_index_path_for_post($page, $targetRoot);
        bms_write_file($pageIndexPath, bms_render_public_content_page($page));
        $count++;
    }
    bms_generate_static_site_index($pages, $targetRoot);
    if (is_file(__DIR__ . '/pages.php')) {
        require_once __DIR__ . '/pages.php';
        if (function_exists('bms_generate_static_page_exports')) {
            $count += bms_generate_static_page_exports($targetRoot);
        }
    }
    return $count;
}
