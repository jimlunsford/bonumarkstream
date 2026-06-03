<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/preview.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bms_admin_error_page('Preview unavailable', 'Open current-content previews from the editor.', 405);
}

bms_verify_csrf();
try {
    $page = bms_preview_current_page_from_request();
    header('X-Robots-Tag: noindex, nofollow', true);
    echo bms_admin_preview_document($page, 'Current unsaved editor content');
} catch (Throwable $e) {
    bms_admin_error_page('Preview failed', 'Bonumark could not build a preview from the current editor content. ' . $e->getMessage(), 500);
}
