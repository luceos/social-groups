<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('social_group_discussions', function (Blueprint $table) {
            $table->boolean('is_gallery')->default(false)->after('is_locked');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('social_group_discussions', function (Blueprint $table) {
            $table->dropColumn('is_gallery');
        });
    },
];
