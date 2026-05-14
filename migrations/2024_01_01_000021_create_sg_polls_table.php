<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('sg_polls')) {
            $schema->create('sg_polls', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('discussion_id')->index();
                $table->string('question', 500);
                $table->boolean('is_multi_select')->default(false);
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();

                $table->foreign('discussion_id')
                      ->references('id')->on('social_group_discussions')
                      ->onDelete('cascade');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('sg_polls');
    },
];
