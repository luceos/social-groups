<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('social_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#4A90E2');
            $table->string('image_url', 500)->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->boolean('is_private')->default(false);
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamps();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('social_groups');
    },
];
