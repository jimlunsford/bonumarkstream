<?php
require_once __DIR__ . '/database.php';

function bms_place_categories(): array
{
    return [
        'restaurant' => 'Restaurant',
        'cafe' => 'Cafe',
        'bar' => 'Bar',
        'store' => 'Store',
        'park' => 'Park',
        'outdoors' => 'Outdoors',
        'entertainment' => 'Entertainment',
        'arts-culture' => 'Arts and culture',
        'hotel' => 'Hotel',
        'government' => 'Government',
        'school' => 'School',
        'workplace' => 'Workplace',
        'transportation' => 'Transportation',
        'healthcare' => 'Healthcare',
        'religious' => 'Religious place',
        'residence' => 'Residence',
        'other' => 'Other',
    ];
}

function bms_place_display_modes(): array
{
    return [
        'exact' => 'Place name and city',
        'area' => 'Approximate area',
        'city' => 'City only',
    ];
}

function bms_place_normalize_display_mode(string $mode): string
{
    return array_key_exists($mode, bms_place_display_modes()) ? $mode : 'exact';
}

function bms_place_normalize_category(string $category): string
{
    $category = strtolower(trim($category));
    return array_key_exists($category, bms_place_categories()) ? $category : 'other';
}

function bms_place_clean_text(mixed $value, int $limit): string
{
    $value = trim(strip_tags((string)$value));
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    if (function_exists('mb_substr')) {
        return bms_text_substr($value, 0, $limit);
    }
    return substr($value, 0, $limit);
}

function bms_place_coordinate(mixed $value, string $axis): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('A valid ' . $axis . ' is required.');
    }
    $coordinate = (float)$value;
    $minimum = $axis === 'latitude' ? -90.0 : -180.0;
    $maximum = $axis === 'latitude' ? 90.0 : 180.0;
    if ($coordinate < $minimum || $coordinate > $maximum) {
        throw new InvalidArgumentException('The ' . $axis . ' is outside the valid range.');
    }
    return round($coordinate, 7);
}

function bms_places_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!function_exists('bms_is_installed') || !bms_is_installed()) {
        return $ready = false;
    }
    try {
        bms_db()->query('SELECT id FROM ' . bms_table('places') . ' LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

function bms_place_row(array $row): array
{
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'category' => bms_place_normalize_category((string)($row['category'] ?? 'other')),
        'area_label' => (string)($row['area_label'] ?? ''),
        'locality' => (string)($row['locality'] ?? ''),
        'region' => (string)($row['region'] ?? ''),
        'country' => (string)($row['country'] ?? ''),
        'latitude' => isset($row['latitude']) ? (float)$row['latitude'] : 0.0,
        'longitude' => isset($row['longitude']) ? (float)$row['longitude'] : 0.0,
        'default_display_mode' => bms_place_normalize_display_mode((string)($row['default_display_mode'] ?? 'exact')),
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

function bms_place_get(int $id): ?array
{
    if ($id < 1 || !bms_places_table_ready()) {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('places') . ' WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? bms_place_row($row) : null;
}

function bms_places_list(int $limit = 250): array
{
    if (!bms_places_table_ready()) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $rows = bms_db()->query('SELECT * FROM ' . bms_table('places') . ' ORDER BY name ASC, locality ASC, id ASC LIMIT ' . $limit)->fetchAll() ?: [];
    return array_map('bms_place_row', $rows);
}

function bms_place_search_text(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function bms_places_filter(array $places, string $query = '', string $category = ''): array
{
    $query = bms_place_search_text($query);
    $category = strtolower(trim($category));
    $categoryFilterActive = $category !== '' && array_key_exists($category, bms_place_categories());

    return array_values(array_filter($places, static function (array $place) use ($query, $category, $categoryFilterActive): bool {
        if ($categoryFilterActive && (string)($place['category'] ?? 'other') !== $category) {
            return false;
        }
        if ($query === '') {
            return true;
        }

        $categoryLabel = bms_place_categories()[(string)($place['category'] ?? 'other')] ?? 'Other';
        $displayLabel = bms_place_display_modes()[(string)($place['default_display_mode'] ?? 'exact')] ?? 'Place name and city';
        $haystack = bms_place_search_text(implode(' ', [
            (string)($place['name'] ?? ''),
            $categoryLabel,
            (string)($place['area_label'] ?? ''),
            (string)($place['locality'] ?? ''),
            (string)($place['region'] ?? ''),
            (string)($place['country'] ?? ''),
            $displayLabel,
        ]));

        return str_contains($haystack, $query);
    }));
}

function bms_place_save(array $input, int $id = 0): array
{
    if (!bms_places_table_ready()) {
        throw new RuntimeException('Local Places is not available until the database upgrade has completed.');
    }

    $name = bms_place_clean_text($input['name'] ?? '', 190);
    if ($name === '') {
        throw new InvalidArgumentException('Place name is required.');
    }

    $record = [
        'name' => $name,
        'category' => bms_place_normalize_category((string)($input['category'] ?? 'other')),
        'area_label' => bms_place_clean_text($input['area_label'] ?? '', 190),
        'locality' => bms_place_clean_text($input['locality'] ?? '', 190),
        'region' => bms_place_clean_text($input['region'] ?? '', 190),
        'country' => bms_place_clean_text($input['country'] ?? '', 120),
        'latitude' => bms_place_coordinate($input['latitude'] ?? null, 'latitude'),
        'longitude' => bms_place_coordinate($input['longitude'] ?? null, 'longitude'),
        'default_display_mode' => bms_place_normalize_display_mode((string)($input['default_display_mode'] ?? 'exact')),
    ];

    if ($id > 0) {
        $stmt = bms_db()->prepare('UPDATE ' . bms_table('places') . ' SET name = :name, category = :category, area_label = :area_label, locality = :locality, region = :region, country = :country, latitude = :latitude, longitude = :longitude, default_display_mode = :default_display_mode, updated_at = NOW() WHERE id = :id');
        $stmt->execute($record + ['id' => $id]);
        $saved = bms_place_get($id);
        if ($saved === null) {
            throw new RuntimeException('The place could not be updated.');
        }
        return $saved;
    }

    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('places') . ' (name, category, area_label, locality, region, country, latitude, longitude, default_display_mode, created_at, updated_at) VALUES (:name, :category, :area_label, :locality, :region, :country, :latitude, :longitude, :default_display_mode, NOW(), NOW())');
    $stmt->execute($record);
    $saved = bms_place_get((int)bms_db()->lastInsertId());
    if ($saved === null) {
        throw new RuntimeException('The place could not be created.');
    }
    return $saved;
}

function bms_place_delete(int $id): bool
{
    if ($id < 1 || !bms_places_table_ready()) {
        return false;
    }
    $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('places') . ' WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->rowCount() > 0;
}

function bms_place_distance_meters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
{
    $earthRadius = 6371000.0;
    $lat1 = deg2rad($latitudeA);
    $lat2 = deg2rad($latitudeB);
    $deltaLat = deg2rad($latitudeB - $latitudeA);
    $deltaLng = deg2rad($longitudeB - $longitudeA);
    $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}

function bms_places_nearby(float $latitude, float $longitude, int $radiusMeters = 500, int $limit = 12): array
{
    if (!bms_places_table_ready()) {
        return [];
    }
    $latitude = bms_place_coordinate($latitude, 'latitude');
    $longitude = bms_place_coordinate($longitude, 'longitude');
    $radiusMeters = max(25, min(5000, $radiusMeters));
    $limit = max(1, min(25, $limit));

    $latitudeDelta = $radiusMeters / 111320.0;
    $longitudeScale = max(0.01, abs(cos(deg2rad($latitude))));
    $longitudeDelta = $radiusMeters / (111320.0 * $longitudeScale);
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('places') . ' WHERE latitude BETWEEN :min_latitude AND :max_latitude AND longitude BETWEEN :min_longitude AND :max_longitude LIMIT 250');
    $stmt->execute([
        'min_latitude' => $latitude - $latitudeDelta,
        'max_latitude' => $latitude + $latitudeDelta,
        'min_longitude' => $longitude - $longitudeDelta,
        'max_longitude' => $longitude + $longitudeDelta,
    ]);

    $nearby = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $place = bms_place_row($row);
        $distance = bms_place_distance_meters($latitude, $longitude, (float)$place['latitude'], (float)$place['longitude']);
        if ($distance > $radiusMeters) {
            continue;
        }
        $place['distance_meters'] = (int)round($distance);
        $nearby[] = $place;
    }
    usort($nearby, static fn(array $a, array $b): int => ((int)$a['distance_meters']) <=> ((int)$b['distance_meters']));
    return array_slice($nearby, 0, $limit);
}

function bms_place_location_line(array $place): string
{
    $parts = array_values(array_filter([
        trim((string)($place['locality'] ?? '')),
        trim((string)($place['region'] ?? '')),
    ], static fn(string $value): bool => $value !== ''));
    if (!$parts && trim((string)($place['country'] ?? '')) !== '') {
        $parts[] = trim((string)$place['country']);
    }
    return implode(', ', array_values(array_unique($parts)));
}

function bms_place_public_labels(array $place, string $mode = ''): array
{
    $mode = bms_place_normalize_display_mode($mode !== '' ? $mode : (string)($place['default_display_mode'] ?? 'exact'));
    $name = trim((string)($place['name'] ?? ''));
    $area = trim((string)($place['area_label'] ?? ''));
    $locality = trim((string)($place['locality'] ?? ''));
    $region = trim((string)($place['region'] ?? ''));
    $country = trim((string)($place['country'] ?? ''));

    if ($mode === 'city') {
        $primary = $locality !== '' ? $locality : ($region !== '' ? $region : $country);
        $secondary = $locality !== '' && $region !== '' ? $region : ($country !== $primary ? $country : '');
    } elseif ($mode === 'area') {
        $primary = $area !== '' ? $area : ($locality !== '' ? $locality : $name);
        $secondaryParts = array_values(array_filter([$locality !== $primary ? $locality : '', $region], static fn(string $value): bool => $value !== ''));
        $secondary = implode(', ', array_unique($secondaryParts));
        if ($secondary === '' && $country !== $primary) {
            $secondary = $country;
        }
    } else {
        $primary = $name;
        $secondary = bms_place_location_line($place);
    }

    return [
        'mode' => $mode,
        'primary' => trim($primary),
        'secondary' => trim($secondary),
    ];
}

function bms_place_snapshot_fields(array $place, string $displayMode = ''): array
{
    return [
        'location_place_id' => (string)(int)($place['id'] ?? 0),
        'location_name' => (string)($place['name'] ?? ''),
        'location_category' => bms_place_normalize_category((string)($place['category'] ?? 'other')),
        'location_area' => (string)($place['area_label'] ?? ''),
        'location_locality' => (string)($place['locality'] ?? ''),
        'location_region' => (string)($place['region'] ?? ''),
        'location_country' => (string)($place['country'] ?? ''),
        'location_display_mode' => bms_place_normalize_display_mode($displayMode !== '' ? $displayMode : (string)($place['default_display_mode'] ?? 'exact')),
    ];
}

function bms_place_from_page(array $page): ?array
{
    $frontMatter = is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
    $placeId = (int)($page['location_place_id'] ?? $frontMatter['location_place_id'] ?? 0);
    $name = trim((string)($page['location_name'] ?? $frontMatter['location_name'] ?? ''));
    $locality = trim((string)($page['location_locality'] ?? $frontMatter['location_locality'] ?? ''));
    if ($placeId < 1 && $name === '' && $locality === '') {
        return null;
    }

    return [
        'id' => $placeId,
        'name' => $name,
        'category' => bms_place_normalize_category((string)($page['location_category'] ?? $frontMatter['location_category'] ?? 'other')),
        'area_label' => (string)($page['location_area'] ?? $frontMatter['location_area'] ?? ''),
        'locality' => $locality,
        'region' => (string)($page['location_region'] ?? $frontMatter['location_region'] ?? ''),
        'country' => (string)($page['location_country'] ?? $frontMatter['location_country'] ?? ''),
        'default_display_mode' => bms_place_normalize_display_mode((string)($page['location_display_mode'] ?? $frontMatter['location_display_mode'] ?? 'exact')),
    ];
}

function bms_place_existing_front_matter_for_slug(string $slug): array
{
    $slug = bms_slugify($slug);
    if ($slug === '' || !function_exists('bms_find_database_content_by_slug_status')) {
        return [];
    }
    foreach (['published', 'scheduled', 'draft'] as $status) {
        try {
            $page = bms_find_database_content_by_slug_status($slug, $status, 'stream');
            if ($page && is_array($page['front_matter'] ?? null)) {
                return $page['front_matter'];
            }
        } catch (Throwable $e) {
            return [];
        }
    }
    return [];
}

function bms_place_request_fields(string $currentSlug = ''): array
{
    $keys = ['location_place_id', 'location_name', 'location_category', 'location_area', 'location_locality', 'location_region', 'location_country', 'location_display_mode'];
    if (array_key_exists('location_control_present', $_POST)) {
        $placeId = max(0, (int)($_POST['location_place_id'] ?? 0));
        if ($placeId < 1) {
            return [];
        }
        $place = bms_place_get($placeId);
        if ($place !== null) {
            return bms_place_snapshot_fields($place, (string)($_POST['location_display_mode'] ?? ''));
        }
        $existing = bms_place_existing_front_matter_for_slug($currentSlug);
        if ((int)($existing['location_place_id'] ?? 0) === $placeId) {
            $fields = [];
            foreach ($keys as $key) {
                $value = trim((string)($existing[$key] ?? ''));
                if ($value !== '') {
                    $fields[$key] = $value;
                }
            }
            $fields['location_display_mode'] = bms_place_normalize_display_mode((string)($_POST['location_display_mode'] ?? $fields['location_display_mode'] ?? 'exact'));
            return $fields;
        }
        return [];
    }

    $existing = bms_place_existing_front_matter_for_slug($currentSlug);
    $fields = [];
    foreach ($keys as $key) {
        $value = trim((string)($existing[$key] ?? ''));
        if ($value !== '') {
            $fields[$key] = $value;
        }
    }
    return $fields;
}

function bms_place_picker_markup(array $page = [], string $context = 'editor'): string
{
    $places = bms_places_list();
    $selected = bms_place_from_page($page);
    $knownPlaceIds = array_map(static fn(array $place): int => (int)($place['id'] ?? 0), $places);
    $selectedId = (int)($selected['id'] ?? 0);
    $selectedMode = bms_place_normalize_display_mode((string)($selected['default_display_mode'] ?? 'exact'));
    $selectedLabels = $selected ? bms_place_public_labels($selected, $selectedMode) : ['primary' => '', 'secondary' => ''];
    $context = $context === 'front' ? 'front' : 'editor';
    $pickerId = 'local-places-' . $context;
    $dialogTitleId = $pickerId . '-dialog-title';
    $nearbyEndpoint = bms_admin_url('places-nearby.php');
    $saveEndpoint = bms_admin_url('places-save.php');

    ob_start();
    ?>
    <div id="<?= htmlspecialchars($pickerId, ENT_QUOTES, 'UTF-8') ?>" class="local-places-picker local-places-picker-<?= htmlspecialchars($context, ENT_QUOTES, 'UTF-8') ?>" data-local-places data-nearby-endpoint="<?= htmlspecialchars($nearbyEndpoint, ENT_QUOTES, 'UTF-8') ?>" data-save-endpoint="<?= htmlspecialchars($saveEndpoint, ENT_QUOTES, 'UTF-8') ?>"<?= $context === 'front' ? ' hidden' : '' ?>>
      <input type="hidden" name="location_control_present" value="1">
      <input type="hidden" name="location_place_id" value="<?= $selectedId ?>" data-place-id>
      <input type="hidden" name="location_display_mode" value="<?= htmlspecialchars($selectedMode, ENT_QUOTES, 'UTF-8') ?>" data-place-display-mode>

      <?php if ($context === 'editor'): ?>
        <div class="editor-card-heading"><div><p class="eyebrow">Local Places</p><h3>Location</h3></div></div>
        <p class="field-help">Choose a saved place or find one nearby. Place details are managed under Admin → Local Places.</p>
      <?php endif; ?>

      <div class="local-places-selected<?= $selected ? ' is-selected' : '' ?>" data-place-selected>
        <div class="local-places-selected-copy">
          <strong data-place-selected-primary><?= htmlspecialchars((string)$selectedLabels['primary'], ENT_QUOTES, 'UTF-8') ?></strong>
          <span data-place-selected-secondary><?= htmlspecialchars((string)$selectedLabels['secondary'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="local-places-selected-actions">
          <button type="button" class="local-places-change" data-place-change<?= $selected ? '' : ' hidden' ?>>Change</button>
          <button type="button" class="local-places-remove" data-place-remove<?= $selected ? '' : ' hidden' ?>>Remove</button>
        </div>
      </div>

      <div class="local-places-picker-body" data-place-picker-body<?= $selected ? ' hidden' : '' ?>>
        <div class="local-places-controls">
          <label>
            <span class="field-label">Saved place</span>
            <select data-place-select>
              <option value="">Choose a saved place</option>
              <?php if ($selected && $selectedId > 0 && !in_array($selectedId, $knownPlaceIds, true)): ?>
                <option value="<?= $selectedId ?>" data-place-name="<?= htmlspecialchars((string)$selected['name'], ENT_QUOTES, 'UTF-8') ?>" data-place-area="<?= htmlspecialchars((string)$selected['area_label'], ENT_QUOTES, 'UTF-8') ?>" data-place-locality="<?= htmlspecialchars((string)$selected['locality'], ENT_QUOTES, 'UTF-8') ?>" data-place-region="<?= htmlspecialchars((string)$selected['region'], ENT_QUOTES, 'UTF-8') ?>" data-place-country="<?= htmlspecialchars((string)$selected['country'], ENT_QUOTES, 'UTF-8') ?>" data-place-mode="<?= htmlspecialchars($selectedMode, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars(trim((string)$selectedLabels['primary'] . ((string)$selectedLabels['secondary'] !== '' ? ' · ' . (string)$selectedLabels['secondary'] : '')), ENT_QUOTES, 'UTF-8') ?></option>
              <?php endif; ?>
              <?php foreach ($places as $place): ?>
                <?php $labels = bms_place_public_labels($place, (string)$place['default_display_mode']); ?>
                <option value="<?= (int)$place['id'] ?>" data-place-name="<?= htmlspecialchars((string)$place['name'], ENT_QUOTES, 'UTF-8') ?>" data-place-area="<?= htmlspecialchars((string)$place['area_label'], ENT_QUOTES, 'UTF-8') ?>" data-place-locality="<?= htmlspecialchars((string)$place['locality'], ENT_QUOTES, 'UTF-8') ?>" data-place-region="<?= htmlspecialchars((string)$place['region'], ENT_QUOTES, 'UTF-8') ?>" data-place-country="<?= htmlspecialchars((string)$place['country'], ENT_QUOTES, 'UTF-8') ?>" data-place-mode="<?= htmlspecialchars((string)$place['default_display_mode'], ENT_QUOTES, 'UTF-8') ?>"<?= (int)$place['id'] === $selectedId ? ' selected' : '' ?>><?= htmlspecialchars(trim((string)$labels['primary'] . ((string)$labels['secondary'] !== '' ? ' · ' . (string)$labels['secondary'] : '')), ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="button" class="button-link secondary local-places-nearby-button" data-place-nearby>Find nearby</button>
        </div>

        <div class="local-places-nearby" data-place-nearby-results hidden></div>
        <p class="local-places-status field-help" data-place-status aria-live="polite"></p>
        <button type="button" class="local-places-add-button" data-place-create-open>+ Add a new place</button>
      </div>

      <div class="local-places-create-modal" data-place-create-modal hidden>
        <button type="button" class="local-places-create-backdrop" aria-label="Close add place dialog" data-place-create-close></button>
        <section class="local-places-create-dialog" role="dialog" aria-modal="true" aria-labelledby="<?= htmlspecialchars($dialogTitleId, ENT_QUOTES, 'UTF-8') ?>">
          <div class="local-places-create-header">
            <div>
              <p class="eyebrow">Local Places</p>
              <h3 id="<?= htmlspecialchars($dialogTitleId, ENT_QUOTES, 'UTF-8') ?>">Add a place</h3>
            </div>
            <button type="button" class="local-places-create-close" aria-label="Close" data-place-create-close>×</button>
          </div>
          <div class="local-places-create-fields">
            <label><span class="field-label">Place name</span><input type="text" maxlength="190" autocomplete="off" data-place-new-name></label>
            <label><span class="field-label">Public location label <small>optional</small></span><input type="text" maxlength="190" placeholder="Columbus, Indiana" autocomplete="address-level2" data-place-new-public-label></label>
            <input type="hidden" data-place-new-latitude>
            <input type="hidden" data-place-new-longitude>
            <p class="field-help">The device location is saved privately so Bonumark Stream can recognize this place nearby later. Only the place name and public label can appear on posts.</p>
            <p class="local-places-create-status field-help" data-place-create-status aria-live="polite"></p>
          </div>
          <div class="local-places-create-actions">
            <button type="button" class="button-link secondary" data-place-create-close>Cancel</button>
            <button type="button" class="primary-button" data-place-save>Save and use</button>
          </div>
        </section>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

function bms_place_location_view_data(array $page): ?array
{
    $place = bms_place_from_page($page);
    if ($place === null) {
        return null;
    }
    $labels = bms_place_public_labels($place, (string)$place['default_display_mode']);
    if ($labels['primary'] === '') {
        return null;
    }
    return [
        'place' => $place,
        'primary' => (string)$labels['primary'],
        'secondary' => (string)$labels['secondary'],
        'display_mode' => (string)$labels['mode'],
    ];
}

function bms_render_stream_location(array $page): string
{
    $view = bms_place_location_view_data($page);
    if ($view === null || !function_exists('bms_render_public_theme_template')) {
        return '';
    }
    return bms_render_public_theme_template('location', $view);
}
