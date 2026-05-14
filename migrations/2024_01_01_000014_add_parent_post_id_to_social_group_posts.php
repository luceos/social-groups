<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('social_group_posts') && ! $schema->hasColumn('social_group_posts', 'parent_post_id')) {
            $schema->table('social_group_posts', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_post_id')->nullable()->after('discussion_id');
                $table->foreign('parent_post_id')
                    ->references('id')
                    ->on('social_group_posts')
                    ->onDelete('cascade');
            });
        }
    },

    'down' => function (Builder $schema) {
        if ($schema->hasTable('social_group_posts') && $schema->hasColumn('social_group_posts', 'parent_post_id')) {
            $schema->table('social_group_posts', function (Blueprint $table) {
                $table->dropForeign(['parent_post_id']);
                $table->dropColumn('parent_post_id');
            });
        }
    },
];
