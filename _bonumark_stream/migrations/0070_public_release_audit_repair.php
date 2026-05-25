<?php
return [
    "UPDATE `{{prefix}}settings` SET `setting_value` = REPLACE(`setting_value`, '\"status_label\":\"Midnight ledger\"', '\"status_label\":\"Live microblog\"'), `updated_at` = NOW() WHERE `setting_key` = 'public_theme_settings_default' AND `setting_value` LIKE '%Midnight ledger%'",
    "UPDATE `{{prefix}}settings` SET `setting_value` = REPLACE(`setting_value`, '\"status_label\": \"Midnight ledger\"', '\"status_label\": \"Live microblog\"'), `updated_at` = NOW() WHERE `setting_key` = 'public_theme_settings_default' AND `setting_value` LIKE '%Midnight ledger%'",
];
