<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // social_group_discussions: index last_posted_user_id (used in eager loads + joins)
        if ($schema->hasTable('social_group_discussions') && ! $schema->hasIndex('social_group_discussions', ['last_posted_user_id'])) {
            $schema->table('social_group_discussions', function (Blueprint $table) {
                $table->index('last_posted_user_id', 'sgd_last_posted_user_id_index');
            });
        }

        // social_group_posts: index parent_post_id (used in nested reply queries)
        if ($schema->hasTable('social_group_posts') && $schema->hasColumn('social_group_posts', 'parent_post_id')) {
            try {
                $schema->table('social_group_posts', function (Blueprint $table) {
                    $table->index('parent_post_id', 'sgp_parent_post_id_index');
                });
            } catch (\Throwable $e) {
                // Index may already exist (added by some DB engines with the FK)
            }
        }
    },

    'down' => function (Builder $schema) {
        if ($schema->hasTable('social_group_discussions')) {
            $schema->table('social_group_discussions', function (Blueprint $table) {
                $table->dropIndexIfExists('sgd_last_posted_user_id_index');
            });
        }
        if ($schema->hasTable('social_group_posts')) {
            $schema->table('social_group_posts', function (Blueprint $table) {
                $table->dropIndexIfExists('sgp_parent_post_id_index');
            });
        }
    },
];
