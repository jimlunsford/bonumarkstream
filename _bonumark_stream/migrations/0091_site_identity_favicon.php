<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.3.8', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.3.8', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('site_favicon_media_id', '0', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value, updated_at = updated_at",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('site_favicon_path', '', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value, updated_at = updated_at",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('site_identity_favicon_pass', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()"
];
