<?php
return [
    "INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('default_theme_assets_version', '1.0.3', NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
];
