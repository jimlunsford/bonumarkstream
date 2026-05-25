<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_media');

$mode = function_exists('mp_media_regeneration_mode') ? mp_media_regeneration_mode((string)($_GET['mode'] ?? $_POST['mode'] ?? 'missing')) : 'missing';
$afterId = max(0, (int)($_GET['after_id'] ?? $_POST['after_id'] ?? 0));
$limit = function_exists('mp_media_regeneration_batch_size') ? mp_media_regeneration_batch_size() : 5;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    try {
        $result = mp_media_regeneration_run_batch($mode, $afterId, $limit);
        $parts = [];
        $parts[] = (int)$result['processed'] . ' image(s) checked';
        $parts[] = (int)$result['generated'] . ' with optimized variants';
        if ((int)$result['skipped'] > 0) {
            $parts[] = (int)$result['skipped'] . ' skipped';
        }
        if ((int)$result['failed'] > 0) {
            $parts[] = (int)$result['failed'] . ' failed';
        }
        mp_flash('Image optimization batch complete: ' . implode(', ', $parts) . '.', ((int)$result['failed'] > 0) ? 'warning' : 'success');
    } catch (Throwable $e) {
        mp_flash('Image optimization failed. ' . $e->getMessage(), 'error');
    }
}

$summary = function_exists('mp_media_regeneration_summary') ? mp_media_regeneration_summary() : ['total_images' => 0, 'with_variants' => 0, 'missing_variants' => 0, 'all_candidates' => 0];
$environment = function_exists('mp_media_derivative_environment') ? mp_media_derivative_environment('image/jpeg') : [];
$remaining = function_exists('mp_media_regeneration_count') ? mp_media_regeneration_count($mode) : 0;
$nextAfter = is_array($result) ? (int)($result['last_id'] ?? $afterId) : $afterId;
$hasMore = is_array($result) ? (bool)($result['has_more'] ?? false) : ($remaining > 0);

mp_admin_header('Optimize Images', [
    ['label' => 'Media Library', 'href' => mp_admin_url('media.php'), 'style' => 'secondary'],
    ['label' => 'Upload Media', 'href' => mp_admin_url('media-upload.php'), 'style' => 'primary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Media tools</p>
  <h2>Optimize existing images.</h2>
  <p class="meta">Create or refresh verified image variants for existing uploads in small batches. Originals are preserved, and public pages only use generated files that exist and pass verification.</p>
</section>

<section class="panel media-regen-status-panel">
  <div class="section-header-row">
    <div>
      <h2>Library status</h2>
      <p class="meta">Batch size is limited to <?= (int)$limit ?> image(s) per request to stay safe on shared hosting.</p>
    </div>
  </div>
  <div class="media-diagnostic-grid media-regen-summary-grid">
    <div><span>Total local images</span><strong><?= (int)($summary['total_images'] ?? 0) ?></strong></div>
    <div><span>Images with optimized variants</span><strong><?= (int)($summary['with_variants'] ?? 0) ?></strong></div>
    <div><span>Missing optimized variants</span><strong><?= (int)($summary['missing_variants'] ?? 0) ?></strong></div>
    <div><span>Current mode remaining</span><strong><?= (int)$remaining ?></strong></div>
  </div>
  <details class="media-troubleshooting-details media-regen-troubleshooting">
    <summary>Server variant support</summary>
    <p class="field-help">These checks explain whether this server can create optimized image variants.</p>
    <div class="media-diagnostic-grid media-regen-summary-grid">
      <div><span>GD resize support</span><strong><?= !empty($environment['gd_available']) ? 'Available' : 'Unavailable' ?></strong></div>
      <div><span>Imagick support</span><strong><?= !empty($environment['imagick_available']) ? 'Available' : 'Unavailable' ?></strong></div>
      <div><span>Generated folder</span><strong><?= !empty($environment['generated_root_writable']) ? 'Writable' : 'Not writable' ?></strong></div>
      <div><span>Memory limit</span><strong><?= htmlspecialchars((string)($environment['memory_limit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
    </div>
  </details>
</section>

<section class="panel media-regen-control-panel">
  <div class="section-header-row">
    <div>
      <h2>Run a batch</h2>
      <p class="meta">Start with missing variants. Use refresh all only when you intentionally want to rebuild variant records for every local image.</p>
    </div>
  </div>
  <div class="media-regen-mode-grid">
    <form method="post" class="action-card media-regen-card">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="mode" value="missing">
      <input type="hidden" name="after_id" value="<?= $mode === 'missing' ? (int)$nextAfter : 0 ?>">
      <h3>Generate missing variants</h3>
      <p>Process existing images that do not have recorded optimized variants yet.</p>
      <button type="submit"><?= $mode === 'missing' && $afterId > 0 ? 'Continue Missing Batch' : 'Start Missing Batch' ?></button>
    </form>
    <form method="post" class="action-card media-regen-card" data-confirm="Refresh variants for all local images? This still runs in small batches but may replace existing generated variants.">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="mode" value="all">
      <input type="hidden" name="after_id" value="<?= $mode === 'all' ? (int)$nextAfter : 0 ?>">
      <h3>Refresh all variants</h3>
      <p>Re-check every local image and rebuild optimized variants in batches.</p>
      <button type="submit" class="secondary-button"><?= $mode === 'all' && $afterId > 0 ? 'Continue Refresh Batch' : 'Start Refresh Batch' ?></button>
    </form>
  </div>
  <?php if (is_array($result) && !empty($result['has_more'])): ?>
    <form method="post" class="form-actions-row media-regen-continue-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="mode" value="<?= htmlspecialchars((string)($result['mode'] ?? $mode), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="after_id" value="<?= (int)($result['last_id'] ?? 0) ?>">
      <button type="submit" class="primary-button">Process next batch</button>
      <p class="field-help">The next batch starts after media ID <?= (int)($result['last_id'] ?? 0) ?>.</p>
    </form>
  <?php endif; ?>
</section>

<?php if (is_array($result)): ?>
<section class="panel media-regen-results-panel">
  <div class="section-header-row">
    <div>
      <h2>Last batch results</h2>
      <p class="meta"><?= (int)$result['processed'] ?> checked, <?= (int)$result['generated'] ?> with optimized variants, <?= (int)$result['skipped'] ?> skipped, <?= (int)$result['failed'] ?> failed.</p>
    </div>
  </div>
  <?php if (empty($result['items'])): ?>
    <div class="empty-state"><h3>No images were processed.</h3><p class="meta">There may be no remaining candidates for this mode.</p></div>
  <?php else: ?>
    <div class="media-variant-list media-regen-result-list">
      <?php foreach ($result['items'] as $item): ?>
        <?php $status = (string)($item['status'] ?? 'skipped'); ?>
        <div class="media-variant-row">
          <div>
            <strong>#<?= (int)($item['id'] ?? 0) ?> <?= htmlspecialchars((string)($item['name'] ?? 'Media item'), ENT_QUOTES, 'UTF-8') ?></strong>
            <p class="field-help"><?= htmlspecialchars((string)($item['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
          <span class="status-pill <?= $status === 'generated' ? 'published' : ($status === 'failed' ? 'trash' : 'draft') ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
  <h2>What this tool does not do</h2>
  <ul class="plain-list">
    <li>It does not change original media files.</li>
    <li>It does not convert images to WebP or AVIF.</li>
    <li>It does not create public image URLs unless the generated file exists on disk.</li>
    <li>It does not process the full library in one request.</li>
  </ul>
</section>
<?php mp_admin_footer(); ?>
