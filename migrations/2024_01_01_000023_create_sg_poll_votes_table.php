<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('sg_poll_votes', function (Blueprint $table) {
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
