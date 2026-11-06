<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_pages');

function bms_pages_admin_status_label(string $status, array $item = []): string
{
    if ($status === 'trash') {
        return function_exists('bms_page_trash_label')
            ? bms_page_trash_label((string)($item['original_status'] ?? 'page_draft'))
            : 'Trash';
    }
    return $status === 'published' ? 'Published' : 'Draft';
}

function bms_pages_admin_preview_text(array $item, int $limit = 150): string
{
    $description = trim((string)($item['description'] ?? ''));
    $source = $description !== '' ? $description : trim((string)($item['body'] ?? ''));
    if ($source === '') {
        return '';
    }
    $source = preg_replace('/[`*_>#\[\]()-]+/', ' ', $source) ?? $source;
    $source = trim(preg_replace('/\s+/', ' ', strip_tags($source)) ?? $source);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return bms_text_length($source) > $limit ? rtrim(bms_text_substr($source, 0, $limit - 1)) . '…' : $source;
    }
    return strlen($source) > $limit ? rtrim(substr($source, 0, $limit - 1)) . '…' : $source;
}

$status = (string)($_GET['status'] ?? 'all');
$status = in_array($status, ['all', 'draft', 'published', 'trash'], true) ? $status : 'all';
$q = trim((string)($_GET['q'] ?? ''));

$drafts = bms_list_page_records('draft');
$published = bms_list_page_records('published');
$trash = function_exists('bms_list_page_trash_items') ? bms_list_page_trash_items() : [];
foreach ($drafts as &$item) {
    $item['content_status'] = 'draft';
    $item['section'] = 'pages/drafts';
}
unset($item);
foreach ($published as &$item) {
    $item['content_status'] = 'published';
    $item['section'] = 'pages/published';
}
unset($item);

$items = $status === 'trash' ? $trash : array_merge($published, $drafts);
$items = array_values(array_filter($items, function (array $item) use ($status, $q): bool {
    $itemStatus = (string)($item['content_status'] ?? 'draft');
    if ($status !== 'all' && $itemStatus !== $status) {
        return false;
    }
    if ($q !== '') {
        $haystack = strtolower(implode(' ', [
            (string)($item['title'] ?? ''),
            (string)($item['description'] ?? ''),
            (string)($item['body'] ?? ''),
            (string)($item['slug'] ?? ''),
            (string)($item['original_filename'] ?? ''),
        ]));
        if (!str_contains($haystack, strtolower($q))) {
            return false;
        }
    }
    return true;
}));

if ($status === 'trash') {
    usort($items, static function (array $a, array $b): int {
        return strcmp((string)($b['deleted_at'] ?? ''), (string)($a['deleted_at'] ?? ''));
    });
} else {
    usort($items, static function (array $a, array $b): int {
        return strcmp(strtolower((string)($a['title'] ?? '')), strtolower((string)($b['title'] ?? '')));
    });
}

bms_admin_header('Pages', [
    ['label' => 'New Page', 'href' => bms_admin_url('page-new.php'), 'style' => 'primary'],
]);
?>
<nav class="content-filter pages-content-filter" aria-label="Page status filters">
  <a class="<?= $status === 'all' ? 'active' : '' ?>"<?= $status === 'all' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('pages.php'), ENT_QUOTES, 'UTF-8') ?>">All <span><?= count($drafts) + count($published) ?></span></a>
  <a class="<?= $status === 'draft' ? 'active' : '' ?>"<?= $status === 'draft' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('pages.php?status=draft'), ENT_QUOTES, 'UTF-8') ?>">Drafts <span><?= count($drafts) ?></span></a>
  <a class="<?= $status === 'published' ? 'active' : '' ?>"<?= $status === 'published' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('pages.php?status=published'), ENT_QUOTES, 'UTF-8') ?>">Published <span><?= count($published) ?></span></a>
  <a class="<?= $status === 'trash' ? 'active' : '' ?>"<?= $status === 'trash' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('pages.php?status=trash'), ENT_QUOTES, 'UTF-8') ?>">Trash <span><?= count($trash) ?></span></a>
</nav>

<?php if ($status === 'trash' && $trash): ?>
  <form method="post" action="<?= htmlspecialchars(bms_admin_url('page-delete-permanent.php'), ENT_QUOTES, 'UTF-8') ?>" class="trash-empty-form" aria-describedby="pages-empty-trash-warning">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="empty_page_trash" value="1">
    <p id="pages-empty-trash-warning">Permanently delete all <?= count($trash) ?> page<?= count($trash) === 1 ? '' : 's' ?> in Page Trash. This cannot be undone.</p>
    <button type="submit" class="danger" aria-describedby="pages-empty-trash-warning">Empty Page Trash</button>
  </form>
<?php endif; ?>

<section class="content-list-panel content-record-panel pages-record-panel" aria-label="Manage Pages">
  <div class="content-list-search-region">
    <form method="get" class="content-search-form">
      <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
      <div class="content-search-field">
        <label for="pages_q">Search pages</label>
        <input id="pages_q" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Title, text, or slug">
      </div>
      <div class="content-search-actions">
        <button type="submit" class="secondary">Search</button>
        <?php if ($q !== ''): ?>
          <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('pages.php' . ($status !== 'all' ? '?status=' . rawurlencode($status) : '')), ENT_QUOTES, 'UTF-8') ?>">Clear search</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <?php if (!$items): ?>
    <div class="empty-state content-list-empty-state">
      <h2><?= $q !== '' ? 'No matching pages.' : match ($status) {
          'trash' => 'Page Trash is empty.',
          'draft' => 'No draft pages.',
          'published' => 'No published pages.',
          default => 'No pages yet.',
      } ?></h2>
      <p><?= $q !== '' ? 'Try different search terms or clear the search to see all pages in this view.' : match ($status) {
          'trash' => 'Pages moved to Trash remain recoverable here until permanently deleted.',
          'draft' => 'Save a new Page as a draft, or choose another status to find your pages.',
          'published' => 'Publish a draft to make it available at its public URL.',
          default => 'Create an About, Contact, or other Page for your site.',
      } ?></p>
      <?php if ($q === '' && $status !== 'trash'): ?><a class="primary-button" href="<?= htmlspecialchars(bms_admin_url('page-new.php'), ENT_QUOTES, 'UTF-8') ?>">New Page</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="content-list-controlbar pages-list-controlbar">
      <div class="content-list-summary">
        <span><?= count($items) ?> page<?= count($items) === 1 ? '' : 's' ?> shown</span>
        <span><?= $status === 'trash' ? 'Newest deleted first' : 'Sorted by title' ?></span>
      </div>
    </div>

    <div class="content-record-header page-content-record-header" aria-hidden="true">
      <span>Page</span>
      <span>Status</span>
      <span><?= $status === 'trash' ? 'Deleted' : 'Date' ?></span>
      <span>Actions</span>
    </div>

    <div class="content-record-list page-content-record-list" role="list" aria-label="Pages">
      <?php foreach ($items as $item): ?>
        <?php
          $itemStatus = (string)($item['content_status'] ?? 'draft');
          $file = (string)($item['filename'] ?? '');
          $titleText = trim((string)($item['title'] ?? '')) ?: 'Untitled Page';
          $slug = trim((string)($item['slug'] ?? ''));
          $previewText = bms_pages_admin_preview_text($item);
          $viewUrl = $itemStatus === 'published' ? bms_page_url_for_page($item) : '';
          $displayUrl = $slug !== '' ? bms_page_url($slug) : '';
          $editUrl = $itemStatus !== 'trash'
              ? bms_admin_url('page-edit.php?type=' . ($itemStatus === 'published' ? 'published' : 'draft') . '&file=' . rawurlencode($file))
              : '';
          $displayDate = $itemStatus === 'trash'
              ? trim((string)($item['deleted_at'] ?? ''))
              : trim((string)($item['date'] ?? ''));
          $statusLabel = $itemStatus === 'trash' ? 'Trash' : bms_pages_admin_status_label($itemStatus, $item);
        ?>
        <article class="content-record page-content-record content-record-<?= htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8') ?>" role="listitem">
          <div class="content-record-main">
            <div class="content-record-title-row">
              <?php if ($itemStatus === 'trash'): ?>
                <h2><?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?></h2>
              <?php else: ?>
                <h2><a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?></a></h2>
              <?php endif; ?>
            </div>

            <?php if ($previewText !== ''): ?>
              <p class="content-record-preview"><?= htmlspecialchars($previewText, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if ($displayUrl !== ''): ?>
              <p class="page-content-record-path"><span><?= $itemStatus === 'published' ? 'Public URL' : ($itemStatus === 'trash' ? 'Previous URL' : 'URL when published') ?></span><code><?= htmlspecialchars($displayUrl, ENT_QUOTES, 'UTF-8') ?></code></p>
            <?php endif; ?>

            <?php if ($itemStatus === 'trash'): ?>
              <p class="page-content-record-origin">Originally <?= htmlspecialchars(bms_pages_admin_status_label($itemStatus, $item), ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>

          <div class="content-record-status">
            <span class="status-pill <?= htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
          </div>

          <div class="content-record-date">
            <span class="content-record-mobile-label"><?= $itemStatus === 'trash' ? 'Deleted' : 'Date' ?></span>
            <time><?= htmlspecialchars($displayDate !== '' ? $displayDate : 'Unknown', ENT_QUOTES, 'UTF-8') ?></time>
          </div>

          <div class="content-record-actions">
            <?php if ($editUrl !== ''): ?><a class="content-record-edit" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Edit <?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?>">Edit</a><?php endif; ?>
            <details class="content-actions-menu" data-content-actions>
              <summary aria-label="Actions for <?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?>">Actions</summary>
              <div class="content-actions-menu-panel">
                <?php if ($itemStatus === 'trash'): ?>
                  <form method="post" action="<?= htmlspecialchars(bms_admin_url('page-restore.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="trash_id" value="<?= (int)($item['trash_id'] ?? 0) ?>">
                    <button type="submit" class="content-action-button state-link">Restore</button>
                  </form>
                  <form method="post" action="<?= htmlspecialchars(bms_admin_url('page-delete-permanent.php'), ENT_QUOTES, 'UTF-8') ?>" aria-describedby="page-delete-warning-<?= (int)($item['trash_id'] ?? 0) ?>">
                    <p class="page-action-warning" id="page-delete-warning-<?= (int)($item['trash_id'] ?? 0) ?>">Permanently deletes this Page. This cannot be undone.</p>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="trash_id" value="<?= (int)($item['trash_id'] ?? 0) ?>">
                    <button type="submit" class="content-action-button danger-link" aria-describedby="page-delete-warning-<?= (int)($item['trash_id'] ?? 0) ?>">Delete permanently</button>
                  </form>
                <?php else: ?>
                  <a href="<?= htmlspecialchars(bms_admin_url('preview.php?type=' . ($itemStatus === 'published' ? 'page-published' : 'page-draft') . '&file=' . rawurlencode($file)), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Preview</a>
                  <?php if ($viewUrl !== ''): ?><a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View page</a><?php endif; ?>
                  <?php if ($itemStatus === 'published'): ?>
                    <form method="post" action="<?= htmlspecialchars(bms_admin_url('page-unpublish.php'), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                      <button type="submit" class="content-action-button state-link">Move to drafts</button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="<?= htmlspecialchars(bms_admin_url('page-publish.php'), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                      <button type="submit" class="content-action-button state-link">Publish page</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?= htmlspecialchars(bms_admin_url('page-delete.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($itemStatus === 'published' ? 'published' : 'draft', ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="content-action-button danger-link">Move to Trash</button>
                  </form>
                <?php endif; ?>
              </div>
            </details>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>
