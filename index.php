<?php
require_once __DIR__ . '/_bonumark_stream/app/functions.php';

if (!mp_is_installed()) {
    mp_redirect(mp_url_path('install.php'));
}

$route = (string)($_GET['__bonumark_route'] ?? '');
if ($route === '' && isset($_GET['stream_page'])) {
    // Load More uses this explicit query key so shared-hosting installs do not
    // need a separate endpoint or a successful clean URL rewrite to page the stream.
    $route = 'stream';
}
if ($route !== '') {
    require_once __DIR__ . '/_bonumark_stream/app/routes.php';
    if (mp_dispatch_public_route($route)) {
        exit;
    }
}

require_once __DIR__ . '/_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_bonumark_stream/app/renderer.php';

if (mp_homepage_mode() === 'stream') {
    $includeComposer = function_exists('mp_is_logged_in') && mp_is_logged_in() && mp_stream_composer_enabled();
    echo mp_render_stream_index(mp_list_content_records('published'), $includeComposer, 1, 'home');
    exit;
}

mp_redirect(mp_admin_url());
