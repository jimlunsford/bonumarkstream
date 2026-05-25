<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.3.0', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.3.0', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('public_github_release_baseline', '0.3.0', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.3.0', updated_at = NOW()"
];
