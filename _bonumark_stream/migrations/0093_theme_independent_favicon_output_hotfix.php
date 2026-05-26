<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.3.10', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.3.10', updated_at = NOW()",
];
