<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('social_group_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('role', 20)->default('member'); // 'creator' | 'admin' | 'member'
            $table->timestamp('joined_at')->useCurrent();

            $table->unique(['group_id', 'user_id']);

            $table->foreign('group_id')
                ->references('id')
                ->on('social_groups')
                ->onDelete('cascade');
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('social_group_members');
    },
];
