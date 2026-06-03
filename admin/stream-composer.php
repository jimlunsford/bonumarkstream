<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Static public pages fetch this endpoint to hydrate the front-end composer.
// It intentionally fails quietly instead of using the normal admin redirect flow.
if (!bms_is_installed() || !bms_is_logged_in()) {
    http_response_code(204);
    exit;
}

$canEdit = function_exists('bms_current_user_can') && bms_current_user_can('edit_content');
$canPublish = function_exists('bms_current_user_can') && bms_current_user_can('publish_content');
if (!$canEdit || !$canPublish || !bms_stream_composer_enabled()) {
    http_response_code(204);
    exit;
}

$returnTo = bms_stream_safe_return_url((string)($_GET['return_to'] ?? bms_url_path()));
echo bms_render_stream_composer($returnTo);
