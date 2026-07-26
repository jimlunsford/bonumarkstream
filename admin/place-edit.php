<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/places.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('edit_content');

$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$place = $id > 0 ? bms_place_get($id) : null;
if ($id > 0 && $place === null) {
    bms_admin_error_page('Place not found', 'The saved place could not be found.', 404, [
        ['label' => 'Local Places', 'href' => bms_admin_url('places.php'), 'style' => 'primary'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    try {
        $place = bms_place_save($_POST, $id);
        bms_flash($id > 0 ? 'Place updated.' : 'Place saved.', 'success');
        bms_redirect(bms_admin_url('place-edit.php?id=' . (int)$place['id']));
    } catch (InvalidArgumentException $e) {
        bms_flash('Check the place name and coordinates, then try again.', 'error');
        $place = [
            'id' => $id,
            'name' => (string)($_POST['name'] ?? ''),
            'category' => bms_place_normalize_category((string)($_POST['category'] ?? 'other')),
            'area_label' => (string)($_POST['area_label'] ?? ''),
            'locality' => (string)($_POST['locality'] ?? ''),
            'region' => (string)($_POST['region'] ?? ''),
            'country' => (string)($_POST['country'] ?? ''),
            'latitude' => (string)($_POST['latitude'] ?? ''),
            'longitude' => (string)($_POST['longitude'] ?? ''),
            'default_display_mode' => bms_place_normalize_display_mode((string)($_POST['default_display_mode'] ?? 'exact')),
        ];
    } catch (Throwable $e) {
        bms_log_admin_exception('place-edit', $e);
        bms_flash('The place could not be saved. Please try again.', 'error');
    }
}

$place = $place ?: [
    'id' => 0,
    'name' => '',
    'category' => 'other',
    'area_label' => '',
    'locality' => '',
    'region' => '',
    'country' => '',
    'latitude' => '',
    'longitude' => '',
    'default_display_mode' => 'exact',
];

$title = $id > 0 ? 'Edit Place' : 'Add Place';
bms_admin_header($title, [
    ['label' => 'Local Places', 'href' => bms_admin_url('places.php'), 'style' => 'secondary'],
]);
?>
<section class="panel local-place-edit-panel">
  <p class="eyebrow">Local Places</p>
  <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
  <p>Coordinates remain private. Public posts show only the place, area, or city label you select.</p>
  <form method="post" class="settings-form local-place-edit-form" data-place-admin-form>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= (int)$place['id'] ?>">
    <div class="settings-grid local-place-fields-grid">
      <label><span>Place name</span><input type="text" name="name" maxlength="190" required value="<?= htmlspecialchars((string)$place['name'], ENT_QUOTES, 'UTF-8') ?>"></label>
      <label><span>Category</span><select name="category"><?php foreach (bms_place_categories() as $key => $label): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"<?= $key === (string)$place['category'] ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
      <label><span>Approximate area</span><input type="text" name="area_label" maxlength="190" placeholder="Downtown, campus, north side" value="<?= htmlspecialchars((string)$place['area_label'], ENT_QUOTES, 'UTF-8') ?>"></label>
      <label><span>City or locality</span><input type="text" name="locality" maxlength="190" value="<?= htmlspecialchars((string)$place['locality'], ENT_QUOTES, 'UTF-8') ?>"></label>
      <label><span>State or region</span><input type="text" name="region" maxlength="190" value="<?= htmlspecialchars((string)$place['region'], ENT_QUOTES, 'UTF-8') ?>"></label>
      <label><span>Country</span><input type="text" name="country" maxlength="120" value="<?= htmlspecialchars((string)$place['country'], ENT_QUOTES, 'UTF-8') ?>"></label>
      <label><span>Latitude</span><input type="number" name="latitude" step="0.0000001" min="-90" max="90" required value="<?= htmlspecialchars((string)$place['latitude'], ENT_QUOTES, 'UTF-8') ?>" data-place-admin-latitude></label>
      <label><span>Longitude</span><input type="number" name="longitude" step="0.0000001" min="-180" max="180" required value="<?= htmlspecialchars((string)$place['longitude'], ENT_QUOTES, 'UTF-8') ?>" data-place-admin-longitude></label>
      <label><span>Default public display</span><select name="default_display_mode"><?php foreach (bms_place_display_modes() as $key => $label): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"<?= $key === (string)$place['default_display_mode'] ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="local-place-coordinate-actions">
      <button type="button" class="button-link secondary" data-place-admin-current>Use current location</button>
      <span class="field-help" data-place-admin-status aria-live="polite">Location access begins only when you press this button.</span>
    </div>
    <button type="submit" class="primary-button"><?= $id > 0 ? 'Save Changes' : 'Save Place' ?></button>
  </form>
</section>
<?php bms_admin_footer(); ?>

