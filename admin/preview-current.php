<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/preview.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mp_admin_error_page('Preview unavailable', 'Open current-content previews from the editor.', 405);
}

mp_verify_csrf();
try {
    $page = mp_preview_current_page_from_request();
    header('X-Robots-Tag: noindex, nofollow', true);
    echo mp_admin_preview_document($page, 'Current unsaved editor content');
} catch (Throwable $e) {
    mp_admin_error_page('Preview failed', 'Bonumark could not build a preview from the current editor content. ' . $e->getMessage(), 500);
}
