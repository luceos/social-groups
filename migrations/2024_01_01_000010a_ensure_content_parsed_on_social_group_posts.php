<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('social_group_posts', 'content_parsed')) {
            $schema->table('social_group_posts', function (Blueprint $table) {
                $table->mediumText('content_parsed')->nullable()->after('content');
            });
        }
    },

    'down' => function (Builder $schema) {
        // Intentionally left empty — dropping this column would destroy post data.
    },
];
