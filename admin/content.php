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
$dateSortNext = $sort === 'date_desc' ? 'date_asc' : 'date_desc';
$dateSortSymbol = $sort === 'date_desc' ? '↓' : '↑';
$dateSortUrl = bms_admin_url('content.php' . bms_query_string([
    'status' => $status !== 'all' ? $status : '',
    'q' => $q,
    'sort' => $dateSortNext,
]));
$title = match ($status) {
    'draft' => 'Draft Stream Posts',
    'published' => 'Published Stream Posts',
    'pinned' => 'Pinned Stream Posts',
    'scheduled' => 'Scheduled Stream Posts',
    'trash' => 'Trash',
    default => 'Stream Posts',
};
$actions = [
    ['label' => 'Open Stream Composer', 'href' => bms_stream_composer_url(), 'style' => 'primary'],
    ['label' => 'Revisions', 'href' => bms_admin_url('revisions.php'), 'style' => 'secondary'],
];
$canEmptyTrash = true;
bms_admin_header($title, $actions);
?>
<nav class="content-filter" aria-label="Stream post status filters">
  <a class="<?= $status === 'all' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('content.php'), ENT_QUOTES, 'UTF-8') ?>">All <span><?= count($drafts) + count($scheduled) + count($published) ?></span></a>
  <a class="<?= $status === 'draft' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('content.php?status=draft'), ENT_QUOTES, 'UTF-8') ?>">Drafts <span><?= count($drafts) ?></span></a>
  <a class="<?= $status === 'scheduled' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('content.php?status=scheduled'), ENT_QUOTES, 'UTF-8') ?>">Scheduled <span><?= count($scheduled) ?></span></a>
  <a class="<?= $status === 'published' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('content.php?status=published'), ENT_QUOTES, 'UTF-8') ?>">Published <span><?= count($published) ?></span></a>
  <a class="<?= $status === 'pinned' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('content.php?status=pinned'), ENT_QUOTES, 'UTF-8') ?>">Pinned <span><?= $pinnedCount ?></span></a>
  <a class="<?= $status === 'trash' ? 'active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('content.php?status=trash'), ENT_QUOTES, 'UTF-8') ?>">Trash <span><?= count($trash) ?></span></a>
</nav>


<?php if ($status === 'trash' && $trash && $canEmptyTrash): ?>
  <form method="post" action="<?= htmlspecialchars(bms_admin_url('delete-permanent.php'), ENT_QUOTES, 'UTF-8') ?>" class="trash-empty-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="empty_trash" value="1">
    <button type="submit" class="danger">Empty Trash</button>
  </form>
<?php endif; ?>

<section class="panel content-list-panel content-record-panel">
  <div class="content-list-search-region">
    <form class="content-search-form" method="get">
      <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
      <label class="sr-only" for="content_q">Search stream posts</label>
      <input id="content_q" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search titles, text, or slugs">
      <button type="submit">Search</button>
      <?php if ($q !== ''): ?>
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('content.php' . bms_query_string(['status' => $status !== 'all' ? $status : ''])), ENT_QUOTES, 'UTF-8') ?>">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (!$items): ?>
    <div class="empty-state content-list-empty-state">
      <h2>No stream posts found.</h2>
      <p><?= $status === 'trash' ? 'Trash is empty.' : 'Create your first Stream Post from the stream composer. Save a draft there when you need the full editor.' ?></p>
      <?php if ($status !== 'trash'): ?><a class="primary-button" href="<?= htmlspecialchars(bms_stream_composer_url(), ENT_QUOTES, 'UTF-8') ?>">Open Stream Composer</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="content-list-controlbar">
      <form id="bulk-content-form" method="post" class="bulk-content-form content-list-bulk-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <label class="content-list-select-all">
          <input type="checkbox" data-select-all data-select-scope="#stream-content-list" aria-label="Select all stream posts shown">
          <span>Select all</span>
        </label>
        <label class="sr-only" for="content_bulk_action">Bulk action</label>
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
        <button type="submit">Apply</button>
      </form>
      <div class="content-list-summary">
        <span><?= count($items) ?> post<?= count($items) === 1 ? '' : 's' ?> shown</span>
        <a class="content-list-sort-link" href="<?= htmlspecialchars($dateSortUrl, ENT_QUOTES, 'UTF-8') ?>">Date <?= htmlspecialchars($dateSortSymbol, ENT_QUOTES, 'UTF-8') ?></a>
      </div>
    </div>

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
          <div class="content-record-select">
            <input type="checkbox" form="bulk-content-form" name="selected[]" value="<?= htmlspecialchars($itemType . '|' . ($itemType === 'trash' ? (int)$item['trash_id'] : $file), ENT_QUOTES, 'UTF-8') ?>" aria-label="Select <?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <div class="content-record-main">
            <div class="content-record-title-row">
              <?php if ($itemStatus === 'trash'): ?>
                <h2><?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></h2>
              <?php else: ?>
                <h2><a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></a></h2>
              <?php endif; ?>
              <span class="status-pill <?= htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8') ?> content-record-mobile-status"><?= htmlspecialchars(bms_content_status_label($itemStatus), ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <?php if ($itemPreview !== ''): ?>
              <p class="content-record-preview"><?= htmlspecialchars($itemPreview, ENT_QUOTES, 'UTF-8') ?></p>
            <?php elseif ($hasMedia): ?>
              <p class="content-record-preview content-record-preview-muted">Media post</p>
            <?php endif; ?>

            <div class="content-record-meta">
              <?php if ($isPinned): ?><span class="content-record-chip pinned">Pinned</span><?php endif; ?>
              <?php if ($hasMedia): ?><span class="content-record-chip media">Media</span><?php endif; ?>
              <?php if ($itemStatus === 'trash'): ?>
                <span class="content-record-chip neutral">Originally <?= htmlspecialchars(ucfirst((string)($item['original_status'] ?? 'draft')), ENT_QUOTES, 'UTF-8') ?></span>
              <?php else: ?>
                <span class="content-record-chip neutral">Database</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="content-record-status">
            <span class="status-pill <?= htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(bms_content_status_label($itemStatus), ENT_QUOTES, 'UTF-8') ?></span>
          </div>

          <div class="content-record-date">
            <span class="content-record-mobile-label"><?= $itemStatus === 'trash' ? 'Deleted' : 'Date' ?></span>
            <time><?= htmlspecialchars($displayDate !== '' ? $displayDate : 'Unknown', ENT_QUOTES, 'UTF-8') ?></time>
          </div>

          <div class="content-record-actions">
            <details class="content-actions-menu" data-content-actions>
              <summary>Actions</summary>
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
                  <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">Edit</a>
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
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>
