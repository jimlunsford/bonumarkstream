<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_media');

$status = function_exists('bms_media_normalize_status') ? bms_media_normalize_status((string)($_GET['status'] ?? $_POST['status'] ?? 'active')) : 'active';
$search = trim((string)($_GET['s'] ?? $_POST['s'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['bulk_action'] ?? '');
    $ids = function_exists('bms_media_ids_from_request') ? bms_media_ids_from_request($_POST) : [];

    if ($action === '') {
        bms_flash('Choose a bulk media action.', 'error');
    } elseif (!$ids) {
        bms_flash('Select at least one media item first.', 'error');
    } else {
        $results = bms_media_bulk_action($ids, $action);
        $label = bms_media_bulk_action_label($action);
        if ((int)$results['processed'] > 0) {
            bms_flash((int)$results['processed'] . ' media item(s) ' . $label . '.', 'success');
        }
        if ((int)$results['failed'] > 0) {
            $detail = $results['messages'][0] ?? 'Some items could not be processed.';
            bms_flash((int)$results['failed'] . ' media item(s) could not be processed. ' . $detail, 'error');
        }
    }

    $redirect = 'media.php?status=' . rawurlencode($status);
    if ($search !== '') {
        $redirect .= '&s=' . rawurlencode($search);
    }
    bms_redirect(bms_admin_url($redirect));
}

$items = bms_media_list(200, $search, $status);
$activeCount = bms_media_count('active');
$trashCount = bms_media_count('trash');
bms_admin_header('Media');
?>
<div class="media-toolbar-panel">
  <nav class="status-tabs" aria-label="Media views">
    <a class="status-tab<?= $status === 'active' ? ' active' : '' ?>"<?= $status === 'active' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('media.php'), ENT_QUOTES, 'UTF-8') ?>">Active <span><?= (int)$activeCount ?></span></a>
    <a class="status-tab<?= $status === 'trash' ? ' active' : '' ?>"<?= $status === 'trash' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_admin_url('media.php?status=trash'), ENT_QUOTES, 'UTF-8') ?>">Trash <span><?= (int)$trashCount ?></span></a>
  </nav>
  <form method="get" class="filter-form media-search-form">
    <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
    <div class="media-search-field">
      <label for="media_search">Search media</label>
      <input id="media_search" type="search" name="s" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Filenames, alt text, or captions">
    </div>
    <div class="media-search-actions">
      <button type="submit">Search</button>
      <?php if ($search !== ''): ?>
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('media.php?status=' . rawurlencode($status)), ENT_QUOTES, 'UTF-8') ?>">Clear search</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<section class="media-library-panel" aria-label="<?= $status === 'trash' ? 'Trashed media' : 'Media items' ?>">
  <?php if (!$items): ?>
    <div class="empty-state media-library-empty-state">
      <h2><?= htmlspecialchars($search !== '' ? 'No matching media.' : ($status === 'trash' ? 'Trash is empty.' : 'No media yet.'), ENT_QUOTES, 'UTF-8') ?></h2>
      <?php if ($search !== ''): ?>
        <p class="meta">No results for “<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>”. Try another search or clear it above.</p>
      <?php elseif ($status === 'trash'): ?>
        <p class="meta">Media moved to Trash will appear here.</p>
      <?php else: ?>
        <p class="meta">Use Upload to add your first file.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <form method="post" class="bulk-media-form media-library-form" data-confirm="Apply this media bulk action?">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="s" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
      <div class="bulk-actions-row media-bulk-actions media-library-bulkbar">
        <label class="select-all-label"><input type="checkbox" data-media-select-all> Select all shown</label>
        <div class="media-bulk-field">
          <label for="bulk_action">Bulk action</label>
          <select id="bulk_action" name="bulk_action" aria-describedby="media_bulk_help">
            <option value="">Choose action</option>
            <?php if ($status === 'trash'): ?>
              <option value="restore">Restore</option>
              <optgroup label="Permanent action">
                <option value="delete_permanently">Delete permanently</option>
              </optgroup>
            <?php else: ?>
              <option value="trash">Move to trash</option>
            <?php endif; ?>
          </select>
        </div>
        <button type="submit">Apply</button>
        <p class="media-results-summary"><?= count($items) ?> <?= count($items) === 1 ? 'item' : 'items' ?> shown<?= count($items) === 200 ? ' (200-item limit)' : '' ?></p>
      </div>
      <p id="media_bulk_help" class="field-help<?= $status === 'trash' ? ' media-bulk-warning' : '' ?>"><?= $status === 'trash' ? 'Permanent deletion removes the record, original file, and generated variants. This cannot be undone.' : 'Trash hides files from the library and composer while keeping them recoverable.' ?></p>

      <div class="media-library-grid" id="media-library-grid">
        <?php foreach ($items as $media): ?>
          <?php
            $url = bms_media_public_url_for_item($media);
            $markdown = bms_media_markdown($media);
            $alt = (string)($media['alt_text'] ?? '');
            $caption = (string)($media['caption'] ?? '');
            $name = (string)($media['original_filename'] ?? $media['filename'] ?? 'Media item');
            $kind = function_exists('bms_media_kind_label') ? bms_media_kind_label($media) : 'Media';
            $isImage = function_exists('bms_media_is_image_item') ? bms_media_is_image_item($media) : str_starts_with((string)($media['mime_type'] ?? ''), 'image/');
            $width = (int)($media['width'] ?? 0);
            $height = (int)($media['height'] ?? 0);
            $dimensionLabel = $width > 0 && $height > 0 ? ($width . '×' . $height) : '';
            $sizeLabel = bms_media_human_size((int)($media['file_size'] ?? 0));
            $metaParts = array_values(array_filter([$kind, $dimensionLabel, $sizeLabel], static fn ($value): bool => trim((string)$value) !== ''));
            $metaLabel = implode(' · ', $metaParts);
            $editUrl = bms_admin_url('media-edit.php?id=' . urlencode((string)$media['id']));
            $privacy = function_exists('bms_media_privacy_status') ? bms_media_privacy_status($media) : ['label' => 'Not checked', 'class' => 'draft', 'note' => 'Privacy status unavailable.'];
            $privacyLabel = (string)($privacy['label'] ?? 'Not checked');
            $privacyClass = preg_replace('/[^a-z0-9_-]+/i', '', (string)($privacy['class'] ?? 'draft')) ?: 'draft';
            $privacyNote = (string)($privacy['note'] ?? 'Privacy status unavailable.');
            $trashedAt = $status === 'trash' ? trim((string)($media['trashed_at'] ?? '')) : '';
          ?>
          <article
            class="media-library-card<?= $status === 'trash' ? ' media-library-card-trashed' : '' ?>"
            data-media-item
            data-media-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
            data-media-kind="<?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?>"
            data-media-meta="<?= htmlspecialchars($metaLabel, ENT_QUOTES, 'UTF-8') ?>"
            data-media-status-label="<?= htmlspecialchars($privacyLabel, ENT_QUOTES, 'UTF-8') ?>"
            data-media-status-class="<?= htmlspecialchars($privacyClass, ENT_QUOTES, 'UTF-8') ?>"
            data-media-note="<?= htmlspecialchars($privacyNote, ENT_QUOTES, 'UTF-8') ?>"
            data-media-caption="<?= htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') ?>"
            data-media-markdown="<?= htmlspecialchars($status === 'active' ? $markdown : '', ENT_QUOTES, 'UTF-8') ?>"
            data-media-edit-url="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"
            data-media-view-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
            data-media-image-url="<?= htmlspecialchars($isImage ? $url : '', ENT_QUOTES, 'UTF-8') ?>"
            data-media-alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>"
            data-media-trashed-at="<?= htmlspecialchars($trashedAt, ENT_QUOTES, 'UTF-8') ?>"
          >
            <label class="media-library-select">
              <input type="checkbox" name="media_ids[]" value="<?= (int)$media['id'] ?>">
              <span class="sr-only">Select <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
            </label>
            <a class="media-library-thumb" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Edit <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
              <?php if ($isImage): ?>
                <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
              <?php else: ?>
                <span class="media-library-file-badge"><?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </a>
            <div class="media-library-card-copy">
              <h2><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></h2>
              <p class="media-library-card-meta"><?= htmlspecialchars($metaLabel, ENT_QUOTES, 'UTF-8') ?></p>
              <div class="media-library-card-status">
                <span class="status-pill <?= htmlspecialchars($privacyClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($privacyLabel, ENT_QUOTES, 'UTF-8') ?></span>
              </div>
            </div>
            <a class="media-library-details-button" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" data-media-details-open>
              <span>Details<span class="sr-only"> for <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span></span><span aria-hidden="true">›</span>
            </a>
          </article>
        <?php endforeach; ?>
      </div>
    </form>

    <dialog id="media_details_dialog" class="media-details-dialog" data-media-details-dialog aria-labelledby="media_details_title">
      <div class="media-details-shell">
        <header class="media-details-header">
          <h2 id="media_details_title">Media details</h2>
          <button type="button" class="media-details-close" data-media-details-close aria-label="Close media details">×</button>
        </header>

        <div class="media-details-scroll" data-media-details-scroll>
          <div class="media-details-layout">
            <div class="media-details-preview">
              <img data-media-detail-image alt="" hidden>
              <span class="media-details-file-badge" data-media-detail-file-badge hidden>Media</span>
            </div>

            <div class="media-details-content">
            <dl class="media-details-list">
              <div><dt>Original filename</dt><dd data-media-detail-name>Media item</dd></div>
              <div><dt>Type</dt><dd data-media-detail-kind>Media</dd></div>
              <div><dt>File</dt><dd data-media-detail-meta></dd></div>
              <div><dt>Alt text</dt><dd data-media-detail-alt>Not set</dd></div>
              <div><dt>Privacy</dt><dd><span class="status-pill draft" data-media-detail-status>Not checked</span></dd></div>
              <div data-media-detail-trashed-row hidden><dt>Trashed</dt><dd data-media-detail-trashed></dd></div>
            </dl>

            <p class="media-details-note" data-media-detail-note hidden></p>
            <div class="media-details-caption" data-media-detail-caption-row hidden>
              <h3>Caption</h3>
              <p data-media-detail-caption></p>
            </div>

              <div class="media-details-markdown" data-media-detail-markdown-row hidden>
                <label for="media_detail_markdown">Markdown</label>
                <input id="media_detail_markdown" class="copy-field" type="text" readonly data-media-detail-markdown>
              </div>
            </div>
          </div>
        </div>

        <footer class="media-details-actions">
          <button type="button" class="button-link secondary" data-copy-target="media_detail_markdown" data-media-detail-copy hidden>Copy Markdown</button>
          <a class="button-link primary" href="#" data-media-detail-edit>Edit media</a>
          <a class="button-link secondary" href="#" target="_blank" rel="noopener" data-media-detail-view>Open file</a>
        </footer>
      </div>
    </dialog>
  <?php endif; ?>
</section>
<script src="<?= htmlspecialchars(bms_asset_url('assets/editor.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php bms_admin_footer(); ?>
