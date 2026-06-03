<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

$slug = trim((string)($_GET['slug'] ?? ''));
$viewId = (int)($_GET['view'] ?? 0);
$viewRevision = $viewId > 0 ? bms_get_revision($viewId) : null;
if ($viewRevision) {
    bms_require_revision_access($viewRevision);
}
$revisions = bms_list_revisions($slug !== '' ? $slug : null, 150);

$title = $slug !== '' ? 'Revisions: ' . $slug : 'Revisions';
bms_admin_header($title, [
    ['label' => 'Stream Posts', 'href' => bms_admin_url('content.php'), 'style' => 'secondary'],
    ['label' => 'Trash', 'href' => bms_admin_url('content.php?status=trash'), 'style' => 'secondary'],
]);
?>
<?php if ($viewRevision): ?>
  <?php
    $revisionSource = (string)($viewRevision['content_body'] ?? '');
    if ($revisionSource === '') {
        $revisionPath = bms_root_path((string)($viewRevision['markdown_path'] ?? ''));
        $revisionSource = is_file($revisionPath) ? (string)file_get_contents($revisionPath) : '';
    }
  ?>
  <section class="panel revision-view-panel">
    <div class="section-header-row">
      <div>
        <h2>Revision Content</h2>
        <p class="meta"><?= htmlspecialchars((string)$viewRevision['created_at'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($viewRevision['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <form method="post" action="<?= htmlspecialchars(bms_admin_url('restore-revision.php'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="revision_id" value="<?= (int)$viewRevision['id'] ?>">
        <button type="submit" class="primary-button">Restore as Draft</button>
      </form>
      <form method="post" action="<?= htmlspecialchars(bms_admin_url('restore-revision.php'), ENT_QUOTES, 'UTF-8') ?>" data-confirm="Restore this revision over the current content? Bonumark Stream will archive the current version first.">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="revision_id" value="<?= (int)$viewRevision['id'] ?>">
        <input type="hidden" name="restore_mode" value="current">
        <button type="submit" class="secondary-button">Restore Over Current</button>
      </form>
    </div>
    <?php if ($revisionSource === ''): ?>
      <p class="notice warning">This revision content is unavailable.</p>
    <?php else: ?>
      <textarea class="revision-source-view" readonly><?= htmlspecialchars($revisionSource, ENT_QUOTES, 'UTF-8') ?></textarea>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="panel content-list-panel">
  <?php if (!$revisions): ?>
    <div class="empty-state">
      <h2>No revisions found.</h2>
      <p>Revisions appear after drafts or published Stream Posts are edited.</p>
    </div>
  <?php else: ?>
    <table class="admin-table content-table">
      <thead><tr><th>Stream Post</th><th>Status</th><th>Author</th><th>Created</th><th>Content</th></tr></thead>
      <tbody>
      <?php foreach ($revisions as $revision): ?>
        <?php
          $path = bms_root_path((string)($revision['markdown_path'] ?? ''));
          $hasDatabaseContent = trim((string)($revision['content_body'] ?? '')) !== '';
          $exists = $hasDatabaseContent || is_file($path);
          $displayTitle = trim((string)($revision['title'] ?? '')) ?: (string)$revision['slug'];
        ?>
        <tr>
          <td class="title-column">
            <strong><?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?></strong>
            <div class="row-actions">
              <a href="<?= htmlspecialchars(bms_admin_url('revisions.php?view=' . (int)$revision['id'] . ($slug !== '' ? '&slug=' . urlencode($slug) : '')), ENT_QUOTES, 'UTF-8') ?>">View Content</a>
              <span>|</span>
              <a href="<?= htmlspecialchars(bms_admin_url('compare-revision.php?id=' . (int)$revision['id']), ENT_QUOTES, 'UTF-8') ?>">Compare</a>
              <?php if ($exists): ?>
                <span>|</span>
                <form class="inline-form row-form" method="post" action="<?= htmlspecialchars(bms_admin_url('restore-revision.php'), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="revision_id" value="<?= (int)$revision['id'] ?>">
                  <button type="submit" class="link-button">Restore as Draft</button>
                </form>
                <span>|</span>
                <form class="inline-form row-form" method="post" action="<?= htmlspecialchars(bms_admin_url('restore-revision.php'), ENT_QUOTES, 'UTF-8') ?>" data-confirm="Restore this revision over the current content? Bonumark Stream will archive the current version first.">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="revision_id" value="<?= (int)$revision['id'] ?>">
                  <input type="hidden" name="restore_mode" value="current">
                  <button type="submit" class="link-button">Restore Current</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
          <td><span class="status-pill <?= htmlspecialchars((string)($revision['status'] ?? 'draft'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst((string)($revision['status'] ?? 'draft')), ENT_QUOTES, 'UTF-8') ?></span></td>
          <td><?= htmlspecialchars((string)($revision['author_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$revision['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= $exists ? '<span class="static-pill generated">Available</span>' : '<span class="static-pill warning">Unavailable</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<script src="<?= htmlspecialchars(bms_asset_url('assets/editor.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php bms_admin_footer(); ?>
