<?php

use Illuminate\Database\Schema\Builder;

// Migration 000010 used hasColumn() which can silently skip if Flarum already
// recorded that migration as run after a failed attempt.  This migration uses
// a raw SHOW COLUMNS query so it is never fooled by schema-builder caching,
// and carries a new filename so Flarum always treats it as fresh.
return [
    'up' => function (Builder $schema) {
        $db     = $schema->getConnection();
        $prefix = $db->getTablePrefix();

        $exists = $db->select(
            "SHOW COLUMNS FROM `{$prefix}social_group_posts` LIKE 'content_parsed'"
        );

        if (empty($exists)) {
            $db->statement(
                "ALTER TABLE `{$prefix}social_group_posts` ADD COLUMN `content_parsed` MEDIUMTEXT NULL AFTER `content`"
            );
        }
    },

    'down' => function (Builder $schema) {
        // Intentionally empty — removing this column destroys post formatting data.
    },
];
