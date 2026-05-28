<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('social_group_discussions', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedBigInteger('group_id')->index();
    $table->unsignedBigInteger('user_id')->index();
    $table->string('title', 255);
    $table->unsignedInteger('comment_count')->default(0);
    $table->unsignedBigInteger('last_posted_user_id')->nullable();
    $table->timestamp('last_posted_at')->nullable();
    $table->boolean('is_locked')->default(false);
    $table->timestamps();

    $table->foreign('group_id')
          ->references('id')->on('social_groups')
          ->onDelete('cascade');
});
