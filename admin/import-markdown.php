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
    ['label' => 'Import', 'href' => bms_admin_url('import.php'), 'style' => 'secondary'],
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
]);
?>
<section class="panel operations-hero">
  <div class="operations-hero-copy">
    <p class="eyebrow">Advanced migration</p>
    <h2>Read private Markdown folders into authoritative database records.</h2>
    <p class="meta">This is not the normal file-upload importer. Use it only after manually placing trusted Markdown files in the private content import folders.</p>
  </div>
  <span class="operation-risk-label is-warning">Advanced tool</span>
</section>

<section class="panel operations-summary-panel">
  <div class="operations-summary-grid">
    <div><span>Source</span><strong>Private Markdown folders</strong></div>
    <div><span>Destination</span><strong>Database content records</strong></div>
    <div><span>Default behavior</span><strong>Previously imported files skipped</strong></div>
    <div><span>Force option</span><strong>Re-read and refresh records</strong></div>
  </div>
</section>

<div class="operations-workflow-grid">
  <div class="operations-workflow-main">
    <section class="panel operations-review-panel">
      <div class="operations-panel-heading"><div><p class="eyebrow">Run import</p><h2>Import private Markdown folders</h2><p class="meta">The database becomes authoritative after import. Keep a backup before forcing a refresh over existing records.</p></div><span class="operation-risk-label is-sensitive">Writes database</span></div>
      <form method="post" class="operations-workflow-main">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <label class="operations-choice-card">
          <span><strong>Force re-import</strong></span>
          <span class="meta">Re-read Markdown files even when an earlier import was marked complete. This can refresh existing database records from the private files.</span>
          <span><input type="checkbox" name="force_import" value="1"> Re-read previously imported files</span>
        </label>
        <div class="operations-form-actions"><button type="submit">Import Markdown Folders</button><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('tools.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a></div>
      </form>
    </section>
  </div>
  <aside class="operations-workflow-rail is-sticky">
    <section class="panel operations-panel">
      <div class="operations-panel-heading"><div><p class="eyebrow">Before running</p><h2>Use the upload importer for ordinary files.</h2></div></div>
      <div class="operations-step-list">
        <div class="operations-step"><div><strong>Upload imports</strong><p>Use Import for a Markdown file, archive, XML export, JSON, or Bluesky data. It creates a reviewable preview first.</p></div></div>
        <div class="operations-step"><div><strong>Folder migration</strong><p>Use this page only when files already exist inside the private Bonumark Stream content folders.</p></div></div>
        <div class="operations-step"><div><strong>Database authority</strong><p>After import, edit and publish through Bonumark Stream. Markdown remains an ownership and migration format.</p></div></div>
      </div>
      <div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('import.php'), ENT_QUOTES, 'UTF-8') ?>">Open Normal Import</a><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('export.php'), ENT_QUOTES, 'UTF-8') ?>">Create Backup</a></div>
    </section>
  </aside>
</div>
<?php bms_admin_footer(); ?>
