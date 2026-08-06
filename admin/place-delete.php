<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/places.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('edit_content');

$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$place = $id > 0 ? bms_place_get($id) : null;
if ($place === null) {
    bms_admin_error_page('Place not found', 'The saved place could not be found or has already been deleted.', 404, [
        ['label' => 'Local Places', 'href' => bms_admin_url('places.php'), 'style' => 'primary'],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    bms_verify_csrf();
    $confirmation = trim((string)($_POST['confirm_name'] ?? ''));
    if (!hash_equals((string)$place['name'], $confirmation)) {
        bms_flash('Enter the exact place name to confirm deletion.', 'error');
        bms_redirect(bms_admin_url('place-delete.php?id=' . $id));
    }

    try {
        if (!bms_place_delete($id)) {
            throw new RuntimeException('The saved place could not be deleted.');
        }
        bms_flash('Saved place deleted. Existing posts keep their saved public location text.', 'success');
        bms_redirect(bms_admin_url('places.php'));
    } catch (Throwable $e) {
        bms_log_admin_exception('place-delete', $e);
        bms_flash('The saved place could not be deleted. Please try again.', 'error');
        bms_redirect(bms_admin_url('place-delete.php?id=' . $id));
    }
}

$displayMode = (string)($place['default_display_mode'] ?? 'exact');
$publicLabels = bms_place_public_labels($place, $displayMode);
$categoryLabel = bms_place_categories()[(string)($place['category'] ?? 'other')] ?? 'Other';
$locationLine = bms_place_location_line($place);

bms_admin_header('Delete Place', [
    ['label' => 'Local Places', 'href' => bms_admin_url('places.php'), 'style' => 'secondary'],
    ['label' => 'Edit Place', 'href' => bms_admin_url('place-edit.php?id=' . $id), 'style' => 'secondary'],
]);
?>
<section class="panel places-delete-hero">
  <p class="eyebrow">Permanent directory change</p>
  <h2>Delete <?= htmlspecialchars((string)$place['name'], ENT_QUOTES, 'UTF-8') ?>?</h2>
  <p class="meta">This removes the saved private place and prevents future nearby matching. Existing Stream Posts keep the public location snapshot already stored with the post.</p>
</section>

<div class="places-delete-layout">
  <section class="panel places-delete-summary">
    <p class="eyebrow">Selected place</p>
    <h2><?= htmlspecialchars((string)$place['name'], ENT_QUOTES, 'UTF-8') ?></h2>
    <dl class="places-preview-facts">
      <div><dt>Category</dt><dd><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Location</dt><dd><?= htmlspecialchars($locationLine !== '' ? $locationLine : 'Location labels not set', ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Public label</dt><dd><?= htmlspecialchars((string)$publicLabels['primary'], ENT_QUOTES, 'UTF-8') ?><?= (string)$publicLabels['secondary'] !== '' ? ' · ' . htmlspecialchars((string)$publicLabels['secondary'], ENT_QUOTES, 'UTF-8') : '' ?></dd></div>
    </dl>
  </section>

  <section class="panel places-delete-confirmation">
    <p class="eyebrow">Confirm deletion</p>
    <h2>Type the exact place name.</h2>
    <p class="meta">This action cannot be undone from the Local Places directory.</p>
    <form method="post" class="places-delete-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <label for="confirm_name">Place name</label>
      <input id="confirm_name" type="text" name="confirm_name" required autocomplete="off" spellcheck="false" placeholder="<?= htmlspecialchars((string)$place['name'], ENT_QUOTES, 'UTF-8') ?>">
      <div class="places-delete-actions">
        <button type="submit" class="danger-button">Delete Place Permanently</button>
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('place-edit.php?id=' . $id), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
      </div>
    </form>
  </section>
</div>
<?php bms_admin_footer(); ?>
