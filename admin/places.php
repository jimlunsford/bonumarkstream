<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/places.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('edit_content');

$tableReady = bms_places_table_ready();
$allPlaces = $tableReady ? bms_places_list(500) : [];
$query = trim((string)($_GET['q'] ?? ''));
$category = strtolower(trim((string)($_GET['category'] ?? '')));
if ($category !== '' && !array_key_exists($category, bms_place_categories())) {
    $category = '';
}
$places = bms_places_filter($allPlaces, $query, $category);

$placeCount = count($allPlaces);
$categoriesUsed = count(array_unique(array_map(static fn(array $place): string => (string)($place['category'] ?? 'other'), $allPlaces)));
$areaCount = count(array_filter($allPlaces, static fn(array $place): bool => trim((string)($place['area_label'] ?? '')) !== ''));
$exactDisplayCount = count(array_filter($allPlaces, static fn(array $place): bool => (string)($place['default_display_mode'] ?? 'exact') === 'exact'));
$resultCount = count($places);
$resultLabel = $resultCount === 1 ? '1 place shown' : $resultCount . ' places shown';

$actions = [
    ['label' => 'Add Place', 'href' => bms_admin_url('place-edit.php'), 'style' => 'primary'],
];
bms_admin_header('Local Places', $actions);
?>
<section class="panel places-workflow-hero">
  <div class="places-workflow-hero-copy">
    <p class="eyebrow">Private location directory</p>
    <h2>Manage reusable places without publishing private coordinates.</h2>
    <p class="meta">Saved coordinates help Bonumark recognize nearby places. Public posts receive only the place, approximate area, or city label selected for that post.</p>
  </div>
  <span class="static-pill generated">PRIVATE</span>
</section>

<?php if (!$tableReady): ?>
  <section class="panel places-attention-panel">
    <p class="eyebrow">Needs attention</p>
    <h2>Local Places is not available yet.</h2>
    <p>The Local Places database table could not be loaded. Complete the current Bonumark Stream upgrade, then return to this screen.</p>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('upgrade.php'), ENT_QUOTES, 'UTF-8') ?>">Open Upgrade</a>
  </section>
<?php else: ?>
  <section class="panel places-summary-panel">
    <div class="places-summary-grid">
      <div><span>Saved places</span><strong><?= $placeCount ?></strong></div>
      <div><span>Categories used</span><strong><?= $categoriesUsed ?></strong></div>
      <div><span>Approximate areas</span><strong><?= $areaCount ?></strong></div>
      <div><span>Exact-name defaults</span><strong><?= $exactDisplayCount ?></strong></div>
    </div>
  </section>

  <section class="panel places-nearby-panel" data-places-directory-nearby data-nearby-endpoint="<?= htmlspecialchars(bms_admin_url('places-nearby.php'), ENT_QUOTES, 'UTF-8') ?>" data-edit-base="<?= htmlspecialchars(bms_admin_url('place-edit.php?id='), ENT_QUOTES, 'UTF-8') ?>">
    <div class="places-section-header">
      <div>
        <p class="eyebrow">Nearby search</p>
        <h2>Find saved places around this device.</h2>
        <p class="meta">Location access starts only when requested. The current device coordinates are sent to this private Admin endpoint and are not stored by the search.</p>
      </div>
      <button type="button" class="button-link secondary" data-places-nearby-find<?= $placeCount < 1 ? ' disabled' : '' ?>>Find nearby places</button>
    </div>
    <input type="hidden" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>" data-places-nearby-csrf>
    <div class="places-nearby-feedback" data-places-nearby-feedback aria-live="polite">
      <?php if ($placeCount < 1): ?>
        <p>No places are available to search yet. Add a place first.</p>
      <?php else: ?>
        <p>Use this when you are at or near a saved place and want to locate its record quickly.</p>
      <?php endif; ?>
    </div>
    <div class="places-nearby-results" data-places-nearby-results hidden></div>
  </section>

  <section class="panel places-record-panel">
    <div class="places-search-region">
      <form method="get" class="places-search-form">
        <label class="sr-only" for="places_q">Search Local Places</label>
        <input id="places_q" type="search" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search names, areas, cities, categories, or display modes">
        <label class="sr-only" for="places_category">Filter by category</label>
        <select id="places_category" name="category">
          <option value="">All categories</option>
          <?php foreach (bms_place_categories() as $key => $label): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"<?= $category === $key ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Search</button>
        <?php if ($query !== '' || $category !== ''): ?>
          <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('places.php'), ENT_QUOTES, 'UTF-8') ?>">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if (!$allPlaces): ?>
      <div class="empty-state places-empty-state">
        <h2>No saved places yet.</h2>
        <p>Add a private location record here, or save one from the Stream composer when you are at the place.</p>
        <a class="primary-button" href="<?= htmlspecialchars(bms_admin_url('place-edit.php'), ENT_QUOTES, 'UTF-8') ?>">Add the first place</a>
      </div>
    <?php elseif (!$places): ?>
      <div class="empty-state places-empty-state">
        <h2>No matching places.</h2>
        <p>Try another name, area, city, category, or public display mode.</p>
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('places.php'), ENT_QUOTES, 'UTF-8') ?>">Clear search</a>
      </div>
    <?php else: ?>
      <div class="places-list-summary">
        <span><?= htmlspecialchars($resultLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <span>Sorted by name</span>
      </div>

      <div class="places-record-header" aria-hidden="true">
        <span>Place</span>
        <span>Category</span>
        <span>Public display</span>
        <span>Actions</span>
      </div>

      <div class="places-record-list" role="list" aria-label="Saved Local Places">
        <?php foreach ($places as $place): ?>
          <?php
            $placeId = (int)($place['id'] ?? 0);
            $categoryKey = (string)($place['category'] ?? 'other');
            $categoryLabel = bms_place_categories()[$categoryKey] ?? 'Other';
            $location = bms_place_location_line($place);
            $locationLabel = $location !== '' ? $location : 'Location labels not set';
            $displayMode = (string)($place['default_display_mode'] ?? 'exact');
            $displayModeLabel = bms_place_display_modes()[$displayMode] ?? 'Place name and city';
            $publicLabels = bms_place_public_labels($place, $displayMode);
            $areaLabel = trim((string)($place['area_label'] ?? ''));
          ?>
          <article class="places-record" role="listitem">
            <div class="places-record-main">
              <h2><a href="<?= htmlspecialchars(bms_admin_url('place-edit.php?id=' . $placeId), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$place['name'], ENT_QUOTES, 'UTF-8') ?></a></h2>
              <p><?= htmlspecialchars($locationLabel, ENT_QUOTES, 'UTF-8') ?></p>
              <div class="places-record-chips">
                <span class="content-record-chip neutral">Private coordinates</span>
                <?php if ($areaLabel !== ''): ?><span class="content-record-chip neutral"><?= htmlspecialchars($areaLabel, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
              </div>
            </div>

            <div class="places-record-category">
              <span class="places-mobile-label">Category</span>
              <strong><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>

            <div class="places-record-display">
              <span class="places-mobile-label">Public display</span>
              <strong><?= htmlspecialchars((string)$publicLabels['primary'], ENT_QUOTES, 'UTF-8') ?></strong>
              <?php if ((string)$publicLabels['secondary'] !== ''): ?><small><?= htmlspecialchars((string)$publicLabels['secondary'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
              <small><?= htmlspecialchars($displayModeLabel, ENT_QUOTES, 'UTF-8') ?></small>
            </div>

            <div class="places-record-actions">
              <details class="content-actions-menu" data-content-actions>
                <summary>Actions</summary>
                <div class="content-actions-menu-panel">
                  <a href="<?= htmlspecialchars(bms_admin_url('place-edit.php?id=' . $placeId), ENT_QUOTES, 'UTF-8') ?>">Edit place</a>
                  <a class="danger-link" href="<?= htmlspecialchars(bms_admin_url('place-delete.php?id=' . $placeId), ENT_QUOTES, 'UTF-8') ?>">Delete place</a>
                </div>
              </details>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php bms_admin_footer(); ?>
