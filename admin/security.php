<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
mp_require_login();
mp_redirect(mp_admin_url('system-check.php'));
