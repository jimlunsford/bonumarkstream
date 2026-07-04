<?php
require_once __DIR__ . '/_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_bonumark_stream/app/pwa.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

echo json_encode(bms_pwa_manifest_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
