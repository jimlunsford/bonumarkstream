<?php
require_once __DIR__ . '/_bonumark_stream/app/profile-portability.php';

if (!bms_is_installed()) {
    bms_redirect(bms_url_path('install.php'));
}
if (!bms_is_logged_in()) {
    bms_redirect(bms_url_path('account.php'));
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bms_redirect(bms_url_path('account.php?section=profile'));
}

bms_verify_csrf();

$export = bms_create_current_user_profile_export_zip();
$path = (string)($export['path'] ?? '');
$filename = (string)($export['filename'] ?? 'bonumark-profile.zip');

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
readfile($path);
@unlink($path);
exit;
