<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/places.php';
bms_require_login();
bms_require_capability('edit_content');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bms_places_save_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bms_places_save_json(['ok' => false, 'message' => 'POST required.'], 405);
}

try {
    bms_verify_csrf();
    $place = bms_place_save($_POST);
    bms_places_save_json(['ok' => true, 'place' => $place]);
} catch (InvalidArgumentException $e) {
    bms_places_save_json(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    bms_log_admin_exception('places-save', $e);
    bms_places_save_json(['ok' => false, 'message' => 'The place could not be saved.'], 500);
}
