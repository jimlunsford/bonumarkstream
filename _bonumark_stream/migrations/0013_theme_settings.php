<?php
$defaultThemeSettings = json_encode([
    'show_status_chip' => '1',
    'status_label' => 'Live microblog',
    'show_post_count' => '1',
    'menu_label' => 'Menu',
], JSON_UNESCAPED_SLASHES);

return [
    "INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('active_public_theme', 'default', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('public_theme_settings_default', '" . str_replace("'", "''", $defaultThemeSettings) . "', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
];
