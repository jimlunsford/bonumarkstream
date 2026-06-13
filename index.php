<?php
require_once __DIR__ . '/_bonumark_stream/app/functions.php';

$route = (string)($_GET['__bonumark_route'] ?? '');
if (in_array($route, ['api_status', 'api_stream_posts', 'api_media', 'api_media_import'], true)) {
    require_once __DIR__ . '/_bonumark_stream/app/api.php';
    if ($route === 'api_status') {
        bms_api_handle_status_endpoint();
    }
    if ($route === 'api_stream_posts') {
        bms_api_handle_stream_posts_endpoint();
    }
    if ($route === 'api_media') {
        bms_api_handle_media_endpoint();
    }
    if ($route === 'api_media_import') {
        bms_api_handle_media_import_endpoint();
    }
}

if (!bms_is_installed()) {
    bms_redirect(bms_url_path('install.php'));
}

if ($route === '' && isset($_GET['stream_page'])) {
    // Load More uses this explicit query key so shared-hosting installs do not
    // need a separate endpoint or a successful clean URL rewrite to page the stream.
    $route = 'stream';
}
if ($route !== '') {
    require_once __DIR__ . '/_bonumark_stream/app/routes.php';
    if (bms_dispatch_public_route($route)) {
        exit;
    }
}

require_once __DIR__ . '/_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_bonumark_stream/app/renderer.php';

if (bms_homepage_mode() === 'stream') {
    $includeComposer = function_exists('bms_is_logged_in') && bms_is_logged_in() && bms_stream_composer_enabled();
    echo bms_render_stream_index(bms_list_content_records('published'), $includeComposer, 1, 'home');
    exit;
}

bms_redirect(bms_admin_url());
