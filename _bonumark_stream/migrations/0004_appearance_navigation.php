<?php
$defaultNav = json_encode([
    ['label' => 'Home', 'url' => '/', 'target' => '_self'],
], JSON_UNESCAPED_SLASHES);

return [
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('public_theme', 'bonumark_dark', NOW())",
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('homepage_eyebrow', 'Own your short-form publishing', NOW())",
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('site_footer_text', '', NOW())",
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('show_powered_by', '1', NOW())",
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('primary_navigation', '" . str_replace("'", "''", $defaultNav) . "', NOW())"
];
