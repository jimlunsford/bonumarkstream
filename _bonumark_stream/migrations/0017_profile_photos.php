<?php
return [
    "ALTER TABLE `{{prefix}}user_profiles` ADD COLUMN `profile_photos_json` LONGTEXT NULL AFTER `featured_items_json`",
];
