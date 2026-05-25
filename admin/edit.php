<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/editor.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

$type = $_GET['type'] ?? ($_POST['type'] ?? 'draft');
$file = basename($_GET['file'] ?? ($_POST['file'] ?? ''));
$section = $type === 'published' ? 'published' : 'drafts';
$path = mp_content_path($section . '/' . $file);

$page = null;
if ($file !== '' && function_exists('mp_find_database_content_by_markdown_path')) {
    $page = mp_find_database_content_by_markdown_path($section, $file);
}
if (!$page && $file !== '' && is_file($path)) {
    try {
        $page = mp_parse_markdown_file($path);
        $page['filename'] = $file;
        $page['path'] = $path;
        $page['section'] = $section;
    } catch (Throwable $e) {
        mp_admin_error_page('Could not read Stream Post', 'Bonumark Stream could not read the legacy Markdown source.', 500);
    }
}
if (!$file || !$page) {
    mp_admin_error_page('Stream post not found', 'The requested Stream Post could not be found.', 404);
}
mp_require_content_file_access($section, $file, 'edit_content', $page);
$originalAuthorId = function_exists('mp_content_author_id_for_file') ? mp_content_author_id_for_file($section, $file) : null;
if ($originalAuthorId === null && (int)($page['author_id'] ?? 0) > 0) {
    $originalAuthorId = (int)$page['author_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();

    $raw = mp_build_markdown_from_request($section === 'published' ? 'published' : 'draft', (string)($page['slug'] ?? ''));
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    if (trim($raw) === '') {
        mp_flash('Stream post cannot be empty.', 'error');
        mp_redirect(mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }

    if (strlen($raw) > 1024 * 1024 * 2) {
        mp_flash('Stream post is too large. Keep files under 2 MB.', 'error');
        mp_redirect(mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }

    try {
        $updatedPage = mp_parse_markdown_string($raw);
        $newFilename = $updatedPage['slug'] . '.md';
        $oldSlug = mp_slugify((string)($page['slug'] ?? pathinfo($file, PATHINFO_FILENAME)));
        $newSlug = mp_slugify((string)($updatedPage['slug'] ?? $newFilename));

        $sameRecord = $newSlug === $oldSlug;
        $targetStatus = $section === 'published' ? 'published' : 'draft';
        if (!$sameRecord && function_exists('mp_find_database_content_by_slug_status') && mp_find_database_content_by_slug_status($newSlug, $targetStatus, 'stream')) {
            mp_flash('Another ' . ($section === 'published' ? 'published stream post' : 'draft') . ' already uses this slug.', 'error');
            mp_redirect(mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
        }

        if ($section === 'drafts' && !$sameRecord && function_exists('mp_find_database_content_by_slug_status') && mp_find_database_content_by_slug_status($newSlug, 'published', 'stream')) {
            mp_flash('A published stream post already uses this slug. Change the slug or edit the published post.', 'error');
            mp_redirect(mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
        }

        if ($section === 'published') {
            if ($oldSlug !== $newSlug && empty($_POST['confirm_slug_change'])) {
                mp_flash('Confirm the live URL change before saving this published Stream Post.', 'warning');
                mp_redirect(mp_admin_url('edit.php?type=published&file=' . urlencode($file)));
            }
            if (function_exists('mp_record_revision_from_page')) {
                mp_record_revision_from_page($page, 'published', $file, $originalAuthorId);
            }
            if (function_exists('mp_delete_post_metadata_by_filename') && $newFilename !== $file) {
                mp_delete_post_metadata_by_filename('published', $file);
            }
            if (is_file($path)) {
                @unlink($path);
            }
            if (function_exists('mp_sync_stream_metadata')) {
                mp_sync_stream_metadata($updatedPage, 'published', $newFilename, $originalAuthorId);
            }
            mp_clear_submitted_autosave();
            mp_flash('Updated “' . $updatedPage['title'] . '”. The live stream post is current through dynamic rendering.', 'success');
            mp_redirect(mp_admin_url('edit.php?type=published&file=' . urlencode($newFilename)));
        }

        $oldReviewStatus = function_exists('mp_review_status_for_file') ? mp_review_status_for_file('drafts', $file) : '';
        if (function_exists('mp_record_revision_from_page')) {
            mp_record_revision_from_page($page, 'draft', $file, $originalAuthorId);
        }
        if (function_exists('mp_delete_post_metadata_by_filename') && $newFilename !== $file) {
            mp_delete_post_metadata_by_filename('drafts', $file);
        }
        if (is_file($path)) {
            @unlink($path);
        }
        if (function_exists('mp_sync_stream_metadata')) {
            mp_sync_stream_metadata($updatedPage, 'drafts', $newFilename, $originalAuthorId);
        }
        if ($oldReviewStatus === 'pending' && function_exists('mp_mark_draft_pending_review')) {
            mp_mark_draft_pending_review($newFilename);
        }
        mp_clear_submitted_autosave();
        mp_flash('Draft saved. “' . $updatedPage['title'] . '” is ready to preview or publish.', 'success');
        mp_redirect(mp_admin_url('edit.php?type=draft&file=' . urlencode($newFilename)));
    } catch (Throwable $e) {
        mp_flash('Save failed. ' . $e->getMessage(), 'error');
        mp_redirect(mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }
}

$headerActions = [mp_editor_screen_controls_action()];
mp_admin_header('Edit Stream Post: ' . $page['title'], $headerActions);
?>
<section class="editor-panel editor-composer-panel">
  <div class="composer-top-row" aria-label="Editor options">
    <p class="editor-page-helper"><span class="editor-page-helper-pill"><?= $section === 'published' ? 'Published' : 'Draft' ?></span> Editing database content record: <code><?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?></code></p>
  </div>

  <form id="stream-editor-form" method="post" class="editor-form editor-layout-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="autosave_key" value="<?= htmlspecialchars(mp_editor_autosave_key('edit', $file), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="autosave_saved_at" value="<?= htmlspecialchars(mp_editor_autosave_saved_at($path), ENT_QUOTES, 'UTF-8') ?>">
    <div class="editor-workspace">
      <div class="editor-primary-column">
        <?php mp_stream_title_fields($page); ?>
        <?php mp_dual_editor($page['body']); ?>
      </div>
      <aside class="editor-sidebar-column">
        <?php mp_publish_sidebar($section, $section === 'published' ? 'Update Post' : 'Save Draft', $section === 'published' ? 'Updates the live database source immediately.' : 'Saves the draft in the database. Publish and trash actions are available here after the draft is saved.', [
            'mode' => 'edit',
            'file' => $file,
            'page' => $page,
        ]); ?>
        <?php mp_stream_url_fields($page, $section); ?>
        <?php mp_stream_settings_fields($page, $section); ?>
        <?php mp_stream_media_fields(); ?>
        <?php mp_stream_revision_fields($page); ?>
      </aside>
    </div>
  </form>

  <div class="editor-hidden-action-forms" aria-hidden="true">
    <?php if ($section !== 'published'): ?>
      <form id="publish-draft-action-form" method="post" action="<?= htmlspecialchars(mp_admin_url('publish.php'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="autosave_key" value="<?= htmlspecialchars(mp_editor_autosave_key('edit', $file), ENT_QUOTES, 'UTF-8') ?>">
      </form>
      <form id="submit-review-action-form" method="post" action="<?= htmlspecialchars(mp_admin_url('submit-review.php'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
      </form>
    <?php else: ?>
      <form id="unpublish-post-action-form" method="post" action="<?= htmlspecialchars(mp_admin_url('unpublish.php'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
      </form>
    <?php endif; ?>
    <form id="trash-post-action-form" method="post" action="<?= htmlspecialchars(mp_admin_url('delete.php'), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
    </form>
  </div>
</section>
<?php mp_editor_script_tag(); ?>
<?php mp_admin_footer(); ?>
