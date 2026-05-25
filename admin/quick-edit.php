<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

$type = $_GET['type'] ?? ($_POST['type'] ?? 'draft');
$type = $type === 'published' ? 'published' : 'draft';
$file = basename($_GET['file'] ?? ($_POST['file'] ?? ''));
$section = $type === 'published' ? 'published' : 'drafts';
$path = mp_content_path($section . '/' . $file);

$page = null;
if ($file !== '' && function_exists('mp_find_database_content_by_markdown_path')) {
    $page = mp_find_database_content_by_markdown_path($section, $file);
}
if (!$page && $file !== '' && is_file($path)) {
    $page = mp_parse_markdown_file($path);
    $page['filename'] = $file;
    $page['path'] = $path;
    $page['section'] = $section;
}
if ($file === '' || !$page) {
    mp_admin_error_page('Stream post not found', 'The requested Stream Post could not be found.', 404);
}

mp_require_content_file_access($section, $file, 'edit_content', $page);
$originalAuthorId = function_exists('mp_content_author_id_for_file') ? mp_content_author_id_for_file($section, $file) : null;
if ($originalAuthorId === null && (int)($page['author_id'] ?? 0) > 0) {
    $originalAuthorId = (int)$page['author_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $previousReviewStatus = ($type === 'draft' && function_exists('mp_review_status_for_file')) ? mp_review_status_for_file('drafts', $file) : '';
    $targetStatus = (string)($_POST['stream_status'] ?? $type);
    $targetStatus = $targetStatus === 'published' ? 'published' : 'draft';
    $targetSection = $targetStatus === 'published' ? 'published' : 'drafts';
    if ($targetStatus === 'published') {
        mp_require_content_file_access($section, $file, 'publish_content', $page);
    }

    $fields = [
        'title' => (string)($_POST['stream_title'] ?? $page['title']),
        'slug' => (string)($_POST['stream_slug'] ?? $page['slug']),
        'status' => $targetStatus,
        'date' => (string)($_POST['stream_date'] ?? $page['date']),
        'content_type' => 'stream',
        'description' => (string)($_POST['stream_description'] ?? ($page['description'] ?? '')),
        'category' => 'Stream',
        'tags' => '',
        'featured_media' => (string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? ''),
        'stream_created_at' => (string)($page['stream_created_at'] ?? $page['front_matter']['stream_created_at'] ?? $page['date'] ?? date('Y-m-d H:i:s')),
        'seo_title' => (string)($_POST['stream_seo_title'] ?? ($page['seo_title'] ?? '')),
        'robots' => (string)($_POST['stream_robots'] ?? ($page['robots'] ?? '')),
    ];

    try {
        $fields = mp_stream_prepare_metadata_fields($fields, (string)($page['body'] ?? ''), (string)($page['slug'] ?? ''));
        $raw = mp_build_markdown_document($fields, (string)($page['body'] ?? ''));
        $updated = mp_parse_markdown_string($raw);
        $newFilename = $updated['slug'] . '.md';
        $oldSlug = mp_slugify((string)($page['slug'] ?? pathinfo($file, PATHINFO_FILENAME)));
        $newSlug = mp_slugify((string)($updated['slug'] ?? pathinfo($newFilename, PATHINFO_FILENAME)));

        if ($newSlug !== $oldSlug && function_exists('mp_find_database_content_by_slug_status') && mp_find_database_content_by_slug_status($newSlug, $targetStatus, 'stream')) {
            throw new RuntimeException('Another stream post already uses this slug in the target status.');
        }

        if ($newSlug !== $oldSlug && function_exists('mp_find_database_content_by_slug_status') && mp_find_database_content_by_slug_status($newSlug, $targetStatus === 'published' ? 'draft' : 'published', 'stream')) {
            throw new RuntimeException('Another stream post already uses this slug.');
        }

        if ($type === 'published' && function_exists('mp_record_revision_from_page')) {
            mp_record_revision_from_page($page, 'published', $file, $originalAuthorId);
        }

        if (function_exists('mp_delete_post_metadata_by_filename') && ($targetSection !== $section || $newFilename !== $file)) {
            mp_delete_post_metadata_by_filename($section, $file);
        }
        if (is_file($path)) {
            @unlink($path);
        }
        if (function_exists('mp_sync_stream_metadata')) {
            mp_sync_stream_metadata($updated, $targetSection, $newFilename, $originalAuthorId);
        }
        if ($targetStatus === 'draft' && $previousReviewStatus === 'pending' && function_exists('mp_mark_draft_pending_review')) {
            mp_mark_draft_pending_review($newFilename);
        }
        if ($targetStatus === 'published' && function_exists('mp_mark_post_review_status')) {
            mp_mark_post_review_status('published', $newFilename, 'approved');
        }

        if ($targetStatus === 'published') {
            mp_flash('Quick edit saved. The live stream post is current through dynamic rendering.', 'success');
            mp_redirect(mp_admin_url('quick-edit.php?type=published&file=' . urlencode($newFilename)));
        }

        if ($type === 'published') {
        }
        mp_flash('Quick edit saved. Draft details were updated.', 'success');
        mp_redirect(mp_admin_url('quick-edit.php?type=draft&file=' . urlencode($newFilename)));
    } catch (Throwable $e) {
        mp_flash('Quick edit failed. ' . $e->getMessage(), 'error');
        mp_redirect(mp_admin_url('quick-edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }
}

$headerActions = [
    ['label' => 'All Stream Posts', 'href' => mp_admin_url('content.php'), 'style' => 'secondary'],
    ['label' => 'Full Editor', 'href' => mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)), 'style' => 'primary'],
];
if ($type === 'published') {
    $headerActions[] = mp_view_stream_post_action($page, 'View Post');
}
mp_admin_header('Quick Edit', $headerActions);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Quick Edit</p>
  <h2><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></h2>
  <p class="meta">Change stream post details without opening the full editor.</p>
</section>

<section class="panel settings-panel quick-edit-panel">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">

    <label for="stream_title">Internal title</label>
    <input type="text" id="stream_title" name="stream_title" value="<?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?>" maxlength="180" placeholder="Generated as post text | site title if blank">
    <p class="field-help">Optional. Leave blank to generate an internal title from the post text or media.</p>

    <label for="stream_slug">Slug</label>
    <input type="text" id="stream_slug" name="stream_slug" value="<?= htmlspecialchars($page['slug'], ENT_QUOTES, 'UTF-8') ?>" maxlength="180" placeholder="Generated on save">
    <p class="field-help">Leave blank to generate a URL slug. Changing this on a published post changes the public URL.</p>

    <label for="stream_status">Status</label>
    <select id="stream_status" name="stream_status">
      <option value="draft" <?= $type === 'draft' ? 'selected' : '' ?>>Draft</option>
      <?php if ($type === 'published' || mp_current_user_can('publish_content', mp_content_subject_for_file($section, $file, $page))): ?>
        <option value="published" <?= $type === 'published' ? 'selected' : '' ?>>Published</option>
      <?php endif; ?>
    </select>

    <label for="stream_date">Date</label>
    <input type="date" id="stream_date" name="stream_date" value="<?= htmlspecialchars((string)$page['date'], ENT_QUOTES, 'UTF-8') ?>" required>

    <label for="stream_description">Meta description</label>
    <textarea class="small-textarea" id="stream_description" name="stream_description" maxlength="300"><?= htmlspecialchars((string)($page['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
    <p class="field-help">Optional. Leave blank to generate one from post text or media.</p>

    <label for="stream_seo_title">Search title</label>
    <input type="text" id="stream_seo_title" name="stream_seo_title" value="<?= htmlspecialchars((string)($page['seo_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="180" placeholder="Generated as post text | site title if blank">

    <label for="stream_robots">Search indexing</label>
    <?php $robots = (string)($page['robots'] ?? ''); ?>
    <select id="stream_robots" name="stream_robots">
      <option value="" <?= $robots === '' ? 'selected' : '' ?>>Use reading setting</option>
      <option value="index,follow" <?= $robots === 'index,follow' ? 'selected' : '' ?>>Index this post</option>
      <option value="noindex,follow" <?= $robots === 'noindex,follow' ? 'selected' : '' ?>>Noindex this post</option>
    </select>

    <div class="form-actions-row">
      <button type="submit">Save Quick Edit</button>
      <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('content.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
      <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)), ENT_QUOTES, 'UTF-8') ?>">Open Full Editor</a>
    </div>
  </form>
</section>
<?php mp_admin_footer(); ?>
