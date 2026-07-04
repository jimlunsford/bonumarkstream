<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/editor.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

$type = $_GET['type'] ?? ($_POST['type'] ?? 'draft');
$type = in_array($type, ['draft', 'published', 'scheduled'], true) ? $type : 'draft';
$file = basename($_GET['file'] ?? ($_POST['file'] ?? ''));
$section = $type === 'published' ? 'published' : ($type === 'scheduled' ? 'scheduled' : 'drafts');

$page = null;
if ($file !== '' && function_exists('bms_find_database_content_by_markdown_path')) {
    $page = bms_find_database_content_by_markdown_path($section, $file);
}
if (!$file || !$page) {
    bms_admin_error_page('Stream post not found', 'The requested Stream Post could not be found.', 404);
}
bms_require_content_file_access($section, $file, 'edit_content', $page);
$originalAuthorId = function_exists('bms_content_author_id_for_file') ? bms_content_author_id_for_file($section, $file) : null;
if ($originalAuthorId === null && (int)($page['author_id'] ?? 0) > 0) {
    $originalAuthorId = (int)$page['author_id'];
}
$path = (string)($page['path'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();

    $submitAction = (string)($_POST['stream_submit_action'] ?? 'save');
    $targetStatus = $section === 'published' ? 'published' : ($section === 'scheduled' ? 'scheduled' : 'draft');
    if ($submitAction === 'publish') {
        $targetStatus = 'published';
    } elseif ($submitAction === 'schedule') {
        $targetStatus = 'scheduled';
    } elseif ($submitAction === 'draft') {
        $targetStatus = 'draft';
    }
    if (in_array($targetStatus, ['published', 'scheduled'], true)) {
        bms_require_content_file_access($section, $file, 'publish_content', $page);
    }
    $scheduledAtUtc = null;
    if ($targetStatus === 'scheduled') {
        try {
            $scheduledAtUtc = bms_scheduled_input_to_utc((string)($_POST['stream_scheduled_at'] ?? bms_utc_to_scheduled_input((string)($page['scheduled_at'] ?? ''))));
            $_POST['stream_scheduled_at_utc'] = $scheduledAtUtc;
        } catch (Throwable $e) {
            bms_log_admin_exception('edit', $e);

            bms_flash('Scheduling failed. Please try again.', 'error');
            bms_redirect(bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
        }
    }
    $targetSection = $targetStatus === 'published' ? 'published' : ($targetStatus === 'scheduled' ? 'scheduled' : 'drafts');
    $raw = bms_build_markdown_from_request($targetStatus, (string)($page['slug'] ?? ''));
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    if (trim($raw) === '') {
        bms_flash('Stream post cannot be empty.', 'error');
        bms_redirect(bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }

    if (strlen($raw) > 1024 * 1024 * 2) {
        bms_flash('Stream post is too large. Keep files under 2 MB.', 'error');
        bms_redirect(bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }

    try {
        $updatedPage = bms_parse_markdown_string($raw);
        $newFilename = $updatedPage['slug'] . '.md';
        $oldSlug = bms_slugify((string)($page['slug'] ?? pathinfo($file, PATHINFO_FILENAME)));
        $newSlug = bms_slugify((string)($updatedPage['slug'] ?? $newFilename));

        $sameRecord = $newSlug === $oldSlug;
        $targetStatus = $targetStatus ?? ($section === 'published' ? 'published' : ($section === 'scheduled' ? 'scheduled' : 'draft'));
        if (!$sameRecord && function_exists('bms_find_database_content_by_slug_status') && bms_find_database_content_by_slug_status($newSlug, $targetStatus, 'stream')) {
            bms_flash('Another ' . ($targetStatus === 'published' ? 'published stream post' : ($targetStatus === 'scheduled' ? 'scheduled stream post' : 'draft')) . ' already uses this slug.', 'error');
            bms_redirect(bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
        }

        if (!$sameRecord && function_exists('bms_find_database_content_by_slug_status')) {
            foreach (['draft', 'published', 'scheduled'] as $conflictStatus) {
                if ($conflictStatus !== $targetStatus && bms_find_database_content_by_slug_status($newSlug, $conflictStatus, 'stream')) {
                    bms_flash('Another stream post already uses this slug. Change the slug or edit the existing post.', 'error');
                    bms_redirect(bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
                }
            }
        }

        if ($targetStatus === 'published') {
            if ($oldSlug !== $newSlug && empty($_POST['confirm_slug_change'])) {
                bms_flash('Confirm the live URL change before saving this published Stream Post.', 'warning');
                bms_redirect(bms_admin_url('edit.php?type=published&file=' . urlencode($file)));
            }
            if (function_exists('bms_record_revision_from_page')) {
                bms_record_revision_from_page($page, 'published', $file, $originalAuthorId);
            }
            if (function_exists('bms_delete_post_metadata_by_filename') && ($newFilename !== $file || $targetSection !== $section)) {
                bms_delete_post_metadata_by_filename($section, $file);
            }
            if (is_file($path)) {
                @unlink($path);
            }
            if (function_exists('bms_sync_stream_metadata')) {
                bms_sync_stream_metadata($updatedPage, 'published', $newFilename, $originalAuthorId);
            }
            bms_flash('Updated “' . $updatedPage['title'] . '”. The live stream post is current through dynamic rendering.', 'success');
            bms_redirect(bms_admin_url('edit.php?type=published&file=' . urlencode($newFilename)));
        }
        if ($targetStatus === 'scheduled') {
            if (function_exists('bms_record_revision_from_page')) {
                bms_record_revision_from_page($page, 'draft', $file, $originalAuthorId);
            }
            if (function_exists('bms_delete_post_metadata_by_filename') && ($newFilename !== $file || $targetSection !== $section)) {
                bms_delete_post_metadata_by_filename($section, $file);
            }
            if (function_exists('bms_schedule_post_page')) {
                bms_schedule_post_page($updatedPage, 'scheduled', $newFilename, $originalAuthorId, (string)$scheduledAtUtc);
            }
            bms_flash('Scheduled “' . $updatedPage['title'] . '” for ' . bms_format_scheduled_datetime((string)$scheduledAtUtc) . '.', 'success');
            bms_redirect(bms_admin_url('edit.php?type=scheduled&file=' . urlencode($newFilename)));
        }
        if (function_exists('bms_record_revision_from_page')) {
            bms_record_revision_from_page($page, 'draft', $file, $originalAuthorId);
        }
        if (function_exists('bms_delete_post_metadata_by_filename') && ($newFilename !== $file || $targetSection !== $section)) {
            bms_delete_post_metadata_by_filename($section, $file);
        }
        if (is_file($path)) {
            @unlink($path);
        }
        if (function_exists('bms_sync_stream_metadata')) {
            bms_sync_stream_metadata($updatedPage, 'drafts', $newFilename, $originalAuthorId);
        }
        bms_flash($section === 'scheduled' ? 'Schedule canceled. Draft saved.' : 'Draft saved. “' . $updatedPage['title'] . '” is ready to preview or publish.', 'success');
        bms_redirect(bms_admin_url('edit.php?type=draft&file=' . urlencode($newFilename)));
    } catch (Throwable $e) {
        bms_log_admin_exception('edit', $e);

        bms_flash('Save failed. Please try again.', 'error');
        bms_redirect(bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }
}

$headerActions = [bms_editor_screen_controls_action()];
bms_admin_header('Edit Stream Post: ' . $page['title'], $headerActions);
?>
<section class="editor-panel editor-composer-panel">
  <div class="composer-top-row" aria-label="Editor options">
    <p class="editor-page-helper"><span class="editor-page-helper-pill"><?= $section === 'published' ? 'Published' : ($section === 'scheduled' ? 'Scheduled' : 'Draft') ?></span> Editing database content record: <code><?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?></code></p>
  </div>

  <form id="stream-editor-form" method="post" class="editor-form editor-layout-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="autosave_key" value="<?= htmlspecialchars(bms_editor_autosave_key('edit', $file), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="autosave_saved_at" value="<?= htmlspecialchars(bms_editor_autosave_saved_at($path), ENT_QUOTES, 'UTF-8') ?>">
    <div class="editor-workspace">
      <div class="editor-primary-column">
        <?php bms_stream_title_fields($page); ?>
        <?php bms_dual_editor($page['body']); ?>
      </div>
      <aside class="editor-sidebar-column">
        <?php bms_publish_sidebar($section, $section === 'published' ? 'Update Post' : ($section === 'scheduled' ? 'Save Scheduled Post' : 'Save Draft'), $section === 'published' ? 'Updates the live database source immediately.' : ($section === 'scheduled' ? 'Keeps this post scheduled. You can reschedule, publish now, or cancel back to draft.' : 'Saves the draft in the database. Publish and trash actions are available here after the draft is saved.'), [
            'mode' => 'edit',
            'file' => $file,
            'page' => $page,
        ]); ?>
        <?php bms_stream_url_fields($page, $section); ?>
        <?php bms_stream_settings_fields($page, $section); ?>
        <?php bms_stream_media_fields(); ?>
        <?php bms_stream_revision_fields($page); ?>
      </aside>
    </div>
  </form>

  <div class="editor-hidden-action-forms" aria-hidden="true">
    <?php if ($section !== 'published'): ?>
      <form id="publish-draft-action-form" method="post" action="<?= htmlspecialchars(bms_admin_url('publish.php'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="autosave_key" value="<?= htmlspecialchars(bms_editor_autosave_key('edit', $file), ENT_QUOTES, 'UTF-8') ?>">
      </form>
    <?php else: ?>
      <form id="unpublish-post-action-form" method="post" action="<?= htmlspecialchars(bms_admin_url('unpublish.php'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
      </form>
    <?php endif; ?>
    <form id="trash-post-action-form" method="post" action="<?= htmlspecialchars(bms_admin_url('delete.php'), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
    </form>
  </div>
</section>
<?php bms_editor_script_tag(); ?>
<?php bms_admin_footer(); ?>
