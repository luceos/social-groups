<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'sg_primary_group_id')) {
            $schema->table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('sg_primary_group_id')->nullable()->after('id');
                $table->foreign('sg_primary_group_id')
                      ->references('id')->on('social_groups')
                      ->nullOnDelete();
            });
        }
    },

    'down' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'sg_primary_group_id')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropForeign(['sg_primary_group_id']);
                $table->dropColumn('sg_primary_group_id');
            });
        }
    },
];
