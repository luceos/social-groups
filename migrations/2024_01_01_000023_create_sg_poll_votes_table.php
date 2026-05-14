<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('sg_poll_votes')) {
            $schema->create('sg_poll_votes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('poll_id')->index();
                $table->unsignedBigInteger('option_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['poll_id', 'option_id', 'user_id'], 'sg_poll_votes_unique');

                $table->foreign('poll_id')
                      ->references('id')->on('sg_polls')
                      ->onDelete('cascade');
                $table->foreign('option_id')
                      ->references('id')->on('sg_poll_options')
                      ->onDelete('cascade');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('sg_poll_votes');
    },
];
