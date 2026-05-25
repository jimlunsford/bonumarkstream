<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.40', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.40', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('release_name', 'Sitemap Presentation Polish Pass', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'Sitemap Presentation Polish Pass', updated_at = NOW()",
];
