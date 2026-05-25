<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_media');

$status = function_exists('mp_media_normalize_status') ? mp_media_normalize_status((string)($_GET['status'] ?? $_POST['status'] ?? 'active')) : 'active';
$search = trim((string)($_GET['s'] ?? $_POST['s'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $action = (string)($_POST['bulk_action'] ?? '');
    $ids = function_exists('mp_media_ids_from_request') ? mp_media_ids_from_request($_POST) : [];

    if ($action === '') {
        mp_flash('Choose a bulk media action.', 'error');
    } elseif (!$ids) {
        mp_flash('Select at least one media item first.', 'error');
    } else {
        $results = mp_media_bulk_action($ids, $action);
        $label = mp_media_bulk_action_label($action);
        if ((int)$results['processed'] > 0) {
            mp_flash((int)$results['processed'] . ' media item(s) ' . $label . '.', 'success');
        }
        if ((int)$results['failed'] > 0) {
            $detail = $results['messages'][0] ?? 'Some items could not be processed.';
            mp_flash((int)$results['failed'] . ' media item(s) could not be processed. ' . $detail, 'error');
        }
    }

    $redirect = 'media.php?status=' . rawurlencode($status);
    if ($search !== '') {
        $redirect .= '&s=' . rawurlencode($search);
    }
    mp_redirect(mp_admin_url($redirect));
}

$items = mp_media_list(200, $search, $status);
$activeCount = mp_media_count('active');
$trashCount = mp_media_count('trash');
$viewTitle = $status === 'trash' ? 'Media Trash' : 'Media Library';

mp_admin_header($viewTitle, [
    ['label' => 'Add New Media', 'href' => mp_admin_url('media-upload.php'), 'style' => 'primary'],
    ['label' => 'Optimize Images', 'href' => mp_admin_url('media-regenerate.php'), 'style' => 'secondary'],
    ['label' => 'Library', 'href' => mp_admin_url('media.php'), 'style' => 'secondary'],
    ['label' => 'Trash', 'href' => mp_admin_url('media.php?status=trash'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Media</p>
  <h2><?= htmlspecialchars($status === 'trash' ? 'Trash' : 'Library', ENT_QUOTES, 'UTF-8') ?></h2>
  <p class="meta">Upload supported media files, keep clean metadata, generate optimized image variants, and insert Markdown links or image syntax into Bonumark Stream content.</p>
</section>

<section class="panel media-toolbar-panel">
  <div class="status-tabs" role="navigation" aria-label="Media views">
    <a class="status-tab<?= $status === 'active' ? ' active' : '' ?>" href="<?= htmlspecialchars(mp_admin_url('media.php'), ENT_QUOTES, 'UTF-8') ?>">Library <span><?= (int)$activeCount ?></span></a>
    <a class="status-tab<?= $status === 'trash' ? ' active' : '' ?>" href="<?= htmlspecialchars(mp_admin_url('media.php?status=trash'), ENT_QUOTES, 'UTF-8') ?>">Trash <span><?= (int)$trashCount ?></span></a>
  </div>
  <form method="get" class="filter-form media-search-form">
    <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
    <label class="sr-only" for="media_search">Search media</label>
    <input id="media_search" type="search" name="s" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search filenames, alt text, or captions">
    <button type="submit">Search</button>
    <?php if ($search !== ''): ?>
      <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('media.php?status=' . rawurlencode($status)), ENT_QUOTES, 'UTF-8') ?>">Clear</a>
    <?php endif; ?>
  </form>
</section>

<section class="panel">
  <div class="section-header-row">
    <div>
      <h2><?= htmlspecialchars($status === 'trash' ? 'Trashed media items' : 'Media items', ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="meta"><?= count($items) ?> item(s) shown. Supported formats: <?= htmlspecialchars(mp_allowed_media_extensions_label(), ENT_QUOTES, 'UTF-8') ?>.</p>
    </div>
    <?php if ($status === 'active'): ?>
      <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('media-upload.php'), ENT_QUOTES, 'UTF-8') ?>">Upload Media</a>
    <?php endif; ?>
  </div>

  <?php if (!$items): ?>
    <div class="empty-state">
      <h3><?= htmlspecialchars($status === 'trash' ? 'Media trash is empty.' : 'No media yet.', ENT_QUOTES, 'UTF-8') ?></h3>
      <p class="meta"><?= htmlspecialchars($status === 'trash' ? 'Media moved to trash will appear here before it is permanently deleted.' : 'Upload your first media file, add useful metadata, then copy its Markdown or public URL into your content.', ENT_QUOTES, 'UTF-8') ?></p>
      <?php if ($status === 'active'): ?>
        <a class="primary-button" href="<?= htmlspecialchars(mp_admin_url('media-upload.php'), ENT_QUOTES, 'UTF-8') ?>">Add New Media</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <form method="post" class="bulk-media-form" data-confirm="Apply this media bulk action?">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="s" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
      <div class="bulk-actions-row media-bulk-actions">
        <label class="select-all-label"><input type="checkbox" data-media-select-all> Select all shown</label>
        <label class="sr-only" for="bulk_action">Bulk action</label>
        <select id="bulk_action" name="bulk_action">
          <option value="">Bulk actions</option>
          <?php if ($status === 'trash'): ?>
            <option value="restore">Restore</option>
            <option value="delete_permanently">Delete permanently</option>
          <?php else: ?>
            <option value="trash">Move to trash</option>
          <?php endif; ?>
        </select>
        <button type="submit">Apply</button>
        <?php if ($status === 'trash'): ?>
          <p class="field-help media-bulk-warning">Permanent deletion removes the media database record and deletes the file from disk.</p>
        <?php else: ?>
          <p class="field-help">Moving media to trash hides it from the library and composer while keeping the file on disk.</p>
        <?php endif; ?>
      </div>

      <div class="media-grid selectable-media-grid">
        <?php foreach ($items as $media): ?>
          <?php
            $url = mp_media_public_url_for_item($media);
            $markdown = mp_media_markdown($media);
            $alt = (string)($media['alt_text'] ?? '');
            $caption = (string)($media['caption'] ?? '');
            $kind = function_exists('mp_media_kind_label') ? mp_media_kind_label($media) : 'Media';
            $isImage = function_exists('mp_media_is_image_item') ? mp_media_is_image_item($media) : str_starts_with((string)($media['mime_type'] ?? ''), 'image/');
            $width = (int)($media['width'] ?? 0);
            $height = (int)($media['height'] ?? 0);
            $dimensions = $width > 0 && $height > 0 ? ($width . '×' . $height . ', ') : '';
            $editUrl = mp_admin_url('media-edit.php?id=' . urlencode((string)$media['id']));
          ?>
          <article class="media-card<?= $status === 'trash' ? ' media-card-trashed' : '' ?>">
            <label class="media-card-select">
              <input type="checkbox" name="media_ids[]" value="<?= (int)$media['id'] ?>">
              <span>Select</span>
            </label>
            <a class="media-thumb" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">
              <?php if ($isImage): ?>
                <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
              <?php else: ?>
                <span class="media-file-badge"><?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </a>
            <div class="media-card-body">
              <h3><?= htmlspecialchars((string)($media['original_filename'] ?? $media['filename']), ENT_QUOTES, 'UTF-8') ?></h3>
              <p class="meta"><?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($dimensions . mp_media_human_size((int)($media['file_size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></p>
              <?php if ($status === 'trash' && trim((string)($media['trashed_at'] ?? '')) !== ''): ?>
                <p class="meta">Trashed <?= htmlspecialchars((string)$media['trashed_at'], ENT_QUOTES, 'UTF-8') ?></p>
              <?php endif; ?>
              <?php if ($caption !== ''): ?><p class="media-caption"><?= htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
              <?php if ($status === 'active'): ?>
                <label for="media_md_<?= (int)$media['id'] ?>">Markdown</label>
                <input id="media_md_<?= (int)$media['id'] ?>" class="copy-field" type="text" readonly value="<?= htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
              <div class="row-actions">
                <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a>
                <?php if ($status === 'active'): ?>
                  <button type="button" class="link-button" data-copy-target="media_md_<?= (int)$media['id'] ?>">Copy Markdown</button>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </form>
  <?php endif; ?>
</section>
<script src="<?= htmlspecialchars(mp_asset_url('assets/editor.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php mp_admin_footer(); ?>
