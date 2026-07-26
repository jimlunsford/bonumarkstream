<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/places.php';
bms_require_login();
bms_require_capability('edit_content');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bms_redirect(bms_admin_url('places.php'));
}

bms_verify_csrf();
$id = max(0, (int)($_POST['id'] ?? 0));
if ($id > 0 && bms_place_delete($id)) {
    bms_flash('Saved place deleted. Existing posts keep their saved public location text.', 'success');
} else {
    bms_flash('The saved place could not be deleted.', 'error');
}
bms_redirect(bms_admin_url('places.php'));
