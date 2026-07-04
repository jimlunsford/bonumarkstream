<?php
return [
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pwa_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pwa_share_target_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pwa_theme_color', '#111827', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pwa_background_color', '#0f172a', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`"
];
