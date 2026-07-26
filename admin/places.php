<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/places.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('edit_content');

$places = bms_places_list(500);
$placeCount = count($places);
$placeCountLabel = $placeCount === 1 ? '1 saved place' : $placeCount . ' saved places';
$actions = [
    ['label' => 'Add Place', 'href' => bms_admin_url('place-edit.php'), 'style' => 'primary'],
];
bms_admin_header('Local Places', $actions);
?>
<section class="panel local-places-directory-panel">
  <header class="local-places-directory-header">
    <div>
      <p class="eyebrow">Private directory</p>
      <h2>Saved places</h2>
      <p class="local-places-directory-summary"><?= htmlspecialchars($placeCountLabel, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <span class="status-pill local-places-private-pill">Stored on this instance</span>
  </header>

  <div class="local-places-privacy-note">
    <div>
      <strong>Private coordinates</strong>
      <p>Device coordinates are used only to recognize nearby saved places. They are never printed on public posts.</p>
    </div>
  </div>

  <?php if (!$places): ?>
    <div class="admin-empty-state local-places-empty-state">
      <h3>No saved places yet</h3>
      <p>Add one here, or use the location control in either Stream Post composer when you are at the place.</p>
      <a class="primary-button" href="<?= htmlspecialchars(bms_admin_url('place-edit.php'), ENT_QUOTES, 'UTF-8') ?>">Add the first place</a>
    </div>
  <?php else: ?>
    <div class="local-places-directory-list">
      <?php foreach ($places as $place): ?>
        <?php
          $categoryLabel = bms_place_categories()[(string)$place['category']] ?? 'Other';
          $location = bms_place_location_line($place);
          $locationLabel = $location !== '' ? $location : 'Coordinates saved privately';
          $displayLabel = bms_place_display_modes()[(string)$place['default_display_mode']] ?? 'Place name and city';
          $areaLabel = trim((string)$place['area_label']);
        ?>
        <article class="local-place-directory-item">
          <div class="local-place-directory-content">
            <div class="local-place-directory-title-copy">
              <h3><?= htmlspecialchars((string)$place['name'], ENT_QUOTES, 'UTF-8') ?></h3>
              <p class="local-place-directory-location"><?= htmlspecialchars($locationLabel, ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <div class="local-place-directory-details">
              <div class="local-place-directory-detail">
                <span>Category</span>
                <strong><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></strong>
              </div>
              <div class="local-place-directory-detail">
                <span>Default public display</span>
                <strong><?= htmlspecialchars($displayLabel, ENT_QUOTES, 'UTF-8') ?></strong>
              </div>
              <?php if ($areaLabel !== ''): ?>
                <div class="local-place-directory-detail">
                  <span>Approximate area</span>
                  <strong><?= htmlspecialchars($areaLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="local-place-directory-actions">
            <a class="secondary-button" href="<?= htmlspecialchars(bms_admin_url('place-edit.php?id=' . (int)$place['id']), ENT_QUOTES, 'UTF-8') ?>">Edit place</a>
            <form method="post" action="<?= htmlspecialchars(bms_admin_url('place-delete.php'), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Delete this saved place? Existing posts keep their saved public location text.');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="id" value="<?= (int)$place['id'] ?>">
              <button type="submit" class="local-place-delete-button">Delete</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>
