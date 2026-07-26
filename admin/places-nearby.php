<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/places.php';
bms_require_login();
bms_require_capability('edit_content');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bms_places_nearby_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bms_places_nearby_json(['ok' => false, 'message' => 'POST required.'], 405);
}

try {
    bms_verify_csrf();
    $latitude = bms_place_coordinate($_POST['latitude'] ?? null, 'latitude');
    $longitude = bms_place_coordinate($_POST['longitude'] ?? null, 'longitude');
    $accuracy = max(0, min(5000, (int)round((float)($_POST['accuracy'] ?? 0))));
    $radius = max(250, min(2500, (int)($_POST['radius'] ?? max(500, $accuracy * 2))));
    $places = bms_places_nearby($latitude, $longitude, $radius, 12);
    bms_places_nearby_json([
        'ok' => true,
        'radius_meters' => $radius,
        'places' => $places,
    ]);
} catch (InvalidArgumentException $e) {
    bms_places_nearby_json(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    bms_log_admin_exception('places-nearby', $e);
    bms_places_nearby_json(['ok' => false, 'message' => 'Nearby places could not be loaded.'], 500);
}
