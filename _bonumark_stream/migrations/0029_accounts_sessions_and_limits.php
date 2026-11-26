<?php
// Accounts, sessions, and abuse limits.
//
// Every statement is safe to repeat after a partial failure. Existing account
// rows are never deleted, and no existing column value is rewritten: email
// claims live in a new column. Accounts that already share an email address
// keep their addresses unchanged and stay unclaimed; System Check lists them
// so the owner can resolve each conflict.
return [
    // Credential generation. A signed-in session records the value it was
    // created with and is rejected once the value changes.
    "ALTER TABLE `{{prefix}}users` ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `password_hash`",

    // Unique normalized email claim. NULL when the address is blank or shared.
    "ALTER TABLE `{{prefix}}users` ADD COLUMN `email_normalized` VARCHAR(190) NULL DEFAULT NULL AFTER `email`",

    // A self-service email change waits here until the new address confirms it.
    "ALTER TABLE `{{prefix}}users` ADD COLUMN `pending_email` VARCHAR(190) NULL DEFAULT NULL AFTER `email_verified_at`",
    "ALTER TABLE `{{prefix}}users` ADD COLUMN `pending_email_token_hash` CHAR(64) NULL DEFAULT NULL AFTER `pending_email`",
    "ALTER TABLE `{{prefix}}users` ADD COLUMN `pending_email_expires_at` DATETIME NULL DEFAULT NULL AFTER `pending_email_token_hash`",
    "ALTER TABLE `{{prefix}}users` ADD KEY `pending_email_token_hash` (`pending_email_token_hash`)",

    // Clear any repeated claim left by an interrupted earlier attempt so the
    // unique key below can always be created. Only the new column is touched.
    "UPDATE `{{prefix}}users` u INNER JOIN (SELECT `email_normalized` AS `repeated_claim` FROM `{{prefix}}users` WHERE `email_normalized` IS NOT NULL GROUP BY `email_normalized` HAVING COUNT(*) > 1) repeated ON repeated.`repeated_claim` = u.`email_normalized` SET u.`email_normalized` = NULL",
    "ALTER TABLE `{{prefix}}users` ADD UNIQUE KEY `email_normalized` (`email_normalized`)",

    // Claim each address held by exactly one account. Shared addresses stay
    // unclaimed, so duplicates never fail the upgrade.
    "UPDATE `{{prefix}}users` u INNER JOIN (SELECT LOWER(TRIM(`email`)) AS `normalized_email` FROM `{{prefix}}users` WHERE TRIM(`email`) <> '' GROUP BY LOWER(TRIM(`email`)) HAVING COUNT(*) = 1) single ON single.`normalized_email` = LOWER(TRIM(u.`email`)) LEFT JOIN (SELECT `email_normalized` AS `claimed_email` FROM `{{prefix}}users` WHERE `email_normalized` IS NOT NULL GROUP BY `email_normalized`) claimed ON claimed.`claimed_email` = single.`normalized_email` SET u.`email_normalized` = single.`normalized_email` WHERE u.`email_normalized` IS NULL AND claimed.`claimed_email` IS NULL",

    // Login throttling looks up failures by address and by username and address.
    "ALTER TABLE `{{prefix}}login_attempts` ADD KEY `ip_attempted` (`ip_hash`, `attempted_at`)",
    "ALTER TABLE `{{prefix}}login_attempts` ADD KEY `username_ip_attempted` (`username`, `ip_hash`, `attempted_at`)",

    // Comment rate limits look up recent comments by account and by address.
    "ALTER TABLE `{{prefix}}comments` ADD KEY `user_created` (`user_id`, `created_at`)",
    "ALTER TABLE `{{prefix}}comments` ADD KEY `ip_created` (`ip_hash`, `created_at`)",

    // Holding a new commenter's first comment for review is off by default.
    "INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('comments_hold_first_comment', '0', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`",

    // The Sendmail program is read only from config.php. Remove the unused row.
    "DELETE FROM `{{prefix}}settings` WHERE `setting_key` = 'mail_sendmail_path'",
];
