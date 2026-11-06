<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

function bms_content_status_label(string $status): string
{
    return match ($status) {
        'published' => 'Published',
        'pinned' => 'Pinned',
        'scheduled' => 'Scheduled',
        'trash' => 'Trash',
        default => 'Draft',
    };
}

function bms_content_selected_items(): array
{
    $selected = $_POST['selected'] ?? [];
    if (!is_array($selected)) {
        return [];
    }
    $items = [];
    foreach ($selected as $value) {
        $parts = explode('|', (string)$value, 2);
        if (count($parts) !== 2) {
            continue;
        }
        if ($parts[0] === 'trash') {
            $id = (int)$parts[1];
            if ($id > 0) {
                $items[] = ['type' => 'trash', 'id' => $id];
            }
            continue;
        }
        $type = in_array($parts[0], ['published', 'scheduled'], true) ? $parts[0] : 'draft';
        $file = basename($parts[1]);
        if ($file !== '') {
            $items[] = ['type' => $type, 'file' => $file];
        }
    }
    return $items;
}

$status = $_GET['status'] ?? 'all';
$status = in_array($status, ['all', 'draft', 'scheduled', 'published', 'pinned', 'trash'], true) ? $status : 'all';
$statusRedirect = $status !== 'all' ? '?status=' . rawurlencode($status) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    if (function_exists('set_time_limit')) {
        @set_time_limit(240);
    }
    $bulkAction = (string)($_POST['bulk_action'] ?? '');
    $allowedBulkActions = ['publish', 'unpublish', 'trash', 'restore', 'delete_permanent'];
    $selected = bms_content_selected_items();
    $done = 0;
    $failed = 0;

    if (!in_array($bulkAction, $allowedBulkActions, true)) {
        bms_flash('Nothing changed. Choose a bulk action first.', 'info');
        bms_redirect(bms_admin_url('content.php' . $statusRedirect));
    }

    foreach ($selected as $item) {
        try {
            if ($bulkAction === 'publish' && in_array($item['type'], ['draft', 'scheduled'], true)) {
                bms_require_content_file_access($item['type'] === 'scheduled' ? 'scheduled' : 'drafts', $item['file'], 'publish_content');
                bms_publish_file($item['file']);
                $done++;
            } elseif ($bulkAction === 'unpublish' && $item['type'] === 'published') {
                bms_require_content_file_access('published', $item['file'], 'publish_content');
                bms_unpublish_file($item['file']);
                $done++;
            } elseif ($bulkAction === 'trash' && in_array($item['type'], ['draft', 'scheduled', 'published'], true)) {
                bms_require_content_file_access($item['type'] === 'published' ? 'published' : ($item['type'] === 'scheduled' ? 'scheduled' : 'drafts'), $item['file'], 'edit_content');
                bms_delete_content_file($item['type'], $item['file']);
                if ($item['type'] === 'published') {
                }
                $done++;
            } elseif ($bulkAction === 'restore' && $item['type'] === 'trash') {
                bms_require_trash_item_access((int)$item['id']);
                $restored = bms_restore_trash_item((int)$item['id']);
                if (($restored['restored_status'] ?? '') === 'published') {
                }
                $done++;
            } elseif ($bulkAction === 'delete_permanent' && $item['type'] === 'trash') {
                bms_require_trash_item_access((int)$item['id']);
                bms_delete_trash_item_permanently((int)$item['id']);
                $done++;
            }
        } catch (Throwable $e) {
            $failed++;
        }
    }


    if ($done > 0) {
        $label = match ($bulkAction) {
            'publish' => 'published',
            'unpublish' => 'moved to drafts',
            'trash' => 'moved to Trash',
            'restore' => 'restored',
            'delete_permanent' => 'permanently deleted',
            default => 'updated',
        };
        $message = 'Bulk action complete. ' . $done . ' post' . ($done === 1 ? '' : 's') . ' ' . $label . '.';
        if ($failed > 0) {
            $message .= ' ' . $failed . ' selected item' . ($failed === 1 ? '' : 's') . ' could not be changed.';
        }
        bms_flash($message, $failed > 0 ? 'warning' : 'success');
    } else {
        bms_flash('Nothing changed. Select stream posts first, then choose a bulk action.', $failed > 0 ? 'warning' : 'info');
    }
    bms_redirect(bms_admin_url('content.php' . $statusRedirect));
}
$q = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'date_desc');
$sort = in_array($sort, ['date_desc', 'date_asc'], true) ? $sort : 'date_desc';

$drafts = bms_filter_stream_posts(bms_list_content_records('drafts'));
$published = bms_filter_stream_posts(bms_list_content_records('published'));
$scheduled = bms_filter_stream_posts(bms_list_content_records('scheduled'));
$trash = function_exists('bms_list_trash_items') ? bms_filter_stream_posts(bms_list_trash_items()) : [];
$pinnedCount = count(array_filter($published, static fn (array $item): bool => function_exists('bms_is_pinned_stream_post') && bms_is_pinned_stream_post($item)));

$allItems = [];
foreach ($drafts as $item) {
    $item['content_status'] = 'draft';
    $allItems[] = $item;
}
foreach ($scheduled as $item) {
    $item['content_status'] = 'scheduled';
    $allItems[] = $item;
}
foreach ($published as $item) {
    $item['content_status'] = 'published';
    $allItems[] = $item;
}
if ($status === 'trash') {
    $allItems = $trash;
}
if (function_exists('bms_filter_content_items_for_current_user')) {
    $drafts = bms_filter_content_items_for_current_user($drafts);
    $published = bms_filter_content_items_for_current_user($published);
    $scheduled = bms_filter_content_items_for_current_user($scheduled);
    $trash = bms_filter_content_items_for_current_user($trash);
    $allItems = bms_filter_content_items_for_current_user($allItems);
}

$items = array_filter($allItems, function ($item) use ($status, $q) {
    $itemStatus = (string)($item['content_status'] ?? 'draft');
    if ($status === 'pinned') {
        if (!(function_exists('bms_is_pinned_stream_post') && bms_is_pinned_stream_post($item))) {
            return false;
        }
    } elseif ($status !== 'all' && $itemStatus !== $status) {
        return false;
    }
    if ($q !== '') {
        $haystack = strtolower(implode(' ', [
            (string)($item['title'] ?? ''),
            (string)($item['description'] ?? ''),
            (string)($item['body'] ?? ''),
            (string)($item['slug'] ?? ''),
        ]));
        if (!str_contains($haystack, strtolower($q))) {
            return false;
        }
    }
    return true;
});

$items = array_values($items);
if ($status === 'trash') {
    usort($items, function ($a, $b) use ($sort) {
        $left = (string)($a['deleted_at'] ?? $a['date'] ?? '');
        $right = (string)($b['deleted_at'] ?? $b['date'] ?? '');
        return $sort === 'date_asc' ? strcmp($left, $right) : strcmp($right, $left);
    });
} else {
    if ($status === 'pinned') {
        usort($items, static function (array $left, array $right): int {
            $leftPinnedAt = (string)($left['pinned_at'] ?? '');
            $rightPinnedAt = (string)($right['pinned_at'] ?? '');
            return strcmp($rightPinnedAt, $leftPinnedAt);
        });
    } else {
        $items = bms_sort_stream_posts($items);
        if ($sort === 'date_asc') {
            $items = array_reverse($items);
        }
    }
}
// Paginate the existing filtered and ordered list without changing its counts.
$totalItems = count($items);
$perPage = 25;
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$currentPage = min($totalPages, max(1, (int)(filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1)));
$pageOffset = ($currentPage - 1) * $perPage;
$items = array_slice($items, $pageOffset, $perPage);
$pageUrl = static fn (int $page): string => bms_admin_url('content.php' . bms_query_string([
    'status' => $status !== 'all' ? $status : '',
    'q' => $q,
    'sort' => $sort,
    'page' => $page,
]));
$canEmptyTrash = true;
bms_admin_header('Content');
?>
<div class="content-view-context">
  <p class="content-timezone">Dates: <?= htmlspecialchars($status === 'trash' ? 'UTC' : bms_site_timezone_name(), ENT_QUOTES, 'UTF-8') ?></p>
</div>
<nav class="content-filter" aria-label="Stream post status filters">
  <a class="<?= $status === 'all' ? 'active' : '' ?>"<?= $status === 'all' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('content.php'), ENT_QUOTES, 'UTF-8') ?>">All <span><?= count($drafts) + count($scheduled) + count($published) ?></span></a>
  <a class="<?= $status === 'draft' ? 'active' : '' ?>"<?= $status === 'draft' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('content.php?status=draft'), ENT_QUOTES, 'UTF-8') ?>">Drafts <span><?= count($drafts) ?></span></a>
  <a class="<?= $status === 'scheduled' ? 'active' : '' ?>"<?= $status === 'scheduled' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('content.php?status=scheduled'), ENT_QUOTES, 'UTF-8') ?>">Scheduled <span><?= count($scheduled) ?></span></a>
  <a class="<?= $status === 'published' ? 'active' : '' ?>"<?= $status === 'published' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('content.php?status=published'), ENT_QUOTES, 'UTF-8') ?>">Published <span><?= count($published) ?></span></a>
  <a class="<?= $status === 'pinned' ? 'active' : '' ?>"<?= $status === 'pinned' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('content.php?status=pinned'), ENT_QUOTES, 'UTF-8') ?>">Pinned <span><?= $pinnedCount ?></span></a>
  <a class="<?= $status === 'trash' ? 'active' : '' ?>"<?= $status === 'trash' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('content.php?status=trash'), ENT_QUOTES, 'UTF-8') ?>">Trash <span><?= count($trash) ?></span></a>
</nav>


<?php if ($status === 'trash' && $trash && $canEmptyTrash): ?>
  <form method="post" action="<?= htmlspecialchars(bms_admin_url('delete-permanent.php'), ENT_QUOTES, 'UTF-8') ?>" class="trash-empty-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="empty_trash" value="1">
    <button type="submit" class="danger">Empty Trash</button>
  </form>
<?php endif; ?>

<section class="content-list-panel content-record-panel" aria-label="Manage Stream posts">
  <div class="content-list-search-region">
    <form class="content-search-form" method="get">
      <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
      <div class="content-search-field">
        <label for="content_q">Search posts</label>
        <input id="content_q" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Title, text, or slug">
      </div>
      <div class="content-sort-field">
        <label for="content_sort">Sort</label>
        <?php if ($status === 'pinned'): ?>
          <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
          <select id="content_sort" disabled aria-describedby="content-pinned-order"><option>Newest pin first</option></select>
        <?php else: ?>
          <select id="content_sort" name="sort">
            <option value="date_desc"<?= $sort === 'date_desc' ? ' selected' : '' ?>>Newest first</option>
            <option value="date_asc"<?= $sort === 'date_asc' ? ' selected' : '' ?>>Oldest first</option>
          </select>
        <?php endif; ?>
      </div>
      <div class="content-search-actions">
        <button type="submit" class="secondary">Search</button>
        <?php if ($q !== ''): ?>
          <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('content.php' . bms_query_string(['status' => $status !== 'all' ? $status : '', 'sort' => $sort])), ENT_QUOTES, 'UTF-8') ?>">Clear search</a>
        <?php endif; ?>
      </div>
    </form>
    <?php if ($status === 'pinned'): ?><p id="content-pinned-order" class="content-results-summary">Pinned posts are ordered by when they were pinned, newest first.</p><?php endif; ?>
  </div>

  <?php if (!$items): ?>
    <div class="empty-state content-list-empty-state">
      <h2><?= $q !== '' ? 'No matching posts.' : ($status === 'trash' ? 'Trash is empty.' : 'No posts in this view.') ?></h2>
      <p><?= $q !== '' ? 'Try different search terms or clear the search to see all posts in this view.' : ($status === 'trash' ? 'Posts moved to Trash will appear here.' : ($status === 'all' ? 'Create your first Stream post from the Stream composer. Save a draft there when you need the full editor.' : 'Choose another status to find your posts.')) ?></p>
      <a href="<?= htmlspecialchars(bms_admin_url('revisions.php'), ENT_QUOTES, 'UTF-8') ?>">Revisions</a>
    </div>
  <?php else: ?>
    <div class="content-list-controlbar">
      <form id="bulk-content-form" method="post" class="bulk-content-form content-list-bulk-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <label class="content-list-select-all">
          <input type="checkbox" data-select-all data-select-scope="#stream-content-list">
          <span>Select this page</span>
        </label>
        <div class="content-bulk-field">
        <label for="content_bulk_action">Bulk action</label>
        <select id="content_bulk_action" name="bulk_action">
          <option value="">Bulk actions</option>
          <?php if ($status === 'trash'): ?>
            <option value="restore">Restore selected</option>
            <option value="delete_permanent">Delete selected permanently</option>
          <?php else: ?>
            <option value="publish">Publish selected drafts</option>
            <option value="unpublish">Move selected published posts to drafts</option>
            <option value="trash">Move selected to Trash</option>
          <?php endif; ?>
        </select>
        </div>
        <button type="submit" class="secondary">Apply</button>
      </form>
      <div class="content-list-summary">
        <a href="<?= htmlspecialchars(bms_admin_url('revisions.php'), ENT_QUOTES, 'UTF-8') ?>">Revisions</a>
      </div>
    </div>
    <noscript><p class="content-results-summary">Select posts individually, then choose a bulk action and Apply.</p></noscript>

    <div class="content-record-header" aria-hidden="true">
      <span></span>
      <span>Post</span>
      <span>Status</span>
      <span>Date</span>
      <span>Actions</span>
    </div>
    <div id="stream-content-list" class="content-record-list" role="list" aria-label="Stream posts">
      <?php foreach ($items as $item): ?>
        <?php
          $itemStatus = (string)($item['content_status'] ?? 'draft');
          $itemType = $itemStatus === 'published' ? 'published' : ($itemStatus === 'scheduled' ? 'scheduled' : ($itemStatus === 'trash' ? 'trash' : 'draft'));
          $file = (string)($item['filename'] ?? '');
          $canPublishItem = $itemStatus !== 'trash' && function_exists('bms_current_user_can') && function_exists('bms_content_subject_for_file') ? bms_current_user_can('publish_content', bms_content_subject_for_file($itemStatus === 'published' ? 'published' : ($itemStatus === 'scheduled' ? 'scheduled' : 'drafts'), $file, $item)) : false;
          $itemTitle = trim((string)($item['title'] ?? ''));
          if ($itemTitle === '' || str_starts_with(strtolower($itemTitle), 'stream ')) {
              $itemTitle = bms_stream_admin_title_from_body((string)($item['body'] ?? ''), (string)($item['stream_created_at'] ?? $item['date'] ?? ''));
          }
          $itemPreview = bms_stream_preview_text($item, 120);
          $hasMedia = trim((string)($item['featured_media'] ?? $item['front_matter']['featured_media'] ?? '')) !== '';
          $isPinned = function_exists('bms_is_pinned_stream_post') && bms_is_pinned_stream_post($item);
          $displayDate = $itemStatus === 'trash'
              ? trim((string)($item['deleted_at'] ?? ''))
              : bms_stream_display_date($item);
          $editUrl = $itemStatus === 'trash' ? '' : bms_admin_url('edit.php?type=' . urlencode($itemType) . '&file=' . urlencode($file));
        ?>
        <article class="content-record content-record-<?= htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8') ?>" role="listitem">
          <label class="content-record-select">
            <input type="checkbox" form="bulk-content-form" name="selected[]" value="<?= htmlspecialchars($itemType . '|' . ($itemType === 'trash' ? (int)$item['trash_id'] : $file), ENT_QUOTES, 'UTF-8') ?>" aria-label="Select <?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?>">
          </label>

          <div class="content-record-main">
            <div class="content-record-title-row">
              <?php if ($itemStatus === 'trash'): ?>
                <h2><?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></h2>
              <?php else: ?>
                <h2><a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></a></h2>
              <?php endif; ?>
            </div>

            <?php if ($itemPreview !== ''): ?>
              <p class="content-record-preview"><?= htmlspecialchars($itemPreview, ENT_QUOTES, 'UTF-8') ?></p>
            <?php elseif ($hasMedia): ?>
              <p class="content-record-preview content-record-preview-muted">Media post</p>
            <?php endif; ?>

            <?php if ($isPinned || $hasMedia || $itemStatus === 'trash'): ?>
            <div class="content-record-meta">
              <?php if ($isPinned): ?><span class="content-record-chip pinned">Pinned</span><?php endif; ?>
              <?php if ($hasMedia): ?><span class="content-record-chip media">Media</span><?php endif; ?>
              <?php if ($itemStatus === 'trash'): ?>
                <span class="content-record-chip neutral">Originally <?= htmlspecialchars(ucfirst((string)($item['original_status'] ?? 'draft')), ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>

          <div class="content-record-status">
            <span class="status-pill <?= htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(bms_content_status_label($itemStatus), ENT_QUOTES, 'UTF-8') ?></span>
          </div>

          <div class="content-record-date">
            <span class="content-record-date-label"><?= match ($itemStatus) { 'trash' => 'Deleted', 'scheduled' => 'Scheduled for', 'published' => 'Published', default => 'Date' } ?></span>
            <time><?= htmlspecialchars($displayDate !== '' ? $displayDate : 'Unknown', ENT_QUOTES, 'UTF-8') ?></time>
          </div>

          <div class="content-record-actions">
            <?php if ($itemStatus !== 'trash'): ?><a class="content-record-edit button-link secondary" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">Edit<span class="screen-reader-text"> <?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></span></a><?php endif; ?>
            <details class="content-actions-menu" data-content-actions>
              <summary>Actions<span class="screen-reader-text"> for <?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></span></summary>
              <div class="content-actions-menu-panel">
                <?php if ($itemStatus === 'trash'): ?>
                  <form method="post" action="<?= htmlspecialchars(bms_admin_url('restore.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="trash_id" value="<?= (int)$item['trash_id'] ?>">
                    <button type="submit" class="content-action-button state-link">Restore</button>
                  </form>
                  <form method="post" action="<?= htmlspecialchars(bms_admin_url('delete-permanent.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="trash_id" value="<?= (int)$item['trash_id'] ?>">
                    <button type="submit" class="content-action-button danger-link">Delete permanently</button>
                  </form>
                <?php else: ?>
                  <a href="<?= htmlspecialchars(bms_admin_url('quick-edit.php?type=' . urlencode($itemType) . '&file=' . urlencode($file)), ENT_QUOTES, 'UTF-8') ?>">Quick edit</a>
                  <a href="<?= htmlspecialchars(bms_admin_url('preview.php?type=' . urlencode($itemType) . '&file=' . urlencode($file)), ENT_QUOTES, 'UTF-8') ?>">Preview</a>
                  <a href="<?= htmlspecialchars(bms_admin_url('revisions.php?slug=' . urlencode((string)$item['slug'])), ENT_QUOTES, 'UTF-8') ?>">Revisions</a>
                  <?php if ($itemStatus === 'published'): ?>
                    <a href="<?= htmlspecialchars(bms_stream_url_for_post($item), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View post</a>
                    <?php if ($canPublishItem): ?>
                      <form method="post" action="<?= htmlspecialchars(bms_admin_url('pin.php'), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="<?= $isPinned ? 'unpin' : 'pin' ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars(bms_admin_url('content.php?status=' . rawurlencode($status)), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="content-action-button state-link"><?= $isPinned ? 'Unpin from Stream' : 'Pin to Stream' ?></button>
                      </form>
                      <form method="post" action="<?= htmlspecialchars(bms_admin_url('unpublish.php'), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="content-action-button state-link">Move to drafts</button>
                      </form>
                    <?php endif; ?>
                  <?php elseif ($canPublishItem): ?>
                    <form method="post" action="<?= htmlspecialchars(bms_admin_url('publish.php'), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="type" value="<?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="return" value="content">
                      <button type="submit" class="content-action-button state-link">Publish</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?= htmlspecialchars(bms_admin_url('delete.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?>">
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
    <?php if ($totalPages > 1): ?>
      <nav class="content-pagination" aria-label="Stream post pagination">
        <?php if ($currentPage > 1): ?><a class="button-link secondary" rel="prev" href="<?= htmlspecialchars($pageUrl($currentPage - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php endif; ?>
        <span>Page <?= $currentPage ?> of <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?><a class="button-link secondary" rel="next" href="<?= htmlspecialchars($pageUrl($currentPage + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a><?php endif; ?>
      </nav>
    <?php endif; ?>
    <p class="content-results-summary">Showing <?= $pageOffset + 1 ?> to <?= $pageOffset + count($items) ?> of <?= $totalItems ?> post<?= $totalItems === 1 ? '' : 's' ?><?= $q !== '' ? ' matching your search' : '' ?>. Selection applies to this page.</p>
  <?php endif; ?>
  <p class="content-results-summary">All excludes Trash. Pinned posts are also included in Published.</p>
</section>
<?php bms_admin_footer(); ?>
