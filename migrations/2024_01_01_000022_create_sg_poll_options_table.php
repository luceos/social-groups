<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('sg_poll_options', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedBigInteger('poll_id')->index();
    $table->string('text', 255);
    $table->unsignedTinyInteger('sort_order')->default(0);

    $table->foreign('poll_id')
          ->references('id')->on('sg_polls')
          ->onDelete('cascade');
});
