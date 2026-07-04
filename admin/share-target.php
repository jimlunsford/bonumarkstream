<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pwa.php';

bms_require_installed();

if (!bms_pwa_share_target_enabled()) {
    bms_flash('Mobile share target is disabled in Stream settings.', 'info');
    bms_redirect(bms_url_path());
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isPendingContinuation = $method === 'GET' && (string)($_GET['pending'] ?? '') === '1';

// Web Share Target POSTs originate outside the app, so they cannot carry the
// app CSRF token. They never publish content. The payload is kept only in the
// session, rate-limited, and then sent to the authenticated front composer.
if ($method === 'POST') {
    if (!bms_request_origin_is_same_site_or_absent()) {
        bms_abort_request('Invalid share request origin.', 403);
    }

    if (bms_share_target_rate_limited()) {
        bms_flash('Too many mobile share attempts. Try again in a few minutes.', 'error');
        bms_redirect(bms_url_path());
    }

    $incoming = bms_share_target_payload_from_array($_POST);
    if (bms_share_target_payload_is_empty($incoming)) {
        bms_flash('No shared text or URL was received. Use the front composer to write a normal stream post.', 'info');
        bms_redirect(bms_url_path());
    }

    bms_share_target_store_pending($incoming);
    if (!bms_is_logged_in()) {
        bms_redirect(bms_admin_url('login.php?return_to=' . rawurlencode(bms_admin_url('share-target.php?pending=1'))));
    }
} elseif (!$isPendingContinuation) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

bms_require_login();
bms_require_capability('publish_content');

$payload = bms_share_target_pending_payload();
if (bms_share_target_payload_is_empty($payload)) {
    bms_flash('No shared text or URL was received. Use the front composer to write a normal stream post.', 'info');
    bms_redirect(bms_url_path());
}

bms_redirect(bms_share_target_front_composer_url());
