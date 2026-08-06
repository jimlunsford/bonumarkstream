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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    bms_verify_csrf();
    try {
        $place = bms_place_save($_POST, $id);
        bms_flash($id > 0 ? 'Place updated.' : 'Place saved.', 'success');
        bms_redirect(bms_admin_url('place-edit.php?id=' . (int)$place['id']));
    } catch (InvalidArgumentException $e) {
        bms_flash('The place name and coordinates are required and must be valid.', 'error');
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

$isEdit = $id > 0;
$title = $isEdit ? 'Edit Place' : 'Add Place';
$categoryLabel = bms_place_categories()[(string)$place['category']] ?? 'Other';
$displayModeLabel = bms_place_display_modes()[(string)$place['default_display_mode']] ?? 'Place name and city';
$publicLabels = bms_place_public_labels($place, (string)$place['default_display_mode']);
$hasPreviewLabel = trim((string)$publicLabels['primary']) !== '';
$previewPrimary = $hasPreviewLabel ? (string)$publicLabels['primary'] : 'Enter a place name to preview the public label';
$previewSecondary = $hasPreviewLabel ? (string)$publicLabels['secondary'] : '';
$locationLine = bms_place_location_line($place);
$coordinatesReady = is_numeric($place['latitude']) && is_numeric($place['longitude']);

bms_admin_header($title, [
    ['label' => 'Local Places', 'href' => bms_admin_url('places.php'), 'style' => 'secondary'],
]);
?>
<section class="panel places-workflow-hero">
  <div class="places-workflow-hero-copy">
    <p class="eyebrow">Local Places</p>
    <h2><?= $isEdit ? 'Manage a reusable private place.' : 'Save a reusable private place.' ?></h2>
    <p class="meta">Coordinates stay inside this Bonumark Stream installation. Public posts use only the label previewed on this screen.</p>
  </div>
  <span class="static-pill <?= $isEdit ? 'generated' : 'draft' ?>"><?= $isEdit ? 'SAVED PLACE' : 'NEW PLACE' ?></span>
</section>

<form method="post" class="places-editor-form" data-place-admin-form data-place-editor data-nearby-endpoint="<?= htmlspecialchars(bms_admin_url('places-nearby.php'), ENT_QUOTES, 'UTF-8') ?>" data-edit-base="<?= htmlspecialchars(bms_admin_url('place-edit.php?id='), ENT_QUOTES, 'UTF-8') ?>" data-current-place-id="<?= (int)$place['id'] ?>">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="id" value="<?= (int)$place['id'] ?>">

  <div class="places-editor-layout">
    <div class="places-editor-main">
      <section class="panel places-section-panel">
        <div class="places-section-header">
          <div>
            <p class="eyebrow">Identity</p>
            <h2>Name and public location labels</h2>
            <p class="meta">Use clear labels that remain useful when this place appears on a Stream Post months or years later.</p>
          </div>
        </div>

        <div class="places-field-grid">
          <div class="places-field-card places-field-span">
            <label for="place_name">Place name</label>
            <input id="place_name" type="text" name="name" maxlength="190" required autocomplete="organization" value="<?= htmlspecialchars((string)$place['name'], ENT_QUOTES, 'UTF-8') ?>" data-place-preview-name>
            <p class="field-help">The exact public name, such as Hardy Lake, Clifty Inn, or a local restaurant.</p>
          </div>

          <div class="places-field-card">
            <label for="place_category">Category</label>
            <select id="place_category" name="category">
              <?php foreach (bms_place_categories() as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"<?= $key === (string)$place['category'] ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="places-field-card">
            <label for="place_area">Approximate area</label>
            <input id="place_area" type="text" name="area_label" maxlength="190" placeholder="Downtown, campus, north side" value="<?= htmlspecialchars((string)$place['area_label'], ENT_QUOTES, 'UTF-8') ?>" data-place-preview-area>
            <p class="field-help">Used when the public display mode is Approximate area.</p>
          </div>

          <div class="places-field-card">
            <label for="place_locality">City or locality</label>
            <input id="place_locality" type="text" name="locality" maxlength="190" autocomplete="address-level2" value="<?= htmlspecialchars((string)$place['locality'], ENT_QUOTES, 'UTF-8') ?>" data-place-preview-locality>
          </div>

          <div class="places-field-card">
            <label for="place_region">State or region</label>
            <input id="place_region" type="text" name="region" maxlength="190" autocomplete="address-level1" value="<?= htmlspecialchars((string)$place['region'], ENT_QUOTES, 'UTF-8') ?>" data-place-preview-region>
          </div>

          <div class="places-field-card places-field-span">
            <label for="place_country">Country</label>
            <input id="place_country" type="text" name="country" maxlength="120" autocomplete="country-name" value="<?= htmlspecialchars((string)$place['country'], ENT_QUOTES, 'UTF-8') ?>" data-place-preview-country>
          </div>
        </div>
      </section>

      <section class="panel places-section-panel">
        <div class="places-section-header">
          <div>
            <p class="eyebrow">Private location</p>
            <h2>Coordinates and nearby matching</h2>
            <p class="meta">Coordinates are required for nearby recognition, but they are never printed on public Stream Posts.</p>
          </div>
          <span class="status-pill <?= $coordinatesReady ? 'published' : 'draft' ?>" data-place-coordinate-status><?= $coordinatesReady ? 'COORDINATES SAVED' : 'COORDINATES NEEDED' ?></span>
        </div>

        <div class="places-field-grid">
          <div class="places-field-card">
            <label for="place_latitude">Latitude</label>
            <input id="place_latitude" type="number" name="latitude" step="0.0000001" min="-90" max="90" required inputmode="decimal" value="<?= htmlspecialchars((string)$place['latitude'], ENT_QUOTES, 'UTF-8') ?>" data-place-admin-latitude>
          </div>
          <div class="places-field-card">
            <label for="place_longitude">Longitude</label>
            <input id="place_longitude" type="number" name="longitude" step="0.0000001" min="-180" max="180" required inputmode="decimal" value="<?= htmlspecialchars((string)$place['longitude'], ENT_QUOTES, 'UTF-8') ?>" data-place-admin-longitude>
          </div>
        </div>

        <div class="places-coordinate-actions">
          <button type="button" class="button-link secondary" data-place-admin-current>Use current location</button>
          <button type="button" class="button-link secondary" data-place-editor-nearby>Check for nearby saved places</button>
        </div>
        <p class="field-help places-coordinate-feedback" data-place-admin-status aria-live="polite">Location access begins only when you press Use current location. Nearby matching uses the coordinates currently shown above.</p>
        <div class="places-editor-nearby-results" data-place-editor-nearby-results hidden></div>
      </section>
    </div>

    <aside class="places-editor-rail">
      <section class="panel places-preview-panel">
        <div class="places-section-header">
          <div>
            <p class="eyebrow">Public display</p>
            <h2>Location preview</h2>
            <p class="meta">This is the default label used when the place is attached to a post.</p>
          </div>
        </div>

        <div class="places-public-preview<?= $hasPreviewLabel ? '' : ' is-empty' ?>" aria-live="polite" data-place-preview-container>
          <span class="places-preview-marker" aria-hidden="true" data-place-preview-marker<?= $hasPreviewLabel ? '' : ' hidden' ?>>
            <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
              <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path>
              <circle cx="12" cy="10" r="2.5"></circle>
            </svg>
          </span>
          <div>
            <strong data-place-preview-primary<?= $hasPreviewLabel ? '' : ' class="is-placeholder"' ?>><?= htmlspecialchars($previewPrimary, ENT_QUOTES, 'UTF-8') ?></strong>
            <span data-place-preview-secondary<?= $previewSecondary === '' ? ' hidden' : '' ?>><?= htmlspecialchars($previewSecondary, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        </div>

        <div class="places-field-card">
          <label for="place_display_mode">Default public display</label>
          <select id="place_display_mode" name="default_display_mode" data-place-preview-mode>
            <?php foreach (bms_place_display_modes() as $key => $label): ?>
              <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"<?= $key === (string)$place['default_display_mode'] ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <p class="field-help">Each Stream Post can override this default without changing the saved place.</p>
        </div>

        <dl class="places-preview-facts">
          <div><dt>Category</dt><dd data-place-preview-category-fact><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div><dt>Current location line</dt><dd data-place-preview-location-fact><?= htmlspecialchars($locationLine !== '' ? $locationLine : 'Not set', ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div><dt>Display rule</dt><dd data-place-preview-mode-fact><?= htmlspecialchars($displayModeLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
        </dl>
      </section>

      <section class="panel places-save-panel">
        <p class="eyebrow">Save</p>
        <h2><?= $isEdit ? 'Update this place' : 'Create this place' ?></h2>
        <p class="meta"><?= $isEdit ? 'Existing posts keep the location snapshot saved when they were published.' : 'The place becomes available in the Stream composer and full editor immediately.' ?></p>
        <button type="submit" class="primary-button"><?= $isEdit ? 'Save Changes' : 'Save Place' ?></button>
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('places.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
      </section>

      <?php if ($isEdit): ?>
        <section class="panel places-danger-panel">
          <p class="eyebrow">Destructive action</p>
          <h2>Delete saved place</h2>
          <p class="meta">Deleting this directory record does not erase the public location text already saved on existing posts.</p>
          <a class="button-link secondary danger-link" href="<?= htmlspecialchars(bms_admin_url('place-delete.php?id=' . (int)$place['id']), ENT_QUOTES, 'UTF-8') ?>">Delete Place</a>
        </section>
      <?php endif; ?>
    </aside>
  </div>
</form>
<?php bms_admin_footer(); ?>
