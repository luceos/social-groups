<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('social_group_post_likes')) {
            $schema->create('social_group_post_likes', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('created_at')->useCurrent();

                $table->primary(['post_id', 'user_id']);
                $table->index('post_id');

                $table->foreign('post_id')
                      ->references('id')->on('social_group_posts')
                      ->onDelete('cascade');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('social_group_post_likes');
    },
];
