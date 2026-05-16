<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('social_group_post_reactions')) {
            $schema->create('social_group_post_reactions', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->string('reaction', 20)->default('like');

                $table->primary(['post_id', 'user_id']);
                $table->foreign('post_id')
                    ->references('id')
                    ->on('social_group_posts')
                    ->onDelete('cascade');
            });

            // Migrate existing likes → reactions
            if ($schema->hasTable('social_group_post_likes')) {
                $db     = $schema->getConnection();
                $prefix = $db->getTablePrefix();
                $db->statement(
                    "INSERT INTO `{$prefix}social_group_post_reactions` (`post_id`, `user_id`, `reaction`)
                     SELECT `post_id`, `user_id`, 'like' FROM `{$prefix}social_group_post_likes`
                     ON DUPLICATE KEY UPDATE `reaction` = `reaction`"
                );
            }
        }

        $schema->dropIfExists('social_group_post_likes');
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('social_group_post_reactions');
    },
];
