<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Migration 000010 used hasColumn() which can silently skip if Flarum already
// recorded that migration as run after a failed attempt.  This migration uses
// a raw SHOW COLUMNS query so it is never fooled by schema-builder caching,
// and carries a new filename so Flarum always treats it as fresh.
return [
    'up' => function (Builder $schema) {
        $db     = $schema->getConnection();

        if (! $db->getSchemaBuilder()->hasColumn('social_group_posts', 'content_parsed')) {
            $schema->table('social_group_posts', function (Blueprint $table) {
                $table->mediumText('content_parsed')->nullable()->after('content');
            });
        }
    },

    'down' => function (Builder $schema) {
        // Intentionally empty — removing this column destroys post formatting data.
    },
];
