<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('view_system');

$imported = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    try {
        if (!function_exists('bms_import_markdown_content_to_database')) {
            throw new RuntimeException('Markdown importer is unavailable.');
        }
        $force = !empty($_POST['force_import']);
        $imported = bms_import_markdown_content_to_database($force);
        bms_flash('Markdown import complete. Imported or refreshed ' . (int)$imported . ' content record(s).', 'success');
        bms_redirect(bms_admin_url('import-markdown.php'));
    } catch (Throwable $e) {
        $error = $e->getMessage();
        bms_flash('Markdown import failed. ' . $error, 'error');
    }
}

bms_admin_header('Import Markdown', [
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
    ['label' => 'Import', 'href' => bms_admin_url('import.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Database-First Content</p>
  <h2>Import Markdown into database records.</h2>
  <p class="meta">This tool reads old Markdown files from the private content folders and writes them into the database-first content table. Markdown files are read as import material and then database records become authoritative.</p>
</section>

<section class="panel settings-panel">
  <h3>Markdown Import</h3>
  <p class="field-help">Use this after manually placing Markdown files into the private Markdown import folders.</p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <label class="checkbox-label">
      <input type="checkbox" name="force_import" value="1">
      Re-read Markdown files even if an earlier import was marked complete.
    </label>
    <div class="form-actions-row">
      <button type="submit">Import Markdown</button>
      <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('tools.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
    </div>
  </form>
</section>
<?php bms_admin_footer(); ?>
