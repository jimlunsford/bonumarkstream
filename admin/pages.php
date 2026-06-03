<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_pages');

$status = (string)($_GET['status'] ?? 'all');
$status = in_array($status, ['all', 'draft', 'published', 'trash'], true) ? $status : 'all';
$q = trim((string)($_GET['q'] ?? ''));

$drafts = bms_list_page_records('draft');
$published = bms_list_page_records('published');
$trash = function_exists('bms_list_page_trash_items') ? bms_list_page_trash_items() : [];
foreach ($drafts as &$item) { $item['content_status'] = 'draft'; $item['section'] = 'pages/drafts'; }
unset($item);
foreach ($published as &$item) { $item['content_status'] = 'published'; $item['section'] = 'pages/published'; }
unset($item);

$items = $status === 'trash' ? $trash : array_merge($published, $drafts);
$items = array_values(array_filter($items, function (array $item) use ($status, $q): bool {
    $itemStatus = (string)($item['content_status'] ?? 'draft');
    if ($status !== 'all' && $status !== 'trash' && $itemStatus !== $status) {
        return false;
    }
    if ($status === 'trash' && $itemStatus !== 'trash') {
        return false;
    }
    if ($q !== '') {
        $haystack = strtolower(implode(' ', [(string)($item['title'] ?? ''), (string)($item['description'] ?? ''), (string)($item['body'] ?? ''), (string)($item['slug'] ?? ''), (string)($item['original_filename'] ?? '')]));
        if (!str_contains($haystack, strtolower($q))) {
            return false;
        }
    }
    return true;
}));
if ($status === 'trash') {
    usort($items, function (array $a, array $b): int {
        return strcmp((string)($b['deleted_at'] ?? ''), (string)($a['deleted_at'] ?? ''));
    });
} else {
    usort($items, function (array $a, array $b): int {
        return strcmp(strtolower((string)($a['title'] ?? '')), strtolower((string)($b['title'] ?? '')));
    });
}

$title = match ($status) {
    'draft' => 'Draft Pages',
    'published' => 'Published Pages',
    'trash' => 'Page Trash',
    default => 'Pages',
};

bms_admin_header($title, [
    ['label' => 'New Page', 'href' => bms_admin_url('page-new.php'), 'style' => 'primary'],
    bms_view_site_action(),
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Site Pages</p>
  <h2>Manage stable public pages.</h2>
  <p class="meta">Pages are for About, Contact, Privacy, services, and other site content that should not appear in the stream timeline.</p>
</section>

<nav class="content-filter" aria-label="Page status filters">
  <a class="<?= $status === 'all' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('pages.php'), ENT_QUOTES, 'UTF-8') ?>">All <span><?= count($drafts) + count($published) ?></span></a>
  <a class="<?= $status === 'draft' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('pages.php?status=draft'), ENT_QUOTES, 'UTF-8') ?>">Drafts <span><?= count($drafts) ?></span></a>
  <a class="<?= $status === 'published' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('pages.php?status=published'), ENT_QUOTES, 'UTF-8') ?>">Published <span><?= count($published) ?></span></a>
  <a class="<?= $status === 'trash' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('pages.php?status=trash'), ENT_QUOTES, 'UTF-8') ?>">Trash <span><?= count($trash) ?></span></a>
</nav>

<?php if ($status === 'trash' && $trash): ?>
  <form method="post" action="<?= htmlspecialchars(bms_admin_url('page-delete-permanent.php'), ENT_QUOTES, 'UTF-8') ?>" class="trash-empty-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="empty_page_trash" value="1">
    <button type="submit" class="danger">Empty Page Trash</button>
  </form>
<?php endif; ?>

<section class="panel content-list-panel">
  <div class="content-list-tools">
    <form method="get" class="content-search-form">
      <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search pages">
      <button type="submit" class="secondary-button">Search</button>
    </form>
  </div>
  <?php if (!$items): ?>
    <div class="empty-state compact-empty-state">
      <h3><?= $status === 'trash' ? 'Page trash is empty.' : 'No pages found.' ?></h3>
      <?php if ($status !== 'trash'): ?><p class="meta">Create stable site content without adding it to the stream timeline.</p><a class="primary-button" href="<?= htmlspecialchars(bms_admin_url('page-new.php'), ENT_QUOTES, 'UTF-8') ?>">New Page</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="content-table">
        <thead><tr><th>Title</th><th>Status</th><th>URL</th><th><?= $status === 'trash' ? 'Deleted' : 'Updated' ?></th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item):
            $itemStatus = (string)($item['content_status'] ?? 'draft');
            $file = (string)($item['filename'] ?? '');
            $titleText = (string)($item['title'] ?? 'Untitled Page');
            $description = (string)($item['description'] ?? '');
            $viewUrl = $itemStatus === 'published' ? bms_page_url_for_page($item) : '';
            $editUrl = $itemStatus !== 'trash' ? bms_admin_url('page-edit.php?type=' . ($itemStatus === 'published' ? 'published' : 'draft') . '&file=' . rawurlencode($file)) : '';
        ?>
          <tr>
            <td>
              <?php if ($itemStatus === 'trash'): ?>
                <strong><?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if ($description !== ''): ?><div class="table-subtext"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <div class="table-subtext">Original file: <?= htmlspecialchars((string)($item['original_filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              <?php else: ?>
                <strong><a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?></a></strong>
                <?php if ($description !== ''): ?><div class="table-subtext"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?php if ($itemStatus === 'trash'): ?><?= htmlspecialchars(function_exists('bms_page_trash_label') ? bms_page_trash_label((string)($item['original_status'] ?? 'page_draft')) : 'Page', ENT_QUOTES, 'UTF-8') ?><?php else: ?><?= $itemStatus === 'published' ? 'Published' : 'Draft' ?><?php endif; ?></td>
            <td><code><?= htmlspecialchars($viewUrl !== '' ? $viewUrl : bms_page_url((string)($item['slug'] ?? '')), ENT_QUOTES, 'UTF-8') ?></code></td>
            <td><?= htmlspecialchars($itemStatus === 'trash' ? (string)($item['deleted_at'] ?? '') : (string)($item['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="table-actions">
              <?php if ($itemStatus === 'trash'): ?>
                <form method="post" action="<?= htmlspecialchars(bms_admin_url('page-restore.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-form row-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="trash_id" value="<?= (int)($item['trash_id'] ?? 0) ?>">
                  <button type="submit" class="link-button">Restore</button>
                </form>
                <span>|</span>
                <form method="post" action="<?= htmlspecialchars(bms_admin_url('page-delete-permanent.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-form row-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="trash_id" value="<?= (int)($item['trash_id'] ?? 0) ?>">
                  <button type="submit" class="link-button danger-link">Delete Permanently</button>
                </form>
              <?php else: ?>
                <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                <?php if ($viewUrl !== ''): ?> <a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a><?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>
