<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
bms_require_login();
bms_redirect(bms_admin_url('system-check.php'));
