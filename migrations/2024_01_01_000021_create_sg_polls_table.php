<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('sg_polls', function (Blueprint $table) {
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
