<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_appearance');
mp_redirect(mp_admin_url('theme.php'));
