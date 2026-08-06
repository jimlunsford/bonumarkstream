<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
bms_require_login();
bms_require_capability('edit_content');

bms_flash('New Stream Posts are now created from the stream composer. The full editor remains available after a draft is saved.', 'info');
bms_redirect(bms_stream_composer_url());
