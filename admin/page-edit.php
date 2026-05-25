<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
require_once __DIR__ . '/../_bonumark_stream/app/editor.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_pages');

$type = (string)($_GET['type'] ?? $_POST['type'] ?? 'draft');
$type = $type === 'published' ? 'published' : 'draft';
$section = $type === 'published' ? 'pages/published' : 'pages/drafts';
$file = basename((string)($_GET['file'] ?? $_POST['file'] ?? ''));
$path = $file !== '' ? mp_content_path($section . '/' . $file) : '';
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
    mp_admin_error_page('Page not found', 'The requested page content record could not be found.', 404, [
        ['label' => 'All Pages', 'href' => mp_admin_url('pages.php'), 'style' => 'primary'],
    ]);
}
mp_require_content_file_access($section, $file, 'edit_content', $page);
$originalAuthorId = function_exists('mp_content_author_id_for_file') ? mp_content_author_id_for_file($section, $file) : null;
if ($originalAuthorId === null && (int)($page['author_id'] ?? 0) > 0) {
    $originalAuthorId = (int)$page['author_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $status = $section === 'pages/published' ? 'published' : 'draft';
    $raw = mp_build_page_markdown_from_request($status, (string)($page['slug'] ?? ''));
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    if (trim((string)($_POST['page_title'] ?? '')) === '') {
        mp_flash('Page title is required.', 'error');
        mp_redirect(mp_admin_url('page-edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }
    if (trim((string)($_POST['body_markdown'] ?? '')) === '') {
        mp_flash('Page body cannot be empty.', 'error');
        mp_redirect(mp_admin_url('page-edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }
    try {
        $updatedPage = mp_parse_markdown_string($raw);
        $newFilename = $updatedPage['slug'] . '.md';
        $oldSlug = mp_slugify((string)($page['slug'] ?? pathinfo($file, PATHINFO_FILENAME)));
        $newSlug = mp_slugify((string)($updatedPage['slug'] ?? pathinfo($newFilename, PATHINFO_FILENAME)));
        $targetStatus = $section === 'pages/published' ? 'published' : 'draft';
        if ($oldSlug !== $newSlug && function_exists('mp_find_database_content_by_slug_status') && mp_find_database_content_by_slug_status($newSlug, $targetStatus, 'page')) {
            throw new RuntimeException('Another page already uses this slug.');
        }
        if ($section === 'pages/published' && $oldSlug !== $newSlug && empty($_POST['confirm_slug_change'])) {
            mp_flash('Confirm the live URL change before saving this published page.', 'warning');
            mp_redirect(mp_admin_url('page-edit.php?type=published&file=' . urlencode($file)));
        }
        if (function_exists('mp_delete_post_metadata_by_filename') && $newFilename !== $file) {
            mp_delete_post_metadata_by_filename($section, $file);
        }
        if (is_file($path)) {
            @unlink($path);
        }
        mp_sync_page_metadata($updatedPage, $section, $newFilename, $originalAuthorId);
        if ($section === 'pages/published') {
            mp_flash('Updated page. “' . $updatedPage['title'] . '” is current through dynamic rendering.', 'success');
            mp_redirect(mp_admin_url('page-edit.php?type=published&file=' . urlencode($newFilename)));
        }
        mp_flash('Draft page saved. “' . $updatedPage['title'] . '” is ready to preview or publish.', 'success');
        mp_redirect(mp_admin_url('page-edit.php?type=draft&file=' . urlencode($newFilename)));
    } catch (Throwable $e) {
        mp_flash('Save failed. ' . $e->getMessage(), 'error');
        mp_redirect(mp_admin_url('page-edit.php?type=' . urlencode($type) . '&file=' . urlencode($file)));
    }
}

mp_admin_header('Edit Page: ' . $page['title'], [
    ['label' => 'All Pages', 'href' => mp_admin_url('pages.php'), 'style' => 'secondary'],
    $section === 'pages/published' ? ['label' => 'View Page', 'href' => mp_page_url_for_page($page), 'style' => 'secondary', 'target' => true] : ['label' => 'Preview', 'href' => mp_admin_url('preview.php?type=page-draft&file=' . urlencode($file)), 'style' => 'secondary'],
]);
?>
<section class="editor-panel editor-composer-panel">
  <div class="composer-top-row" aria-label="Editor options">
    <p class="editor-page-helper"><span class="editor-page-helper-pill"><?= $section === 'pages/published' ? 'Published Page' : 'Draft Page' ?></span> Editing database content record: <code><?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?></code></p>
  </div>
  <form id="stream-editor-form" method="post" class="editor-form editor-layout-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="content_kind" value="page">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
    <div class="editor-workspace">
      <div class="editor-primary-column">
        <?php mp_page_title_fields($page); ?>
        <?php mp_dual_editor((string)($page['body'] ?? '')); ?>
      </div>
      <aside class="editor-sidebar-column">
        <?php mp_publish_sidebar($section, $section === 'pages/published' ? 'Update Page' : 'Save Draft', '', ['mode' => 'edit', 'content_type' => 'page', 'file' => $file, 'page' => $page]); ?>
        <?php mp_page_url_fields($page, $section); ?>
        <?php mp_page_settings_fields($page); ?>
      </aside>
    </div>
  </form>
  <div class="editor-hidden-action-forms" aria-hidden="true">
    <?php if ($section !== 'pages/published'): ?>
      <form id="publish-draft-action-form" method="post" action="<?= htmlspecialchars(mp_admin_url('page-publish.php'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
      </form>
    <?php else: ?>
      <form id="unpublish-post-action-form" method="post" action="<?= htmlspecialchars(mp_admin_url('page-unpublish.php'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
      </form>
    <?php endif; ?>
    <form id="trash-post-action-form" method="post" action="<?= htmlspecialchars(mp_admin_url('page-delete.php'), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
    </form>
  </div>
</section>
<?php mp_editor_script_tag(); ?>
<?php mp_admin_footer(); ?>
