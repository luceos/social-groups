<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('social_groups', 'is_featured')) {
            $schema->table('social_groups', function (Blueprint $table) {
                $table->boolean('is_featured')->default(false)->after('is_private');
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('social_groups', 'is_featured')) {
            $schema->table('social_groups', function (Blueprint $table) {
                $table->dropColumn('is_featured');
            });
        }
    },
];
