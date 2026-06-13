<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}api_idempotency_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_id` BIGINT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(120) NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `response_status` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `response_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL,
  `last_used_at` DATETIME NOT NULL,
  `expires_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_id_key` (`token_id`, `idempotency_key`),
  KEY `expires_at` (`expires_at`),
  KEY `request_hash` (`request_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('remote_posting_direct_publish_enabled', '0', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('remote_posting_default_status', 'draft', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('remote_posting_publish_confirmation_required', '1', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`"
];
