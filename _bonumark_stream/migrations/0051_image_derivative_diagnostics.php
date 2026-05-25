<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.27', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.27', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.27', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.27', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('image_derivative_diagnostics_pass', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()"
];
