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
        bms_log_admin_exception('import-markdown', $e);
        $error = 'Markdown import could not be completed. Please try again.';
        bms_flash($error, 'error');
    }
}

bms_admin_header('Import Markdown', [
    ['label' => 'Upload Import', 'href' => bms_admin_url('import.php'), 'style' => 'secondary'],
]);
?>
<div class="system-workspace">
  <p class="system-intro">Advanced migration from private Markdown folders directly into database records.</p>
  <div class="system-warning"><strong>This operation writes immediately.</strong><p>There is no preview or dry run here. For an ordinary uploaded file, use <a href="<?= htmlspecialchars(bms_admin_url('import.php'), ENT_QUOTES, 'UTF-8') ?>">Import</a> to stage and review content first.</p></div>
  <dl class="system-facts"><div><dt>Source</dt><dd>Trusted files manually placed in the private content import folders.</dd></div><div><dt>Destination</dt><dd>Database content records. The database remains the runtime source of truth.</dd></div><div><dt>Normal import</dt><dd>Skips the folder import after an earlier import was marked complete.</dd></div></dl>
  <section class="system-section"><h2>Import private folders</h2>
    <form method="post" class="system-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <label class="system-checkbox"><input type="checkbox" name="force_import" value="1" aria-describedby="force-import-help"><span>Force re-import</span></label>
      <p class="field-help" id="force-import-help">Re-read previously imported files. This may refresh existing database records from private files. Keep a backup before forcing a refresh.</p>
      <div class="operations-form-actions"><button type="submit">Import Markdown Folders</button><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('tools.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a></div>
    </form>
  </section>
  <p class="system-note">After import, edit and publish through Bonumark Stream. Markdown remains a portability and migration format. <a href="<?= htmlspecialchars(bms_admin_url('export.php'), ENT_QUOTES, 'UTF-8') ?>">Review export options</a>.</p>
</div>
<?php bms_admin_footer(); ?>
