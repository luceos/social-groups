<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('social_group_members', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedBigInteger('group_id')->index();
    $table->unsignedBigInteger('user_id')->index();
    $table->string('role', 20)->default('member');
    $table->timestamp('joined_at')->useCurrent();

    $table->unique(['group_id', 'user_id']);

    $table->foreign('group_id')
        ->references('id')
        ->on('social_groups')
        ->onDelete('cascade');
});
