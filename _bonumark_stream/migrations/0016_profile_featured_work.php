<?php
return [
    "ALTER TABLE `{{prefix}}user_profiles` ADD COLUMN `featured_items_json` LONGTEXT NULL AFTER `interests_json`",
];
