<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

$type = $_GET['type'] ?? ($_POST['type'] ?? 'draft');
$type = in_array($type, ['published', 'scheduled'], true) ? $type : 'draft';
$file = basename($_GET['file'] ?? ($_POST['file'] ?? ''));
$section = match ($type) {
    'published' => 'published',
    'scheduled' => 'scheduled',
    default => 'drafts',
};

$page = null;
if ($file !== '' && function_exists('bms_find_database_content_by_markdown_path')) {
    $page = bms_find_database_content_by_markdown_path($section, $file);
}
if ($file === '' || !$page) {
    bms_admin_error_page('Stream post not found', 'The requested Stream Post could not be found.', 404);
}

bms_require_content_file_access($section, $file, 'edit_content', $page);
$originalAuthorId = function_exists('bms_content_author_id_for_file') ? bms_content_author_id_for_file($section, $file) : null;
if ($originalAuthorId === null && (int)($page['author_id'] ?? 0) > 0) {
    $originalAuthorId = (int)$page['author_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $targetStatus = (string)($_POST['stream_status'] ?? $type);
    $targetStatus = in_array($targetStatus, ['published', 'scheduled'], true) ? $targetStatus : 'draft';
    $targetSection = match ($targetStatus) {
        'published' => 'published',
        'scheduled' => 'scheduled',
        default => 'drafts',
    };
    $scheduledAtUtc = null;
    if (in_array($targetStatus, ['published', 'scheduled'], true)) {
        bms_require_content_file_access($section, $file, 'publish_content', $page);
    }
    if ($targetStatus === 'scheduled') {
        try {
            $scheduledAtUtc = function_exists('bms_scheduled_input_to_utc') ? bms_scheduled_input_to_utc((string)($_POST['stream_scheduled_at'] ?? '')) : null;
            if ($scheduledAtUtc === null) {
                throw new RuntimeException('Choose a future scheduled publish time.');
            }
        } catch (Throwable $e) {
            bms_log_admin_exception('quick-edit', $e);

            bms_flash('Quick edit failed. Please try again.', 'error');
            bms_redirect(bms_admin_url('quick-edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
        }
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
        'scheduled_at' => $targetStatus === 'scheduled' ? (string)$scheduledAtUtc : '',
    ];

    try {
        $fields = bms_stream_prepare_metadata_fields($fields, (string)($page['body'] ?? ''), (string)($page['slug'] ?? ''));
        $raw = bms_build_markdown_document($fields, (string)($page['body'] ?? ''));
        $updated = bms_parse_markdown_string($raw);
        $newFilename = $updated['slug'] . '.md';
        $oldSlug = bms_slugify((string)($page['slug'] ?? pathinfo($file, PATHINFO_FILENAME)));
        $newSlug = bms_slugify((string)($updated['slug'] ?? pathinfo($newFilename, PATHINFO_FILENAME)));

        if ($newSlug !== $oldSlug && function_exists('bms_find_database_content_by_slug_status') && bms_find_database_content_by_slug_status($newSlug, $targetStatus, 'stream')) {
            throw new RuntimeException('Another stream post already uses this slug in the target status.');
        }

        if ($newSlug !== $oldSlug && function_exists('bms_find_database_content_by_slug_status')) {
            foreach (['draft', 'published', 'scheduled'] as $conflictStatus) {
                if ($conflictStatus !== $targetStatus && bms_find_database_content_by_slug_status($newSlug, $conflictStatus, 'stream')) {
                    throw new RuntimeException('Another stream post already uses this slug.');
                }
            }
        }

        if ($type === 'published' && function_exists('bms_record_revision_from_page')) {
            bms_record_revision_from_page($page, 'published', $file, $originalAuthorId);
        }

        if (function_exists('bms_delete_post_metadata_by_filename') && ($targetSection !== $section || $newFilename !== $file)) {
            bms_delete_post_metadata_by_filename($section, $file);
        }
        if ($targetStatus === 'scheduled' && function_exists('bms_schedule_post_page')) {
            bms_schedule_post_page($updated, 'scheduled', $newFilename, $originalAuthorId, (string)$scheduledAtUtc);
        } elseif (function_exists('bms_sync_stream_metadata')) {
            bms_sync_stream_metadata($updated, $targetSection, $newFilename, $originalAuthorId);
        }
        if ($targetStatus === 'published') {
            bms_flash('Quick edit saved. The live stream post is current through dynamic rendering.', 'success');
            bms_redirect(bms_admin_url('quick-edit.php?type=published&file=' . urlencode($newFilename)));
        }
        if ($targetStatus === 'scheduled') {
            bms_flash('Quick edit saved. Scheduled for ' . bms_format_scheduled_datetime((string)$scheduledAtUtc) . '.', 'success');
            bms_redirect(bms_admin_url('quick-edit.php?type=scheduled&file=' . urlencode($newFilename)));
        }
        bms_flash($section === 'scheduled' ? 'Schedule canceled. Draft details were updated.' : 'Quick edit saved. Draft details were updated.', 'success');
        bms_redirect(bms_admin_url('quick-edit.php?type=draft&file=' . urlencode($newFilename)));
    } catch (Throwable $e) {
        bms_log_admin_exception('quick-edit', $e);

        bms_flash('Quick edit failed. Please try again.', 'error');
        bms_redirect(bms_admin_url('quick-edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }
}

$headerActions = [
    ['label' => 'All Stream Posts', 'href' => bms_admin_url('content.php'), 'style' => 'secondary'],
    ['label' => 'Full Editor', 'href' => bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)), 'style' => 'primary'],
];
if ($type === 'published') {
    $headerActions[] = bms_view_stream_post_action($page, 'View Post');
}
bms_admin_header('Quick Edit', $headerActions);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Quick Edit</p>
  <h2><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></h2>
  <p class="meta">Change stream post details without opening the full editor.</p>
</section>

<section class="panel settings-panel quick-edit-panel">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
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
      <?php if ($type === 'published' || $type === 'scheduled' || bms_current_user_can('publish_content', bms_content_subject_for_file($section, $file, $page))): ?>
        <option value="published" <?= $type === 'published' ? 'selected' : '' ?>>Published</option>
        <option value="scheduled" <?= $type === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
      <?php endif; ?>
    </select>

    <label for="stream_date">Date</label>
    <input type="date" id="stream_date" name="stream_date" value="<?= htmlspecialchars((string)$page['date'], ENT_QUOTES, 'UTF-8') ?>" required>

    <?php if ($type === 'scheduled' || bms_current_user_can('publish_content', bms_content_subject_for_file($section, $file, $page))): ?>
      <?php $scheduledInput = function_exists('bms_utc_to_scheduled_input') ? bms_utc_to_scheduled_input((string)($page['scheduled_at'] ?? '')) : ''; ?>
      <label for="stream_scheduled_at">Scheduled publish time</label>
      <input type="datetime-local" id="stream_scheduled_at" name="stream_scheduled_at" value="<?= htmlspecialchars($scheduledInput, ENT_QUOTES, 'UTF-8') ?>">
      <p class="field-help">Required only when status is Scheduled. Uses site timezone: <strong><?= htmlspecialchars(function_exists('bms_site_timezone_name') ? bms_site_timezone_name() : date_default_timezone_get(), ENT_QUOTES, 'UTF-8') ?></strong>.</p>
    <?php endif; ?>

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
      <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('content.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
      <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)), ENT_QUOTES, 'UTF-8') ?>">Open Full Editor</a>
    </div>
  </form>
</section>
<?php bms_admin_footer(); ?>
