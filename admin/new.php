<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/editor.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

$today = date('Y-m-d');
$createdAt = date('Y-m-d H:i:s');
$defaultSlug = '';
$defaultStatus = function_exists('mp_default_content_status') ? mp_default_content_status() : 'draft';
$defaultSection = $defaultStatus === 'published' ? 'published' : 'drafts';
$defaultTitle = '';
$defaultPage = [
    'title' => $defaultTitle,
    'slug' => $defaultSlug,
    'status' => $defaultStatus,
    'date' => $today,
    'content_type' => 'stream',
    'description' => '',
    'category' => 'Stream',
    'tags' => [],
    'featured_media' => '',
    'stream_created_at' => $createdAt,
    'seo_title' => '',
    'robots' => '',
    'body' => '',
    'front_matter' => [],
];

$page = $defaultPage;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $defaultStatus = function_exists('mp_default_content_status') ? mp_default_content_status() : 'draft';
    $submitAction = (string)($_POST['stream_submit_action'] ?? '');
    $requestedStatus = $defaultStatus;
    $submitForReview = false;
    if ($submitAction === 'publish') {
        $requestedStatus = 'published';
    } elseif ($submitAction === 'submit_review') {
        $requestedStatus = 'draft';
        $submitForReview = true;
    } elseif ($submitAction === 'draft') {
        $requestedStatus = 'draft';
    }
    if ($requestedStatus === 'published' && !mp_current_user_can('publish_content')) {
        $requestedStatus = 'draft';
        $submitForReview = true;
    }
    $defaultSection = $requestedStatus === 'published' ? 'published' : 'drafts';
    $page = [
        'title' => (string)($_POST['stream_title'] ?? $defaultTitle),
        'slug' => (string)($_POST['stream_slug'] ?? $defaultSlug),
        'status' => $requestedStatus,
        'date' => (string)($_POST['stream_date'] ?? $today),
        'content_type' => 'stream',
        'description' => (string)($_POST['stream_description'] ?? ''),
        'category' => 'Stream',
        'tags' => [],
        'featured_media' => (string)($_POST['featured_media'] ?? ''),
        'stream_created_at' => (string)($_POST['stream_created_at'] ?? $createdAt),
        'seo_title' => (string)($_POST['stream_seo_title'] ?? ''),
        'robots' => (string)($_POST['stream_robots'] ?? ''),
        'body' => (string)($_POST['body_markdown'] ?? ''),
        'front_matter' => [],
    ];

    mp_verify_csrf();

    $raw = mp_build_markdown_from_request($requestedStatus === 'published' ? 'published' : 'draft');
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    if (trim((string)($_POST['body_markdown'] ?? '')) === '' && trim((string)($_POST['featured_media'] ?? '')) === '') {
        mp_flash('Stream post cannot be empty.', 'error');
        mp_redirect(mp_admin_url('new.php'));
    }

    if (strlen($raw) > 1024 * 1024 * 2) {
        mp_flash('Stream post is too large. Keep files under 2 MB.', 'error');
        mp_redirect(mp_admin_url('new.php'));
    }

    try {
        $createdPage = mp_parse_markdown_string($raw);
        $filename = $createdPage['slug'] . '.md';

        if (function_exists('mp_find_database_content_by_slug_status') && (mp_find_database_content_by_slug_status((string)$createdPage['slug'], 'draft', 'stream') || mp_find_database_content_by_slug_status((string)$createdPage['slug'], 'published', 'stream'))) {
            mp_flash('A stream post with this slug already exists. Change the slug or edit the existing post.', 'error');
            mp_admin_header('New Stream Post', [mp_editor_screen_controls_action()]);
            mp_new_content_form($page, $defaultStatus);
            mp_editor_script_tag();
            mp_admin_footer();
            exit;
        }

        if ($requestedStatus === 'published') {
            if (function_exists('mp_sync_stream_metadata')) {
                mp_sync_stream_metadata($createdPage, 'published', $filename, mp_current_user_id());
            }
            mp_clear_submitted_autosave();
            mp_flash('Stream post published. “' . $createdPage['title'] . '” is live through dynamic rendering.', 'success');
            mp_redirect(mp_admin_url('edit.php?type=published&file=' . urlencode($filename)));
        }

        if (function_exists('mp_sync_stream_metadata')) {
            mp_sync_stream_metadata($createdPage, 'drafts', $filename, mp_current_user_id());
        }
        if ($submitForReview && function_exists('mp_mark_draft_pending_review')) {
            mp_mark_draft_pending_review($filename);
        }
        mp_clear_submitted_autosave();
        mp_flash($submitForReview ? 'Stream post submitted for review. “' . $createdPage['title'] . '” is waiting for an admin.' : 'Draft stream post created. “' . $createdPage['title'] . '” is ready to edit, preview, or publish.', 'success');
        mp_redirect(mp_admin_url('edit.php?type=draft&file=' . urlencode($filename)));
    } catch (Throwable $e) {
        mp_flash('Stream post creation failed. ' . $e->getMessage(), 'error');
    }
}

function mp_new_content_form(array $page, string $defaultStatus): void
{
    $section = $defaultStatus === 'published' ? 'published' : 'drafts';
    $needsReview = function_exists('mp_current_user_requires_post_review') && mp_current_user_requires_post_review();
    $button = $needsReview ? 'Submit for Review' : ($defaultStatus === 'published' ? 'Publish' : 'Save Draft');
    $helper = '';
    $intro = $defaultStatus === 'published'
        ? 'Your Writing setting is set to publish new stream posts immediately.'
        : 'Saving creates a draft, not a live stream post.';
    ?>
    <section class="editor-panel editor-composer-panel">
      <div class="composer-top-row" aria-label="Editor options">
        <p class="editor-page-helper">Write visually or switch to Markdown. <?= htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') ?></p>
      </div>

      <form id="stream-editor-form" method="post" class="editor-form editor-layout-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="autosave_key" value="<?= htmlspecialchars(mp_editor_autosave_key('new'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="autosave_saved_at" value="">
        <div class="editor-workspace">
          <div class="editor-primary-column">
            <?php mp_stream_title_fields($page); ?>
            <?php mp_dual_editor((string)($page['body'] ?? '')); ?>
          </div>
          <aside class="editor-sidebar-column">
            <?php mp_publish_sidebar($section, $button, $helper, [
                'mode' => 'new',
                'default_status' => $defaultStatus,
                'requires_review' => $needsReview,
            ]); ?>
            <?php mp_stream_url_fields($page, $section); ?>
            <?php mp_stream_settings_fields($page, $section); ?>
            <?php mp_stream_media_fields(); ?>
            <?php mp_stream_revision_fields($page); ?>
              </aside>
        </div>
      </form>
    </section>
    <?php
}

mp_admin_header('New Stream Post', [mp_editor_screen_controls_action()]);
mp_new_content_form($page, $defaultStatus);
mp_editor_script_tag();
mp_admin_footer();
