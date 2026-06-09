<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Raw (not a Migration helper): this migration does two things in one step —
 * adds a column to social_groups AND creates the join_requests table with an
 * FK — which the single-purpose helpers (addColumns / createTable) can't
 * express in a single return.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('social_groups', function (Blueprint $table) {
            $table->string('membership_type', 20)->default('open')->after('is_private');
            // 'open' = anyone can join, 'approval' = creator must approve
        });
        $schema->create('social_group_join_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->timestamps();
            $table->unique(['group_id', 'user_id']);
            $table->foreign('group_id')->references('id')->on('social_groups')->onDelete('cascade');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('social_groups', fn ($t) => $t->dropColumn('membership_type'));
        $schema->dropIfExists('social_group_join_requests');
    },
];
