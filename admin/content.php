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
    ['label' => 'New Stream Post', 'href' => bms_admin_url('new.php'), 'style' => 'primary'],
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

<section class="panel content-list-panel">
  <form class="content-search-form" method="get">
    <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
    <label class="sr-only" for="content_q">Search stream posts</label>
    <input id="content_q" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search stream posts">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?>
      <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('content.php' . bms_query_string(['status' => $status !== 'all' ? $status : ''])), ENT_QUOTES, 'UTF-8') ?>">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (!$items): ?>
    <div class="empty-state">
      <h2>No stream posts found.</h2>
      <p><?= $status === 'trash' ? 'Trash is empty.' : 'Create your first stream post from the front-page composer or the admin editor.' ?></p>
      <?php if ($status !== 'trash'): ?><a class="primary-button" href="<?= htmlspecialchars(bms_admin_url('new.php'), ENT_QUOTES, 'UTF-8') ?>">New Stream Post</a><?php endif; ?>
    </div>
  <?php else: ?>
    <form id="bulk-content-form" method="post" class="bulk-content-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <div class="bulk-actions-row">
        <select name="bulk_action" aria-label="Bulk action">
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
        <span class="meta"><?= count($items) ?> post<?= count($items) === 1 ? '' : 's' ?> shown</span>
      </div>
    </form>

    <table class="admin-table content-table stream-posts-table">
      <thead>
        <tr>
          <th class="check-column"><label class="select-all-label"><input type="checkbox" data-select-all aria-label="Select all stream posts"> <span>Select</span></label></th>
          <th>Post</th>
          <th>Status</th>
          <th>Pinned</th>
          <th>Media</th>
          <th><?= $status === 'trash' ? 'Deleted' : 'Storage' ?></th>
          <th><a class="sort-link <?= $sort === 'date_desc' || $sort === 'date_asc' ? 'active' : '' ?>" href="<?= htmlspecialchars($dateSortUrl, ENT_QUOTES, 'UTF-8') ?>">Date <span><?= htmlspecialchars($dateSortSymbol, ENT_QUOTES, 'UTF-8') ?></span></a></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <?php
          $itemStatus = (string)($item['content_status'] ?? 'draft');
          $itemType = $itemStatus === 'published' ? 'published' : ($itemStatus === 'scheduled' ? 'scheduled' : ($itemStatus === 'trash' ? 'trash' : 'draft'));
          $file = (string)($item['filename'] ?? '');
          $storageStatus = $itemStatus === 'trash' ? ['label' => 'In Trash', 'class' => 'trash'] : ['label' => 'Database', 'class' => 'generated'];
          $canPublishItem = $itemStatus !== 'trash' && function_exists('bms_current_user_can') && function_exists('bms_content_subject_for_file') ? bms_current_user_can('publish_content', bms_content_subject_for_file($itemStatus === 'published' ? 'published' : ($itemStatus === 'scheduled' ? 'scheduled' : 'drafts'), $file, $item)) : false;
          $itemTitle = trim((string)($item['title'] ?? ''));
          if ($itemTitle === '' || str_starts_with(strtolower($itemTitle), 'stream ')) {
              $itemTitle = bms_stream_admin_title_from_body((string)($item['body'] ?? ''), (string)($item['stream_created_at'] ?? $item['date'] ?? ''));
          }
          $itemPreview = bms_stream_preview_text($item, 140);
          $hasMedia = trim((string)($item['featured_media'] ?? $item['front_matter']['featured_media'] ?? '')) !== '';
          $isPinned = function_exists('bms_is_pinned_stream_post') && bms_is_pinned_stream_post($item);
          $displayDate = bms_stream_display_date($item);
        ?>
        <tr class="content-row content-type-stream">
          <td class="check-column"><input type="checkbox" form="bulk-content-form" name="selected[]" value="<?= htmlspecialchars($itemType . '|' . ($itemType === 'trash' ? (int)$item['trash_id'] : $file), ENT_QUOTES, 'UTF-8') ?>" aria-label="Select <?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?>"></td>
          <td class="title-column">
            <?php if ($itemStatus === 'trash'): ?>
              <strong><?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></strong>
              <?php if ($itemPreview !== '' || $hasMedia): ?><p class="content-preview"><?= htmlspecialchars($itemPreview, ENT_QUOTES, 'UTF-8') ?><?php if ($hasMedia): ?> <span class="media-indicator">Media attached</span><?php endif; ?></p><?php endif; ?>
              <div class="row-actions">
                <form method="post" action="<?= htmlspecialchars(bms_admin_url('restore.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-form row-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="trash_id" value="<?= (int)$item['trash_id'] ?>">
                  <button type="submit" class="link-button state-link">Restore</button>
                </form>
                <span>|</span>
                <form method="post" action="<?= htmlspecialchars(bms_admin_url('delete-permanent.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-form row-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="trash_id" value="<?= (int)$item['trash_id'] ?>">
                  <button type="submit" class="link-button danger-link">Delete Permanently</button>
                </form>
              </div>
              <p class="meta">Original: <?= htmlspecialchars(ucfirst((string)($item['original_status'] ?? 'draft')), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($item['original_filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
              <strong><a href="<?= htmlspecialchars(bms_admin_url('edit.php?type=' . urlencode($itemType) . '&file=' . urlencode($file)), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></a></strong>
              <?php if ($itemPreview !== '' || $hasMedia): ?><p class="content-preview"><?= htmlspecialchars($itemPreview, ENT_QUOTES, 'UTF-8') ?><?php if ($hasMedia): ?> <span class="media-indicator">Media attached</span><?php endif; ?></p><?php endif; ?>
              <div class="row-actions">
                <a href="<?= htmlspecialchars(bms_admin_url('edit.php?type=' . urlencode($itemType) . '&file=' . urlencode($file)), ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                <span>|</span>
                <a href="<?= htmlspecialchars(bms_admin_url('quick-edit.php?type=' . urlencode($itemType) . '&file=' . urlencode($file)), ENT_QUOTES, 'UTF-8') ?>">Quick Edit</a>
                <span>|</span>
                <a href="<?= htmlspecialchars(bms_admin_url('preview.php?type=' . urlencode($itemType) . '&file=' . urlencode($file)), ENT_QUOTES, 'UTF-8') ?>">Preview</a>
                <span>|</span>
                <a href="<?= htmlspecialchars(bms_admin_url('revisions.php?slug=' . urlencode((string)$item['slug'])), ENT_QUOTES, 'UTF-8') ?>">Revisions</a>
                <?php if ($itemStatus === 'published'): ?>
                  <span>|</span>
                  <a href="<?= htmlspecialchars(bms_stream_url_for_post($item), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a>
                  <?php if ($canPublishItem): ?>
                    <span>|</span>
                    <form method="post" action="<?= htmlspecialchars(bms_admin_url('pin.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-form row-form">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="<?= $isPinned ? 'unpin' : 'pin' ?>">
                      <input type="hidden" name="return_to" value="<?= htmlspecialchars(bms_admin_url('content.php?status=' . rawurlencode($status)), ENT_QUOTES, 'UTF-8') ?>">
                      <button type="submit" class="link-button state-link"><?= $isPinned ? 'Unpin from Stream' : 'Pin to Stream' ?></button>
                    </form>
                    <span>|</span>
                    <form method="post" action="<?= htmlspecialchars(bms_admin_url('unpublish.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-form row-form">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                      <button type="submit" class="link-button state-link">Move to Drafts</button>
                    </form>
                  <?php endif; ?>
                <?php else: ?>
                  <span>|</span>
                  <?php if ($canPublishItem): ?>
                    <form method="post" action="<?= htmlspecialchars(bms_admin_url('publish.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-form row-form">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="type" value="<?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="return" value="content">
                      <button type="submit" class="link-button state-link">Publish</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
                <span>|</span>
                <form method="post" action="<?= htmlspecialchars(bms_admin_url('delete.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-form row-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="type" value="<?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="file" value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                  <button type="submit" class="link-button danger-link">Trash</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
          <td><span class="status-pill <?= htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(bms_content_status_label($itemStatus), ENT_QUOTES, 'UTF-8') ?></span></td>
          <td><?= $isPinned ? '<span class="status-pill pinned">Pinned</span>' : '<span class="meta">No</span>' ?></td>
          <td><?= $hasMedia ? '<span class="media-indicator">Attached</span>' : '<span class="meta">None</span>' ?></td>
          <td><?php if ($itemStatus === 'trash'): ?><?= htmlspecialchars((string)($item['deleted_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?php else: ?><span class="static-pill <?= htmlspecialchars((string)$storageStatus['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$storageStatus['label'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></td>
          <td><?= htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>
