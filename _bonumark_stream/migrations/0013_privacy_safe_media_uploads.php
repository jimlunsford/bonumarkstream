<?php
return [
    "ALTER TABLE `{{prefix}}media` ADD `privacy_status` VARCHAR(30) NOT NULL DEFAULT 'legacy_unchecked' AFTER `image_variants_json`",
    "ALTER TABLE `{{prefix}}media` ADD `privacy_note` VARCHAR(255) NOT NULL DEFAULT '' AFTER `privacy_status`",
    "ALTER TABLE `{{prefix}}media` ADD `privacy_checked_at` DATETIME NULL AFTER `privacy_note`",
    "INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('media_privacy_mode', 'best_effort', NOW()) ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`",
];
