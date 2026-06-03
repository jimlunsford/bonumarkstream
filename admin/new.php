<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/editor.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

$today = date('Y-m-d');
$createdAt = date('Y-m-d H:i:s');
$defaultSlug = '';
$defaultStatus = function_exists('bms_default_content_status') ? bms_default_content_status() : 'draft';
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
    $defaultStatus = function_exists('bms_default_content_status') ? bms_default_content_status() : 'draft';
    $submitAction = (string)($_POST['stream_submit_action'] ?? '');
    $requestedStatus = $defaultStatus;
    if ($submitAction === 'publish') {
        $requestedStatus = 'published';
    } elseif ($submitAction === 'draft') {
        $requestedStatus = 'draft';
    }
    if ($requestedStatus === 'published' && !bms_current_user_can('publish_content')) {
        $requestedStatus = 'draft';
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

    bms_verify_csrf();

    $raw = bms_build_markdown_from_request($requestedStatus === 'published' ? 'published' : 'draft');
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    if (trim((string)($_POST['body_markdown'] ?? '')) === '' && trim((string)($_POST['featured_media'] ?? '')) === '') {
        bms_flash('Stream post cannot be empty.', 'error');
        bms_redirect(bms_admin_url('new.php'));
    }

    if (strlen($raw) > 1024 * 1024 * 2) {
        bms_flash('Stream post is too large. Keep files under 2 MB.', 'error');
        bms_redirect(bms_admin_url('new.php'));
    }

    try {
        $createdPage = bms_parse_markdown_string($raw);
        $filename = $createdPage['slug'] . '.md';

        if (function_exists('bms_find_database_content_by_slug_status') && (bms_find_database_content_by_slug_status((string)$createdPage['slug'], 'draft', 'stream') || bms_find_database_content_by_slug_status((string)$createdPage['slug'], 'published', 'stream'))) {
            bms_flash('A stream post with this slug already exists. Change the slug or edit the existing post.', 'error');
            bms_admin_header('New Stream Post', [bms_editor_screen_controls_action()]);
            bms_new_content_form($page, $defaultStatus);
            bms_editor_script_tag();
            bms_admin_footer();
            exit;
        }

        if ($requestedStatus === 'published') {
            if (function_exists('bms_sync_stream_metadata')) {
                bms_sync_stream_metadata($createdPage, 'published', $filename, bms_current_user_id());
            }
            bms_clear_submitted_autosave();
            bms_flash('Stream post published. “' . $createdPage['title'] . '” is live through dynamic rendering.', 'success');
            bms_redirect(bms_admin_url('edit.php?type=published&file=' . urlencode($filename)));
        }

        if (function_exists('bms_sync_stream_metadata')) {
            bms_sync_stream_metadata($createdPage, 'drafts', $filename, bms_current_user_id());
        }
        bms_clear_submitted_autosave();
        bms_flash('Draft stream post created. “' . $createdPage['title'] . '” is ready to edit, preview, or publish.', 'success');
        bms_redirect(bms_admin_url('edit.php?type=draft&file=' . urlencode($filename)));
    } catch (Throwable $e) {
        bms_flash('Stream post creation failed. ' . $e->getMessage(), 'error');
    }
}

function bms_new_content_form(array $page, string $defaultStatus): void
{
    $section = $defaultStatus === 'published' ? 'published' : 'drafts';
    $button = $defaultStatus === 'published' ? 'Publish' : 'Save Draft';
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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="autosave_key" value="<?= htmlspecialchars(bms_editor_autosave_key('new'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="autosave_saved_at" value="">
        <div class="editor-workspace">
          <div class="editor-primary-column">
            <?php bms_stream_title_fields($page); ?>
            <?php bms_dual_editor((string)($page['body'] ?? '')); ?>
          </div>
          <aside class="editor-sidebar-column">
            <?php bms_publish_sidebar($section, $button, $helper, [
                'mode' => 'new',
                'default_status' => $defaultStatus,
            ]); ?>
            <?php bms_stream_url_fields($page, $section); ?>
            <?php bms_stream_settings_fields($page, $section); ?>
            <?php bms_stream_media_fields(); ?>
            <?php bms_stream_revision_fields($page); ?>
              </aside>
        </div>
      </form>
    </section>
    <?php
}

bms_admin_header('New Stream Post', [bms_editor_screen_controls_action()]);
bms_new_content_form($page, $defaultStatus);
bms_editor_script_tag();
bms_admin_footer();
