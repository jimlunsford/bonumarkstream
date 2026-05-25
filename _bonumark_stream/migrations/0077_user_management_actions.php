<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('user_management_actions_pass', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()"
];
