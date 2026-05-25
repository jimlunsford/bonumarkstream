<?php
return [
"ALTER TABLE `{{prefix}}users` ADD COLUMN `email_verified_at` DATETIME NULL AFTER `email`",
"ALTER TABLE `{{prefix}}users` ADD COLUMN `verification_token_hash` CHAR(64) NULL AFTER `password_hash`",
"ALTER TABLE `{{prefix}}users` ADD COLUMN `verification_token_expires_at` DATETIME NULL AFTER `verification_token_hash`",
"ALTER TABLE `{{prefix}}users` ADD KEY `verification_token_hash` (`verification_token_hash`)",
"UPDATE `{{prefix}}users` SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE status = 'active'",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('registration_mode', 'disabled', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('registration_default_role', 'commenter', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('registration_require_email_verification', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('registration_honeypot_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('comment_registration_enabled', '0', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0', updated_at = NOW()"
];
