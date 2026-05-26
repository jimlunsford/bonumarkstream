<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/appearance.php';
require_once __DIR__ . '/interactions.php';
require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/link-preview.php';





function mp_render_stream_index(array $pages, bool $includeComposer = false, int $pageNumber = 1, string $context = 'home'): string
{
    $siteNameRaw = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
    $taglineRaw = (string)mp_setting_or_config('site_tagline', 'A self-hosted microblog stream for owning short-form publishing.');
    $pageNumber = max(1, $pageNumber);
    $perPage = mp_stream_posts_per_page();
    $allStreamPosts = mp_sort_stream_posts(mp_filter_stream_posts($pages));
    $totalPosts = count($allStreamPosts);
    $totalPages = max(1, (int)ceil($totalPosts / max(1, $perPage)));
    if ($pageNumber > $totalPages) {
        $pageNumber = $totalPages;
    }
    $streamPosts = array_slice($allStreamPosts, ($pageNumber - 1) * $perPage, $perPage);
    $items = mp_render_stream_cards($streamPosts);
    if ($items === '') {
        $items = mp_render_public_theme_template('empty', [
            'include_composer' => $includeComposer,
            'context' => $context,
            'title' => 'No stream posts yet.',
            'message' => $includeComposer ? 'Write your first stream post above.' : 'No stream posts have been published yet.',
        ]);
    }

    $isArchive = $context === 'archive';
    $composer = '';
    if (!$isArchive && $pageNumber === 1) {
        $composer = $includeComposer ? mp_render_stream_composer() : mp_render_stream_composer_mount();
    }
    $pagination = mp_render_stream_pagination($pageNumber, $totalPages, $context);
    $titleText = $isArchive ? 'Stream | ' . $siteNameRaw : $siteNameRaw;
    if ($isArchive && $pageNumber > 1) {
        $titleText = 'Stream, Page ' . $pageNumber . ' | ' . $siteNameRaw;
    }
    $descriptionText = mp_site_identity_plain_text($taglineRaw) !== '' ? mp_site_identity_plain_text($taglineRaw) : 'Short-form updates from ' . $siteNameRaw . '.';
    $canonicalPath = $isArchive ? ($pageNumber > 1 ? 'stream/page/' . $pageNumber . '/' : 'stream/') : '';
    $bodyContext = $isArchive ? 'stream-archive' : 'home stream-home';
    $navCurrentPath = $isArchive ? ($pageNumber > 1 ? 'stream/page/' . $pageNumber . '/' : 'stream/') : '/';

    $view = [
        'title' => $titleText,
        'description' => $descriptionText,
        'canonical' => mp_site_url($canonicalPath),
        'feed_title' => $siteNameRaw . ' Stream Feed',
        'feed_url' => mp_site_url('stream/feed.xml'),
        'style_url' => mp_asset_url('assets/style.css'),
        'script_url' => mp_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => mp_public_theme_stylesheet_links(),
        'favicon_tags' => function_exists('mp_site_favicon_tags') ? mp_site_favicon_tags() : '',
        'theme_script_tags' => mp_public_theme_script_tags(),
        'body_class' => mp_public_theme_class($bodyContext),
        'header_html' => mp_render_public_header($isArchive ? 'stream' : 'home', $totalPosts, $navCurrentPath),
        'footer_html' => mp_render_public_footer($navCurrentPath),
        'composer_html' => $composer,
        'items_html' => $items,
        'pagination_html' => $pagination,
        'total_posts' => $totalPosts,
        'page_number' => $pageNumber,
        'total_pages' => $totalPages,
        'context' => $context,
    ];

    return mp_render_public_theme_template($isArchive ? 'archive' : 'home', $view);
}

function mp_stream_pagination_view_data(int $pageNumber, int $totalPages, string $context = 'archive'): array
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
        'older_url' => $hasOlder ? mp_url_path($olderPath) : '',
        'older_ajax_url' => $hasOlder ? mp_url_path($olderAjaxPath) : '',
        'older_canonical_url' => $hasOlder ? mp_url_path($olderCanonicalPath) : '',
        'older_label' => 'Load More',
        'complete_label' => 'No more posts',
        'back_to_top_url' => '#site-main',
        'back_to_top_label' => 'Back to top',
        'status_id' => 'stream-load-status',
    ];
}

function mp_render_stream_pagination(int $pageNumber, int $totalPages, string $context = 'archive'): string
{
    if ($totalPages <= 1) {
        return '';
    }

    return mp_render_public_theme_template('pagination', mp_stream_pagination_view_data($pageNumber, $totalPages, $context));
}

function mp_render_stream_composer_mount(): string
{
    $endpoint = htmlspecialchars(mp_admin_url('stream-composer.php'), ENT_QUOTES, 'UTF-8');
    return '<section class="stream-composer-mount" data-stream-composer-mount data-stream-composer-endpoint="' . $endpoint . '" aria-live="polite"></section>';
}


function mp_stream_composer_view_data(?string $returnToOverride = null): ?array
{
    $canPublish = function_exists('mp_current_user_can') && mp_current_user_can('publish_content');
    $requiresReview = function_exists('mp_current_user_requires_post_review') && mp_current_user_requires_post_review();
    if (!function_exists('mp_is_logged_in') || !mp_is_logged_in() || (!$canPublish && !$requiresReview) || !mp_stream_composer_enabled()) {
        return null;
    }

    $flashes = [];
    if (function_exists('mp_get_flash')) {
        foreach (mp_get_flash() as $flash) {
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

    $returnSource = $returnToOverride !== null ? $returnToOverride : (string)($_SERVER['REQUEST_URI'] ?? mp_url_path());

    return [
        'action_url' => mp_admin_url('quick-post.php'),
        'csrf' => function_exists('mp_csrf_token') ? mp_csrf_token() : '',
        'return_to' => mp_stream_safe_return_url($returnSource),
        'accept' => function_exists('mp_allowed_media_accept_attribute') ? mp_allowed_media_accept_attribute() : 'image/*,audio/*,video/*,.pdf,.doc,.docx,.txt',
        'textarea_id' => 'stream_body',
        'file_id' => 'stream_media',
        'help_id' => 'stream-compose-help',
        'preview_id' => 'stream-compose-preview',
        'link_preview_id' => 'stream-link-preview',
        'link_preview_endpoint' => mp_admin_url('link-preview.php'),
        'placeholder' => 'What is happening?',
        'submit_label' => $requiresReview ? 'Submit for Review' : 'Post',
        'busy_label' => $requiresReview ? 'Submitting...' : 'Posting...',
        'attach_label' => 'Attach media',
        'help_text' => $requiresReview ? 'You can attach one image, audio, video, or document file. Your post will be saved as a draft for review.' : 'You can attach one image, audio, video, or document file.',
        'flashes' => $flashes,
    ];
}

function mp_render_stream_composer(?string $returnToOverride = null): string
{
    $view = mp_stream_composer_view_data($returnToOverride);
    if ($view === null) {
        return '';
    }

    return mp_render_public_theme_template('composer', $view);
}

function mp_render_stream_cards(array $pages): string
{
    $items = '';
    foreach ($pages as $index => $page) {
        $items .= mp_render_stream_card($page, false, (int)$index);
    }

    if ($items !== '' && function_exists('mp_markdown_prioritize_first_image')) {
        $items = mp_markdown_prioritize_first_image($items);
    }

    return $items;
}

function mp_stream_display_date(array $page): string
{
    $raw = trim((string)($page['stream_created_at'] ?? $page['front_matter']['stream_created_at'] ?? $page['date'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $time = strtotime($raw);
    if ($time === false) {
        return $raw;
    }
    return date('M j, Y g:i A', $time);
}

function mp_stream_media_url(array $page): string
{
    $media = trim((string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? ''));
    if ($media === '') {
        return '';
    }

    $media = html_entity_decode(str_replace('\\', '/', $media), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('mp_media_resolve_existing_public_relative_from_url')) {
        $resolved = mp_media_resolve_existing_public_relative_from_url($media);
        if ($resolved !== '') {
            return mp_url_path($resolved);
        }
    }

    if (function_exists('mp_media_public_relative_from_url')) {
        $relative = mp_media_public_relative_from_url($media);
        if ($relative !== '' && is_file(mp_public_path($relative))) {
            return mp_url_path($relative);
        }
    }

    if (preg_match('#^https?://#i', $media) === 1) {
        $clean = mp_clean_url($media);
        return $clean !== '#' ? $clean : '';
    }

    $media = ltrim($media, '/');
    if (!str_starts_with($media, 'media/')) {
        return '';
    }
    if (!is_file(mp_public_path($media))) {
        return '';
    }
    return mp_url_path($media);
}

function mp_stream_media_mime_from_url(string $mediaUrl): string
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

function mp_stream_media_label_from_url(string $mediaUrl): string
{
    $path = parse_url($mediaUrl, PHP_URL_PATH);
    $name = basename((string)$path);
    $name = rawurldecode($name);
    return $name !== '' ? $name : 'Attached media';
}


function mp_stream_media_view_data(array $page, array $options = []): ?array
{
    $mediaUrl = mp_stream_media_url($page);
    if ($mediaUrl === '') {
        return null;
    }

    $mime = mp_stream_media_mime_from_url($mediaUrl);
    $type = 'file';
    if (str_starts_with($mime, 'image/')) {
        $type = 'image';
    } elseif (str_starts_with($mime, 'audio/')) {
        $type = 'audio';
    } elseif (str_starts_with($mime, 'video/')) {
        $type = 'video';
    }

    $alt = mp_stream_media_alt($page);
    $imageAttributes = '';
    if ($type === 'image' && function_exists('mp_media_image_attributes')) {
        $imageAttributes = mp_media_image_attributes($mediaUrl, $alt, [
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
        'label' => mp_stream_media_label_from_url($mediaUrl),
        'image_attributes' => $imageAttributes,
        'page' => $page,
    ];
}

function mp_render_stream_media_attachment(array $page, array $options = []): string
{
    $view = mp_stream_media_view_data($page, $options);
    if ($view === null) {
        return '';
    }

    return mp_render_public_theme_template('media', $view);
}

function mp_stream_link_preview_view_data(array $page): ?array
{
    if (!function_exists('mp_link_preview_from_page')) {
        return null;
    }
    $payload = mp_link_preview_from_page($page);
    if (($payload['url'] ?? '') === '') {
        return null;
    }
    if (($payload['title'] ?? '') === '') {
        $payload['title'] = (string)(parse_url((string)$payload['url'], PHP_URL_HOST) ?: $payload['url']);
    }
    $payload['page'] = $page;
    return $payload;
}

function mp_render_stream_link_preview(array $page): string
{
    $view = mp_stream_link_preview_view_data($page);
    if ($view === null) {
        return '';
    }
    return mp_render_public_theme_template('link-preview', $view);
}

function mp_stream_media_alt(array $page): string
{
    $title = trim((string)($page['title'] ?? ''));
    if ($title !== '' && !str_starts_with(strtolower($title), 'stream post:')) {
        return $title;
    }
    $preview = function_exists('mp_stream_preview_text') ? mp_stream_preview_text($page, 80) : '';
    return $preview !== '' && $preview !== 'Media post' ? $preview : 'Stream post media';
}

function mp_stream_edit_url(array $page): string
{
    $filename = basename((string)($page['filename'] ?? ''));
    if ($filename === '') {
        return '';
    }
    $type = ((string)($page['section'] ?? 'published')) === 'drafts' ? 'draft' : 'published';
    return mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($filename));
}

function mp_stream_author_initials(): string
{
    $author = trim((string)mp_setting_or_config('author_name', 'Admin'));
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

function mp_stream_absolute_url_for_post(array $page): string
{
    $relative = mp_stream_url_for_post($page);
    $basePath = mp_base_path();
    if ($basePath !== '' && str_starts_with($relative, $basePath . '/')) {
        $relative = substr($relative, strlen($basePath) + 1);
    } else {
        $relative = ltrim($relative, '/');
    }
    return mp_site_url($relative);
}


function mp_stream_card_view_data(array $page, bool $single = false, int $index = 0): array
{
    $title = trim((string)($page['title'] ?? ''));
    $dateRaw = (string)($page['stream_created_at'] ?? $page['front_matter']['stream_created_at'] ?? $page['date'] ?? '');
    $dateLabel = mp_stream_display_date($page);
    $dateIso = ($dateRaw !== '' && strtotime($dateRaw) !== false) ? date('c', (int)strtotime($dateRaw)) : $dateRaw;
    $authorUser = function_exists('mp_author_for_stream_page') ? mp_author_for_stream_page($page) : [];
    $authorName = (string)($authorUser['display_name'] ?? mp_setting_or_config('author_name', 'Admin'));
    $avatarMarkup = function_exists('mp_user_avatar_markup') ? mp_user_avatar_markup($authorUser, '', 96, 96, false) : '<span class="stream-author-avatar stream-author-initials">' . htmlspecialchars(mp_stream_author_initials(), ENT_QUOTES, 'UTF-8') . '</span>';
    $authorProfileUrl = ((int)($authorUser['id'] ?? 0) > 0 && (string)($authorUser['profile_visibility'] ?? 'public') === 'public' && function_exists('mp_public_profile_url_for_user')) ? mp_public_profile_url_for_user($authorUser) : '';
    $pageUrl = mp_stream_url_for_post($page);
    $body = trim((string)($page['body'] ?? ''));
    $bodyHtml = $body !== '' ? mp_markdown_to_html($body) : '';
    $mediaOptions = [
        'loading' => 'lazy',
        'fetchpriority' => '',
    ];
    $mediaHtml = mp_render_stream_media_attachment($page, $mediaOptions);
    $linkPreviewHtml = mp_render_stream_link_preview($page);
    $slug = (string)($page['slug'] ?? '');
    $likeCount = function_exists('mp_stream_like_count_for_slug') ? mp_stream_like_count_for_slug($slug) : 0;
    $liked = function_exists('mp_stream_visitor_liked_slug') ? mp_stream_visitor_liked_slug($slug) : false;
    $likeLabel = function_exists('mp_stream_like_label') ? mp_stream_like_label($likeCount) : ((string)$likeCount . ' likes');
    $commentCount = function_exists('mp_comment_count_for_slug') ? mp_comment_count_for_slug($slug) : 0;
    $commentLabel = function_exists('mp_comment_label') ? mp_comment_label($commentCount) : ((string)$commentCount . ' Comments');
    $editUrl = '';
    if (function_exists('mp_is_logged_in') && mp_is_logged_in() && mp_stream_show_edit_links()) {
        $subject = $page;
        $filename = basename((string)($page['filename'] ?? ''));
        if ($filename !== '' && function_exists('mp_content_subject_for_file')) {
            $section = ((string)($page['section'] ?? 'published')) === 'drafts' ? 'drafts' : 'published';
            $subject = mp_content_subject_for_file($section, $filename, $page);
        }
        if (function_exists('mp_current_user_can') && mp_current_user_can('edit_content', $subject)) {
            $editUrl = mp_stream_edit_url($page);
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

    return [
        'page' => $page,
        'single' => $single,
        'classes' => implode(' ', $classes),
        'title' => $title,
        'show_title' => false,
        'page_url' => $pageUrl,
        'body_html' => $bodyHtml,
        'media_html' => $mediaHtml,
        'link_preview_html' => $linkPreviewHtml,
        'author' => $authorUser,
        'author_name' => $authorName,
        'author_profile_url' => $authorProfileUrl,
        'author_archive_url' => '',
        'author_handle' => '',
        'avatar_html' => $avatarMarkup,
        'date_label' => $dateLabel,
        'date_iso' => $dateIso,
        'show_dates' => mp_stream_show_dates(),
        'like' => [
            'slug' => $slug,
            'count' => $likeCount,
            'label' => $likeLabel,
            'liked' => $liked,
            'endpoint' => mp_url_path('stream-like.php'),
            'endpoint_alt' => mp_admin_url('stream-like.php'),
            'action_label' => $liked ? 'Post liked.' : 'Like this post.',
        ],
        'comments' => [
            'count' => $commentCount,
            'label' => $commentLabel,
            'url' => $single ? '#comments' : $pageUrl . '#comments',
            'enabled' => function_exists('mp_comment_count_for_slug') && function_exists('mp_comment_label'),
        ],
        'back_url' => $single ? mp_url_path('stream/') : '',
        'edit_url' => $editUrl,
    ];
}

function mp_render_stream_card(array $page, bool $single = false, int $index = 0): string
{
    $html = mp_render_public_theme_template('card', mp_stream_card_view_data($page, $single, $index));
    if ($single && $html !== '' && function_exists('mp_markdown_prioritize_first_image')) {
        $html = mp_markdown_prioritize_first_image($html);
    }
    return $html;
}

function mp_stream_seo_title(array $page): string
{
    $seoTitle = trim((string)($page['seo_title'] ?? $page['front_matter']['seo_title'] ?? ''));
    if ($seoTitle !== '') {
        return mp_stream_limit_text($seoTitle, 65, '…');
    }

    return mp_stream_generated_seo_title(
        (string)($page['body'] ?? ''),
        (string)($page['stream_created_at'] ?? $page['front_matter']['stream_created_at'] ?? $page['date'] ?? ''),
        (string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? ''),
        []
    );
}

function mp_stream_seo_description(array $page, int $limit = 160): string
{
    $description = trim((string)($page['description'] ?? ''));
    if ($description !== '') {
        return mp_plain_excerpt($description, $limit);
    }

    return mp_stream_generated_description(
        (string)($page['body'] ?? ''),
        (string)($page['stream_created_at'] ?? $page['front_matter']['stream_created_at'] ?? $page['date'] ?? ''),
        (string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? ''),
        $limit
    );
}

function mp_plain_excerpt(string $text, int $limit = 160): string
{
    $text = mp_stream_clean_text_for_seo($text);
    if ($text === '') {
        return '';
    }
    return mp_stream_limit_text($text, $limit, '…');
}

function mp_stream_robots_directive(array $page): string
{
    $explicit = strtolower(trim((string)($page['robots'] ?? $page['front_matter']['robots'] ?? '')));
    if ($explicit !== '') {
        return preg_replace('/[^a-z,\s-]+/', '', $explicit) ?? $explicit;
    }

    $policy = function_exists('mp_stream_index_policy') ? mp_stream_index_policy() : 'smart';
    if ($policy === 'all') {
        return '';
    }
    if ($policy === 'noindex') {
        return 'noindex,follow';
    }

    $bodyText = mp_stream_clean_text_for_seo((string)($page['body'] ?? ''));
    $hasMedia = trim((string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? '')) !== '';
    if ($hasMedia && $bodyText === '') {
        return 'noindex,follow';
    }

    return '';
}

function mp_stream_media_absolute_url(array $page): string
{
    $relative = mp_stream_media_url($page);
    if ($relative === '') {
        return '';
    }
    $basePath = mp_base_path();
    if ($basePath !== '' && str_starts_with($relative, $basePath . '/')) {
        $relative = substr($relative, strlen($basePath) + 1);
    } else {
        $relative = ltrim($relative, '/');
    }
    return mp_site_url($relative);
}

function mp_render_stream_single(array $page): string
{
    $siteNameRaw = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
    $titleRaw = mp_stream_seo_title($page);
    $descriptionRaw = mp_stream_seo_description($page);
    $streamSlug = mp_slugify((string)($page['slug'] ?? ''));
    $canonical = mp_site_url($streamSlug !== '' ? 'stream/' . $streamSlug . '/' : '');
    $publishedRaw = (string)($page['stream_created_at'] ?? $page['front_matter']['stream_created_at'] ?? $page['date'] ?? '');
    $publishedMeta = '';
    if ($publishedRaw !== '' && strtotime($publishedRaw) !== false) {
        $publishedMeta = '<meta property="article:published_time" content="' . htmlspecialchars(date('c', (int)strtotime($publishedRaw)), ENT_QUOTES, 'UTF-8') . '">';
    }
    $imageUrl = mp_stream_media_absolute_url($page);
    $imageMime = $imageUrl !== '' ? mp_stream_media_mime_from_url((string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? '')) : '';
    $imageMeta = ($imageUrl !== '' && str_starts_with($imageMime, 'image/')) ? '<meta property="og:image" content="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '">' : '';
    $robotsDirective = mp_stream_robots_directive($page);
    $robotsMeta = $robotsDirective !== '' ? '<meta name="robots" content="' . htmlspecialchars($robotsDirective, ENT_QUOTES, 'UTF-8') . '">' : '';

    $view = [
        'site_name' => $siteNameRaw,
        'title' => $titleRaw,
        'description' => $descriptionRaw,
        'canonical' => $canonical,
        'published_meta' => $publishedMeta,
        'image_meta' => $imageMeta,
        'robots_meta' => $robotsMeta,
        'style_url' => mp_asset_url('assets/style.css'),
        'script_url' => mp_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => mp_public_theme_stylesheet_links(),
        'favicon_tags' => function_exists('mp_site_favicon_tags') ? mp_site_favicon_tags() : '',
        'theme_script_tags' => mp_public_theme_script_tags(),
        'body_class' => mp_public_theme_class('stream-single'),
        'header_html' => mp_render_public_header('stream-single', null, mp_stream_relative_directory_for_post($page) . '/'),
        'footer_html' => mp_render_public_footer(mp_stream_relative_directory_for_post($page) . '/'),
        'card_html' => mp_render_stream_card($page, true),
        'comments_html' => function_exists('mp_render_comments_mount') ? mp_render_comments_mount($page) : '',
        'page' => $page,
    ];

    return mp_render_public_theme_template('single', $view);
}

function mp_render_public_content_page(array $page): string
{
    if (mp_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'stream')) === 'page') {
        require_once __DIR__ . '/pages.php';
        return mp_render_page($page);
    }
    return mp_render_stream_single($page);
}




function mp_filter_stream_posts_for_search(array $pages, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $needle = function_exists('mb_strtolower') ? mb_strtolower($query) : strtolower($query);
    $matches = [];
    foreach (mp_sort_stream_posts(mp_filter_stream_posts($pages)) as $page) {
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

function mp_render_stream_search(string $query = ''): string
{
    $siteNameRaw = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
    $query = trim($query);
    $matches = mp_filter_stream_posts_for_search(mp_list_content_records('published'), $query);
    $items = $query !== '' ? mp_render_stream_cards($matches) : '';
    if ($query !== '' && $items === '') {
        $items = mp_render_public_theme_template('empty', [
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
        'canonical' => mp_site_url('search.php' . ($query !== '' ? '?q=' . rawurlencode($query) : '')),
        'style_url' => mp_asset_url('assets/style.css'),
        'script_url' => mp_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => mp_public_theme_stylesheet_links(),
        'favicon_tags' => function_exists('mp_site_favicon_tags') ? mp_site_favicon_tags() : '',
        'theme_script_tags' => mp_public_theme_script_tags(),
        'body_class' => mp_public_theme_class('search-page'),
        'header_html' => mp_render_public_header('search', null, 'search.php'),
        'footer_html' => mp_render_public_footer('search.php'),
        'query' => $query,
        'items_html' => $items,
        'results' => $matches,
        'result_count' => count($matches),
        'search_url' => mp_url_path('search.php'),
    ];

    return mp_render_public_theme_template('search', $view);
}

function mp_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function mp_rss_date(string $raw): string
{
    $time = strtotime($raw);
    return date(DATE_RSS, $time !== false ? $time : time());
}

function mp_cdata(string $value): string
{
    return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
}

function mp_render_rss_feed(array $pages, string $feedType = 'stream'): string
{
    $siteName = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
    $tagline = (string)mp_setting_or_config('site_tagline', 'A self-hosted microblog stream for owning short-form publishing.');
    $feedTitle = $siteName . ' Stream';
    $feedDescription = mp_site_identity_plain_text($tagline) !== '' ? mp_site_identity_plain_text($tagline) : 'Short-form updates from ' . $siteName . '.';
    $feedLink = mp_site_url('stream/');
    $selfLink = $feedType === 'root' ? mp_site_url('feed.xml') : mp_site_url('stream/feed.xml');
    $items = mp_sort_stream_posts(mp_filter_stream_posts($pages));
    $items = array_slice($items, 0, max(20, min(50, mp_stream_posts_per_page())));

    $xmlItems = '';
    foreach ($items as $page) {
        $title = mp_stream_seo_title($page);
        if ($title === '') {
            $title = 'Stream Post';
        }
        $link = mp_site_url(trim(mp_stream_relative_directory_for_post($page), '/') . '/');
        $rawDate = (string)($page['stream_created_at'] ?? $page['date'] ?? '');
        $description = mp_stream_seo_description($page);
        $bodyHtml = mp_markdown_to_html((string)($page['body'] ?? ''));
        if ($bodyHtml === '') {
            $bodyHtml = '<p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $xmlItems .= '    <item>' . "\n"
            . '      <title>' . mp_xml_escape($title) . '</title>' . "\n"
            . '      <link>' . mp_xml_escape($link) . '</link>' . "\n"
            . '      <guid isPermaLink="true">' . mp_xml_escape($link) . '</guid>' . "\n"
            . '      <pubDate>' . mp_rss_date($rawDate) . '</pubDate>' . "\n"
            . '      <description>' . mp_cdata($description) . '</description>' . "\n"
            . '      <content:encoded>' . mp_cdata($bodyHtml) . '</content:encoded>' . "\n"
            . '    </item>' . "\n";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
        . '  <channel>' . "\n"
        . '    <title>' . mp_xml_escape($feedTitle) . '</title>' . "\n"
        . '    <link>' . mp_xml_escape($feedLink) . '</link>' . "\n"
        . '    <description>' . mp_xml_escape($feedDescription) . '</description>' . "\n"
        . '    <language>en-us</language>' . "\n"
        . '    <lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>' . "\n"
        . '    <atom:link href="' . mp_xml_escape($selfLink) . '" rel="self" type="application/rss+xml" />' . "\n"
        . $xmlItems
        . '  </channel>' . "\n"
        . '</rss>' . "\n";
}


function mp_clean_static_export_stream_output(array $streamPosts, ?string $targetRoot = null): void
{
    $streamRoot = mp_static_site_export_path('stream', $targetRoot);
    if (!is_dir($streamRoot)) {
        return;
    }

    $currentSlugs = [];
    foreach ($streamPosts as $post) {
        $slug = mp_slugify((string)($post['slug'] ?? ''));
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
            mp_delete_directory($path);
        }
    }
}

function mp_generate_static_stream_archive(?array $pages = null, ?string $targetRoot = null): void
{
    $pages = $pages ?? mp_list_content_records('published');
    $streamPosts = mp_sort_stream_posts(mp_filter_stream_posts($pages));
    $perPage = mp_stream_posts_per_page();
    $totalPages = max(1, (int)ceil(count($streamPosts) / max(1, $perPage)));

    mp_delete_directory(mp_static_site_export_path('stream/page', $targetRoot));
    mp_clean_static_export_stream_output($streamPosts, $targetRoot);
    mp_write_file(mp_static_site_export_path('stream/index.html', $targetRoot), mp_render_stream_index($pages, false, 1, 'archive'));

    for ($page = 2; $page <= $totalPages; $page++) {
        mp_write_file(mp_static_site_export_path('stream/page/' . $page . '/index.html', $targetRoot), mp_render_stream_index($pages, false, $page, 'archive'));
    }
}

function mp_generate_static_feeds(?array $pages = null, ?string $targetRoot = null): void
{
    $pages = $pages ?? mp_list_content_records('published');
    mp_write_file(mp_static_site_export_path('stream/feed.xml', $targetRoot), mp_render_rss_feed($pages, 'stream'));
    mp_write_file(mp_static_site_export_path('feed.xml', $targetRoot), mp_render_rss_feed($pages, 'root'));
}

function mp_generate_static_discovery_files(?array $streamPosts = null, ?array $pages = null, ?string $targetRoot = null): void
{
    if (!is_file(__DIR__ . '/sitemap.php')) {
        return;
    }
    if (is_file(__DIR__ . '/pages.php')) {
        require_once __DIR__ . '/pages.php';
    }
    require_once __DIR__ . '/sitemap.php';
    if (function_exists('mp_generate_static_sitemap')) {
        mp_generate_static_sitemap($streamPosts, $pages, $targetRoot);
    }
}

function mp_unpublish_file(string $publishedFilename): array
{
    $filename = basename($publishedFilename);
    $page = function_exists('mp_find_database_content_by_markdown_path') ? mp_find_database_content_by_markdown_path('published', $filename) : null;
    $legacyPath = mp_content_path('published/' . $filename);
    if (!$page && is_file($legacyPath)) {
        $page = mp_parse_markdown_file($legacyPath);
        $page['filename'] = $filename;
        $page['section'] = 'published';
    }
    if (!$page) {
        throw new RuntimeException('Published stream post not found.');
    }

    $authorId = function_exists('mp_content_author_id_for_file') ? mp_content_author_id_for_file('published', $filename) : null;
    if ($authorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $authorId = (int)$page['author_id'];
    }
    if (function_exists('mp_record_revision_from_page')) {
        mp_record_revision_from_page($page, 'published', $filename, $authorId);
    }
    $draft = function_exists('mp_database_content_page_for_status') ? mp_database_content_page_for_status($page, 'draft', 'stream') : $page;
    $draftFilename = function_exists('mp_database_content_filename_for_page') ? mp_database_content_filename_for_page($draft) : $filename;
    if (function_exists('mp_find_database_content_by_slug_status') && mp_find_database_content_by_slug_status((string)($draft['slug'] ?? ''), 'draft', 'stream')) {
        throw new RuntimeException('A draft with this slug already exists. Delete or rename the draft first.');
    }

    if (function_exists('mp_delete_post_metadata_by_filename')) {
        mp_delete_post_metadata_by_filename('published', $filename);
    }
    if (function_exists('mp_sync_stream_metadata')) {
        mp_sync_stream_metadata($draft, 'drafts', $draftFilename, $authorId);
    }
    if (is_file($legacyPath)) {
        @unlink($legacyPath);
    }

    return $draft + ['filename' => $draftFilename];
}

function mp_delete_content_file(string $type, string $filename): array
{
    $section = $type === 'published' ? 'published' : 'drafts';
    $originalStatus = $section === 'published' ? 'published' : 'draft';
    $filename = basename($filename);
    $page = function_exists('mp_find_database_content_by_markdown_path') ? mp_find_database_content_by_markdown_path($section, $filename) : null;
    $legacyPath = mp_content_path($section . '/' . $filename);
    if (!$page && is_file($legacyPath)) {
        $page = mp_parse_markdown_file($legacyPath);
        $page['filename'] = $filename;
        $page['section'] = $section;
    }
    if (!$page) {
        throw new RuntimeException('Content record not found.');
    }

    $trashFilename = date('Ymd-His') . '-' . $originalStatus . '-' . $filename;
    if (function_exists('mp_record_trashed_content')) {
        mp_record_trashed_content($page, $originalStatus, $filename, $trashFilename);
    }
    if (function_exists('mp_delete_post_metadata_by_filename')) {
        mp_delete_post_metadata_by_filename($section, $filename);
    }
    if (is_file($legacyPath)) {
        @unlink($legacyPath);
    }
    return $page;
}

function mp_publish_file(string $draftFilename): array
{
    $filename = basename($draftFilename);
    $page = function_exists('mp_find_database_content_by_markdown_path') ? mp_find_database_content_by_markdown_path('drafts', $filename) : null;
    $legacyPath = mp_content_path('drafts/' . $filename);
    if (!$page && is_file($legacyPath)) {
        $page = mp_parse_markdown_file($legacyPath);
        $page['filename'] = $filename;
        $page['section'] = 'drafts';
    }
    if (!$page) {
        throw new RuntimeException('Draft not found.');
    }

    $authorId = function_exists('mp_content_author_id_for_file') ? mp_content_author_id_for_file('drafts', $filename) : null;
    if ($authorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $authorId = (int)$page['author_id'];
    }
    $published = function_exists('mp_database_content_page_for_status') ? mp_database_content_page_for_status($page, 'published', 'stream') : $page;
    $publishedFilename = function_exists('mp_database_content_filename_for_page') ? mp_database_content_filename_for_page($published) : basename((string)($published['slug'] ?? pathinfo($filename, PATHINFO_FILENAME)) . '.md');
    $existingPublished = function_exists('mp_find_database_content_by_slug_status') ? mp_find_database_content_by_slug_status((string)($published['slug'] ?? ''), 'published', 'stream') : null;
    if ($existingPublished) {
        throw new RuntimeException('A published stream post already uses this slug.');
    }

    if (function_exists('mp_delete_post_metadata_by_filename')) {
        mp_delete_post_metadata_by_filename('drafts', $filename);
    }
    if (function_exists('mp_sync_stream_metadata')) {
        mp_sync_stream_metadata($published, 'published', $publishedFilename, $authorId);
    }
    if (function_exists('mp_mark_post_review_status')) {
        mp_mark_post_review_status('published', $publishedFilename, 'approved');
    }
    if (is_file($legacyPath)) {
        @unlink($legacyPath);
    }


    return $published + ['filename' => $publishedFilename];
}

function mp_generate_static_site_index(?array $pages = null, ?string $targetRoot = null): void
{
    $pages = $pages ?? mp_list_content_records('published');
    mp_write_file(mp_static_site_export_path('index.html', $targetRoot), mp_render_stream_index($pages));
    mp_generate_static_stream_archive($pages, $targetRoot);
    mp_generate_static_feeds($pages, $targetRoot);
    mp_generate_static_discovery_files($pages, null, $targetRoot);
    mp_delete_directory(mp_static_site_export_path('categories', $targetRoot));
    mp_delete_directory(mp_static_site_export_path('tags', $targetRoot));
}


function mp_generate_static_site_export(?string $targetRoot = null): int
{
    $targetRoot = $targetRoot !== null && trim($targetRoot) !== '' ? $targetRoot : mp_static_site_export_root('current');
    mp_delete_directory($targetRoot);
    $pages = mp_list_content_records('published');
    $count = 0;
    foreach ($pages as $page) {
        $pageIndexPath = mp_stream_export_index_path_for_post($page, $targetRoot);
        mp_write_file($pageIndexPath, mp_render_public_content_page($page));
        $count++;
    }
    mp_generate_static_site_index($pages, $targetRoot);
    if (is_file(__DIR__ . '/pages.php')) {
        require_once __DIR__ . '/pages.php';
        if (function_exists('mp_generate_static_page_exports')) {
            $count += mp_generate_static_page_exports($targetRoot);
        }
    }
    return $count;
}
