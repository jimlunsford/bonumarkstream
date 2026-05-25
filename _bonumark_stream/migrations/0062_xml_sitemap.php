<?php
return [
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.38', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.38', updated_at = NOW()",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.38', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.38', updated_at = NOW()",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('sitemap_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`, `updated_at` = `updated_at`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('sitemap_include_stream_posts', '1', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`, `updated_at` = `updated_at`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('sitemap_include_pages', '1', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`, `updated_at` = `updated_at`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('sitemap_include_profiles', '0', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`, `updated_at` = `updated_at`"
];
