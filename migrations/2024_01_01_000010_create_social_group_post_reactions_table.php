<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('social_group_post_reactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('user_id');
            $table->string('reaction', 20);
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);

            $table->foreign('post_id')
                  ->references('id')->on('social_group_posts')
                  ->cascadeOnDelete();

            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('social_group_post_reactions');
    },
];
