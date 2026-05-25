<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('public_navigation_account_links_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value, updated_at = updated_at",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('public_navigation_account_links_toggle_pass', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()"
];
