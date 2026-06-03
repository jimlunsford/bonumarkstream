<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/importers.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('view_system');

$previewResult = null;
$previewToken = '';

/** @param list<string> $details */
function bms_import_flash_detail_summary(array $details): string
{
    $filtered = [];
    foreach ($details as $detail) {
        $detail = trim((string)$detail);
        if ($detail !== '' && (str_contains($detail, 'Could not import media') || str_contains($detail, 'media'))) {
            $filtered[] = $detail;
        }
        if (count($filtered) >= 3) {
            break;
        }
    }
    if (!$filtered) {
        return 'No detailed media error was returned.';
    }
    return implode(' ', $filtered);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['import_action'] ?? '');

    if ($action === 'clear') {
        bms_import_clear_preview();
        bms_flash('Import preview cleared.', 'success');
        bms_redirect(bms_admin_url('import.php'));
    }

    if ($action === 'preview') {
        $file = $_FILES['import_file'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            bms_flash('Choose a Markdown, JSON, WordPress XML, Bonumark export ZIP, Twitter/X archive ZIP, or Bluesky archive file to import.', 'error');
            bms_redirect(bms_admin_url('import.php'));
        }

        if ((int)($file['size'] ?? 0) > bms_import_max_upload_bytes()) {
            bms_flash('Import file is too large. Keep imports under the listed import size limit.', 'error');
            bms_redirect(bms_admin_url('import.php'));
        }

        $importer = bms_import_detect_importer($file);
        if (!$importer) {
            bms_flash('Unsupported import file. Use .md, .markdown, .txt, .json, a WordPress .xml export, a Bonumark export .zip, a Twitter/X archive .zip, or a Bluesky archive file.', 'error');
            bms_redirect(bms_admin_url('import.php'));
        }

        $result = $importer->importPreview($file);
        if ($result->errors) {
            bms_import_clear_preview();
            bms_flash(implode(' ', $result->errors), 'error');
            bms_redirect(bms_admin_url('import.php'));
        }
        if (!$result->hasItems()) {
            bms_import_clear_preview();
            bms_flash('The file was readable, but no importable stream posts were found.', 'error');
            bms_redirect(bms_admin_url('import.php'));
        }

        try {
            $previewToken = bms_import_store_preview($result, (string)($file['name'] ?? 'import'));
        } catch (Throwable $e) {
            bms_import_clear_preview();
            bms_flash('Import preview could not be staged. ' . $e->getMessage(), 'error');
            bms_redirect(bms_admin_url('import.php'));
        }
        bms_flash('Import preview created. Review the sample before confirming the import.', 'success');
    }

    if ($action === 'import') {
        $preview = bms_import_get_preview();
        $token = (string)($_POST['preview_token'] ?? '');
        if (!$preview || !hash_equals((string)($preview['token'] ?? ''), $token)) {
            bms_flash('Import preview expired. Upload the file again and create a fresh preview.', 'error');
            bms_redirect(bms_admin_url('import.php'));
        }

        $items = $preview['items'] ?? [];
        if (!is_array($items) || !$items) {
            bms_flash('Import preview has no items to import.', 'error');
            bms_redirect(bms_admin_url('import.php'));
        }

        $targetStatus = (string)($_POST['target_status'] ?? 'draft');
        $preserveDates = !empty($_POST['preserve_dates']);
        $duplicatePolicy = (string)($_POST['duplicate_policy'] ?? 'skip');
        $progress = is_array($preview['progress'] ?? null) ? $preview['progress'] : [];
        $isBlueskyImport = stripos((string)($preview['importer'] ?? ''), 'Bluesky') !== false;
        $defaultStart = max(1, (int)($progress['next_start'] ?? 1));
        $rangeStart = $isBlueskyImport ? 1 : max(1, (int)($_POST['range_start'] ?? $defaultStart));
        $batchSize = $isBlueskyImport ? 0 : max(0, (int)($_POST['batch_size'] ?? ($_POST['range_limit'] ?? 0)));
        $selectedItems = bms_import_select_items($items, $rangeStart, $batchSize);
        if (!$selectedItems) {
            bms_flash('The selected import batch has no items. Adjust the start item or batch size and try again.', 'error');
            bms_redirect(bms_admin_url('import.php'));
        }

        try {
            $mediaPolicy = $isBlueskyImport ? 'remote' : (string)($_POST['media_policy'] ?? 'remote');
            $summary = bms_import_commit_items($selectedItems, $targetStatus, $preserveDates, $duplicatePolicy, $mediaPolicy);
            $totalItems = count($items);
            $batchCount = count($selectedItems);
            $batchEnd = min($totalItems, $rangeStart + $batchCount - 1);
            $nextStart = $batchEnd + 1;
            $processedTotal = max((int)($progress['processed_total'] ?? 0), $batchEnd);
            $importedTotal = (int)($progress['imported_total'] ?? 0) + (int)$summary['imported'];
            $skippedTotal = (int)($progress['skipped_total'] ?? 0) + (int)$summary['skipped'];
            $message = 'Import batch complete. Processed item ' . $rangeStart . ' through ' . $batchEnd . ' of ' . $totalItems . '. Imported ' . $summary['imported'] . ' item(s).';
            if ($summary['skipped'] > 0) {
                $message .= ' Skipped ' . $summary['skipped'] . ' duplicate item(s).';
            }
            if (($summary['media_imported'] ?? 0) > 0) {
                $message .= ' Imported ' . $summary['media_imported'] . ' image file(s).';
            }
            if (($summary['media_removed'] ?? 0) > 0) {
                $message .= ' Removed ' . $summary['media_removed'] . ' image reference(s).';
            }
            if (($summary['media_failed'] ?? 0) > 0) {
                $message .= ' Could not import ' . $summary['media_failed'] . ' image file(s); details: ' . bms_import_flash_detail_summary($summary['details'] ?? []);
            }

            if ($nextStart <= $totalItems) {
                bms_import_update_preview_progress($preview, [
                    'next_start' => $nextStart,
                    'processed_total' => $processedTotal,
                    'imported_total' => $importedTotal,
                    'skipped_total' => $skippedTotal,
                    'last_batch_size' => $batchSize,
                    'last_batch_end' => $batchEnd,
                    'last_target_status' => $targetStatus,
                    'last_duplicate_policy' => $duplicatePolicy,
                    'last_media_policy' => $mediaPolicy,
                    'last_preserve_dates' => $preserveDates ? 1 : 0,
                ]);
                $message .= ' Continue with item ' . $nextStart . ' when ready. Total imported so far: ' . $importedTotal . '.';
                bms_flash($message, 'success');
                bms_redirect(bms_admin_url('import.php'));
            }

            bms_import_clear_preview();
            $message = 'Import complete. Processed all ' . $totalItems . ' prepared item(s). Imported ' . $importedTotal . ' item(s).';
            if ($skippedTotal > 0) {
                $message .= ' Skipped ' . $skippedTotal . ' duplicate item(s).';
            }
            if (($summary['media_imported'] ?? 0) > 0) {
                $message .= ' Imported ' . $summary['media_imported'] . ' image file(s) in the final batch.';
            }
            if (($summary['media_failed'] ?? 0) > 0) {
                $message .= ' Some media could not be imported; details: ' . bms_import_flash_detail_summary($summary['details'] ?? []);
            }
            bms_flash($message, 'success');
            $redirectStatus = $targetStatus === 'original' ? 'all' : ($targetStatus === 'published' ? 'published' : 'draft');
            bms_redirect(bms_admin_url('content.php' . ($redirectStatus === 'all' ? '' : '?status=' . $redirectStatus)));
        } catch (Throwable $e) {
            bms_flash('Import failed. ' . $e->getMessage(), 'error');
            bms_redirect(bms_admin_url('import.php'));
        }
    }
}
$preview = bms_import_get_preview();
$maxUploadLabel = number_format(bms_import_max_upload_bytes() / 1024 / 1024, 0) . ' MB';

bms_admin_header('Import', [
    ['label' => 'Export', 'href' => bms_admin_url('export.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Import content</p>
  <h2>Bring outside posts into Bonumark Stream.</h2>
  <p class="meta">Upload Markdown, generic JSON, WordPress XML, a Bonumark export ZIP, a Twitter/X archive ZIP, or a Bluesky CAR export, review a preview sample, then import the prepared items. Nothing is written to content files or the database until you confirm the import.</p>
</section>

<section class="panel import-panel">
  <h2>Upload import file</h2>
  <p class="meta">Supported: single Markdown files, generic JSON arrays or objects with post records, WordPress WXR/XML exports, Bonumark Stream export ZIP files, Twitter/X archive ZIP files, and Bluesky CAR exports. Maximum file size: <?= htmlspecialchars($maxUploadLabel, ENT_QUOTES, 'UTF-8') ?>.</p>
  <form method="post" enctype="multipart/form-data" class="settings-form import-upload-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="import_action" value="preview">
    <label class="settings-field">
      <span>Import file</span>
      <input type="file" name="import_file" accept=".md,.markdown,.txt,.json,.xml,.zip,.car,application/json,application/xml,text/xml,application/zip,application/x-zip-compressed,application/vnd.ipld.car,text/markdown,text/plain" required>
      <small>Use exports you control. The importer reads the file, normalizes items, and stores a private temporary preview outside the browser session.</small>
    </label>
    <button type="submit">Create Preview</button>
  </form>
</section>

<?php if ($preview): ?>
  <?php
  $items = is_array($preview['items'] ?? null) ? $preview['items'] : [];
  $warnings = is_array($preview['warnings'] ?? null) ? $preview['warnings'] : [];
  $token = (string)($preview['token'] ?? '');
  $previewRemoteImageCount = 0;
  $previewStagedImageCount = 0;
  foreach ($items as $previewItem) {
      if (is_array($previewItem)) {
          $previewBody = (string)($previewItem['body'] ?? '');
          $previewRemoteImageCount += count(bms_import_extract_remote_image_urls($previewBody));
          $previewStagedImageCount += count(bms_import_extract_staged_media_urls($previewBody));
          $previewFeaturedMedia = trim((string)($previewItem['featured_media'] ?? ''));
          if ($previewFeaturedMedia !== '') {
              if (bms_import_is_staged_media_url($previewFeaturedMedia)) {
                  $previewStagedImageCount++;
              } elseif (bms_import_is_remote_http_url($previewFeaturedMedia)) {
                  $previewRemoteImageCount++;
              }
          }
      }
  }
  $previewImageCount = $previewRemoteImageCount + $previewStagedImageCount;
  $defaultMediaPolicy = $previewImageCount > 0 ? 'import' : 'remote';
  $progress = is_array($preview['progress'] ?? null) ? $preview['progress'] : [];
  $isBlueskyPreview = stripos((string)($preview['importer'] ?? ''), 'Bluesky') !== false;
  $nextStart = max(1, min(max(1, count($items)), (int)($progress['next_start'] ?? 1)));
  $processedTotal = max(0, min(count($items), (int)($progress['processed_total'] ?? 0)));
  $importedTotal = max(0, (int)($progress['imported_total'] ?? 0));
  $skippedTotal = max(0, (int)($progress['skipped_total'] ?? 0));
  $selectedTargetStatus = in_array((string)($progress['last_target_status'] ?? 'draft'), ['draft', 'published', 'original'], true) ? (string)($progress['last_target_status'] ?? 'draft') : 'draft';
  $selectedDuplicatePolicy = in_array((string)($progress['last_duplicate_policy'] ?? 'skip'), ['skip', 'rename'], true) ? (string)($progress['last_duplicate_policy'] ?? 'skip') : 'skip';
  $selectedMediaPolicy = in_array((string)($progress['last_media_policy'] ?? $defaultMediaPolicy), ['remote', 'import', 'skip'], true) ? (string)($progress['last_media_policy'] ?? $defaultMediaPolicy) : $defaultMediaPolicy;
  $preserveDatesChecked = !isset($progress['last_preserve_dates']) || (int)$progress['last_preserve_dates'] === 1;
  $defaultBatchSize = (int)($progress['last_batch_size'] ?? 0);
  $buttonLabel = $nextStart > 1 && !$isBlueskyPreview ? 'Import Next Batch' : 'Confirm Import';
  $previewDisplayLimit = 50;
  $displayItems = array_slice($items, 0, $previewDisplayLimit);
  $hiddenPreviewCount = max(0, count($items) - count($displayItems));
  ?>
  <section class="panel import-preview-panel">
    <div class="import-preview-heading">
      <div>
        <p class="eyebrow">Preview</p>
        <h2><?= count($items) ?> item(s) prepared</h2>
        <p class="meta">Source: <?= htmlspecialchars((string)($preview['filename'] ?? 'import'), ENT_QUOTES, 'UTF-8') ?> via <?= htmlspecialchars((string)($preview['importer'] ?? 'Importer'), ENT_QUOTES, 'UTF-8') ?>.</p>
        <?php if ($processedTotal > 0): ?>
          <p class="meta">Progress: <?= (int)$processedTotal ?> of <?= count($items) ?> prepared item(s) processed. Imported so far: <?= (int)$importedTotal ?>. Skipped so far: <?= (int)$skippedTotal ?>.</p>
        <?php endif; ?>
        <?php if ($hiddenPreviewCount > 0): ?>
          <?php if ($isBlueskyPreview): ?>
            <p class="meta">Showing the first <?= count($displayItems) ?> item(s) as a sample. Confirm Import will import all <?= count($items) ?> prepared Bluesky post(s) without splitting the CAR file.</p>
          <?php else: ?>
            <p class="meta">Showing the first <?= count($displayItems) ?> item(s) as a sample. Optional batch controls below can import the prepared set without splitting the archive.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="import_action" value="clear">
        <button type="submit" class="button-link secondary">Clear Preview</button>
      </form>
    </div>

    <?php if ($warnings): ?>
      <div class="notice warning"><span class="notice-icon" aria-hidden="true">!</span><div class="notice-copy"><strong>Importer warnings</strong><p><?= htmlspecialchars(implode(' ', $warnings), ENT_QUOTES, 'UTF-8') ?></p></div></div>
    <?php endif; ?>

    <div class="import-options-card">
      <form method="post" class="settings-form import-confirm-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="import_action" value="import">
        <input type="hidden" name="preview_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <div class="settings-grid compact-settings-grid">
          <label class="settings-field">
            <span>Import status</span>
            <select name="target_status">
              <option value="draft"<?= $selectedTargetStatus === 'draft' ? ' selected' : '' ?>>Import as drafts</option>
              <option value="published"<?= $selectedTargetStatus === 'published' ? ' selected' : '' ?>>Publish immediately</option>
              <option value="original"<?= $selectedTargetStatus === 'original' ? ' selected' : '' ?>>Use detected status</option>
            </select>
          </label>
          <label class="settings-field">
            <span>Duplicate policy</span>
            <select name="duplicate_policy">
              <option value="skip"<?= $selectedDuplicatePolicy === 'skip' ? ' selected' : '' ?>>Skip duplicate slugs</option>
              <option value="rename"<?= $selectedDuplicatePolicy === 'rename' ? ' selected' : '' ?>>Rename duplicate slugs</option>
            </select>
          </label>
          <?php if ($isBlueskyPreview): ?>
            <input type="hidden" name="range_start" value="1">
            <input type="hidden" name="batch_size" value="0">
            <input type="hidden" name="media_policy" value="remote">
            <div class="settings-field import-note-field">
              <span>Bluesky CAR import</span>
              <small>Bluesky exports are CAR files. Bonumark imports Bluesky text, timestamps, hashtags, and links from the CAR file. Media import is not available from Bluesky CAR exports at this time.</small>
            </div>
          <?php else: ?>
            <label class="settings-field">
              <span>Start item</span>
              <input type="number" name="range_start" value="<?= (int)$nextStart ?>" min="1" max="<?= max(1, count($items)) ?>">
              <small>This automatically advances after each batch. Leave it alone unless you intentionally want to retry or skip to a different prepared item.</small>
            </label>
            <label class="settings-field">
              <span>Batch size</span>
              <input type="number" name="batch_size" value="<?= (int)$defaultBatchSize ?>" min="0" max="<?= max(0, count($items)) ?>">
              <small>Use 0 to import all remaining prepared items.</small>
            </label>
            <label class="settings-field">
              <span>Media handling<?= $previewImageCount > 0 ? ' (' . (int)$previewImageCount . ' detected)' : '' ?></span>
              <select name="media_policy">
                <option value="import"<?= $selectedMediaPolicy === 'import' ? ' selected' : '' ?>>Import media into Media</option>
                <option value="remote"<?= $selectedMediaPolicy === 'remote' ? ' selected' : '' ?>>Leave remote media URLs</option>
                <option value="skip"<?= $selectedMediaPolicy === 'skip' ? ' selected' : '' ?>>Remove remote media references</option>
              </select>
              <small>When media is detected, importing it into Media is selected by default. WordPress remote images are downloaded safely. Twitter/X archive media is staged locally.</small>
            </label>
          <?php endif; ?>
          <label class="settings-field checkbox-field">
            <input type="checkbox" name="preserve_dates" value="1"<?= $preserveDatesChecked ? ' checked' : '' ?>>
            <span>Preserve imported dates when available</span>
          </label>
        </div>
        <button type="submit"><?= htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') ?></button>
      </form>
    </div>

    <div class="import-preview-list">
      <?php foreach ($displayItems as $index => $item): ?>
        <?php
        $body = (string)($item['body'] ?? '');
        $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? $body);
        if (function_exists('mb_substr')) {
            $excerpt = mb_substr($excerpt, 0, 220);
        } else {
            $excerpt = substr($excerpt, 0, 220);
        }
        $itemWarnings = is_array($item['warnings'] ?? null) ? $item['warnings'] : [];
        $remoteImages = bms_import_extract_remote_image_urls($body);
        $stagedImages = bms_import_extract_staged_media_urls($body);
        $previewFeaturedMedia = trim((string)($item['featured_media'] ?? ''));
        $featuredRemoteImage = $previewFeaturedMedia !== '' && bms_import_is_remote_http_url($previewFeaturedMedia);
        $featuredStagedImage = $previewFeaturedMedia !== '' && bms_import_is_staged_media_url($previewFeaturedMedia);
        ?>
        <article class="import-preview-item">
          <div class="import-preview-meta">
            <span>#<?= (int)$index + 1 ?></span>
            <span><?= htmlspecialchars((string)($item['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars((string)($item['source'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            <span>Status: <?= htmlspecialchars((string)($item['status'] ?? 'draft'), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <h3><?= htmlspecialchars((string)($item['title'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8') ?></h3>
          <p class="meta">Slug: <?= htmlspecialchars((string)($item['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
          <?php if ($excerpt !== ''): ?><p><?= htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') ?><?= strlen($excerpt) >= 220 ? '…' : '' ?></p><?php endif; ?>
          <?php if ($remoteImages): ?><p class="meta">Inline remote images detected: <?= count($remoteImages) ?></p><?php endif; ?>
          <?php if ($featuredRemoteImage): ?><p class="meta">Featured remote image detected</p><?php endif; ?>
          <?php if ($stagedImages): ?><p class="meta">Archive media staged: <?= count($stagedImages) ?></p><?php endif; ?>
          <?php if ($featuredStagedImage): ?><p class="meta">Featured archive media staged</p><?php endif; ?>
          <?php if ($itemWarnings): ?><p class="meta warning-text"><?= htmlspecialchars(implode(' ', array_map('strval', $itemWarnings)), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if ($hiddenPreviewCount > 0): ?>
        <article class="import-preview-item import-preview-more">
          <h3><?= (int)$hiddenPreviewCount ?> additional item(s) prepared</h3>
          <?php if ($isBlueskyPreview): ?>
            <p class="meta">They are not displayed here to keep the admin screen responsive, but Confirm Import will process the full prepared Bluesky set.</p>
          <?php else: ?>
            <p class="meta">They are not displayed here to keep the admin screen responsive, but the optional batch controls can continue through the full prepared set.</p>
          <?php endif; ?>
        </article>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<section class="panel import-format-panel">
  <h2>Import formats</h2>
  <p class="meta">The Bonumark importer accepts Bonumark Stream export ZIP files created by Tools > Export. It safely restores database-first Markdown export entries from <code>markdown/posts</code> and <code>markdown/pages</code>, stages referenced files from the export's <code>media</code> folder, rewrites restored media references for the new install during confirmation, and preserves dates, slugs, descriptions, statuses, and tags. It does not execute database SQL from the export.</p>
  <p class="meta">The WordPress importer accepts standard WordPress export XML files from Tools > Export in WordPress. It imports posts only, skips pages, attachments, revisions, menu items, trashed posts, and auto-drafts, converts common HTML into Markdown-style content, preserves WordPress categories and tags as Bonumark Stream tags, and can optionally import remote WordPress images into Bonumark Stream Media during confirmed import. Large exports no longer need to be split manually; Bonumark stores the full prepared import privately and shows a smaller preview sample with optional batch controls.</p>
  <p class="meta">The Twitter/X importer accepts downloaded archive ZIP files. It looks for tweet data files such as <code>data/tweets.js</code>, imports authored posts, skips retweets and unsupported account data, preserves original dates, converts tweet text into Markdown-style content, turns hashtags into tags, and stages supported local archive images for Media import during confirmation. Large archives no longer need to be split manually; Bonumark stores the full prepared import privately and shows a smaller preview sample with optional batch controls.</p>
  <p class="meta">The Bluesky importer treats AT Protocol repository <code>.car</code> files as the primary export format. It reads <code>app.bsky.feed.post</code> records, skips replies and repost records, preserves original dates, converts post text into Markdown-style content, turns hashtags into tags, and preserves links found in post text or external link cards. Large Bluesky archives do not need to be split manually; Bonumark stores the full prepared import privately, shows a smaller preview sample, and imports the prepared posts when confirmed. Bluesky CAR exports currently import text, timestamps, hashtags, and links only. Media import is not available from Bluesky CAR exports at this time.</p>
  <p class="meta">The JSON importer accepts an array of posts or an object containing <code>posts</code>, <code>items</code>, <code>entries</code>, <code>data</code>, or <code>records</code>. Common fields like <code>title</code>, <code>slug</code>, <code>body</code>, <code>content</code>, <code>text</code>, <code>date</code>, <code>created_at</code>, <code>status</code>, and <code>tags</code> are normalized.</p>
</section>
<?php bms_admin_footer(); ?>
