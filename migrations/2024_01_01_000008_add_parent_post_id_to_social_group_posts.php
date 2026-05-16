<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('social_group_posts', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_post_id')->nullable()->after('user_id');
            $table->foreign('parent_post_id')
                  ->references('id')->on('social_group_posts')
                  ->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('social_group_posts', function (Blueprint $table) {
            $table->dropForeign(['parent_post_id']);
            $table->dropColumn('parent_post_id');
        });
    },
];
