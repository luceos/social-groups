<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('sg_poll_options')) {
            $schema->create('sg_poll_options', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('poll_id')->index();
                $table->string('text', 255);
                $table->unsignedTinyInteger('sort_order')->default(0);

                $table->foreign('poll_id')
                      ->references('id')->on('sg_polls')
                      ->onDelete('cascade');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('sg_poll_options');
    },
];
