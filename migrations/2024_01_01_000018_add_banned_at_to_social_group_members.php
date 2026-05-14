<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('social_group_members', 'banned_at')) {
            $schema->table('social_group_members', function (Blueprint $table) {
                $table->timestamp('banned_at')->nullable()->after('joined_at');
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('social_group_members', 'banned_at')) {
            $schema->table('social_group_members', function (Blueprint $table) {
                $table->dropColumn('banned_at');
            });
        }
    },
];
