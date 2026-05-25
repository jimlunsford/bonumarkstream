<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('view_system');

$imported = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    try {
        if (!function_exists('mp_import_legacy_markdown_content_to_database')) {
            throw new RuntimeException('Legacy Markdown importer is unavailable.');
        }
        $force = !empty($_POST['force_import']);
        $imported = mp_import_legacy_markdown_content_to_database($force);
        mp_flash('Legacy Markdown import complete. Imported or refreshed ' . (int)$imported . ' content record(s).', 'success');
        mp_redirect(mp_admin_url('import-markdown.php'));
    } catch (Throwable $e) {
        $error = $e->getMessage();
        mp_flash('Legacy Markdown import failed. ' . $error, 'error');
    }
}

mp_admin_header('Import Legacy Markdown', [
    ['label' => 'Tools', 'href' => mp_admin_url('tools.php'), 'style' => 'secondary'],
    ['label' => 'Import', 'href' => mp_admin_url('import.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Database-First Content</p>
  <h2>Import legacy Markdown into database records.</h2>
  <p class="meta">This tool reads old Markdown files from the private content folders and writes them into the database-first content table. Markdown files are read as import material and then database records become authoritative.</p>
</section>

<section class="panel settings-panel">
  <h3>Legacy Markdown Import</h3>
  <p class="field-help">Use this after upgrading older test installs or after manually placing old Markdown files into the legacy content folders.</p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <label class="checkbox-label">
      <input type="checkbox" name="force_import" value="1">
      Re-read legacy Markdown files even if an earlier import was marked complete.
    </label>
    <div class="form-actions-row">
      <button type="submit">Import Legacy Markdown</button>
      <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('tools.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
    </div>
  </form>
</section>
<?php mp_admin_footer(); ?>
