<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('social_group_posts', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedBigInteger('discussion_id')->index();
    $table->unsignedBigInteger('group_id')->index();
    $table->unsignedBigInteger('user_id')->index();
    $table->text('content');
    $table->timestamps();

    $table->foreign('discussion_id')
          ->references('id')->on('social_group_discussions')
          ->onDelete('cascade');
});
