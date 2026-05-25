<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Static public pages fetch this endpoint to hydrate the front-end composer.
// It intentionally fails quietly instead of using the normal admin redirect flow.
if (!mp_is_installed() || !mp_is_logged_in()) {
    http_response_code(204);
    exit;
}

$canEdit = function_exists('mp_current_user_can') && mp_current_user_can('edit_content');
$canPublish = function_exists('mp_current_user_can') && mp_current_user_can('publish_content');
$requiresReview = function_exists('mp_current_user_requires_post_review') && mp_current_user_requires_post_review();

if (!$canEdit || (!$canPublish && !$requiresReview) || !mp_stream_composer_enabled()) {
    http_response_code(204);
    exit;
}

$returnTo = mp_stream_safe_return_url((string)($_GET['return_to'] ?? mp_url_path()));
echo mp_render_stream_composer($returnTo);
