<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.11', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.11', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.11', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.11', updated_at = NOW()",
];
