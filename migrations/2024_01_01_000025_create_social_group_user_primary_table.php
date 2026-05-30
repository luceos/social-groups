<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Companion table for a member's chosen "primary" social group (the badge shown
// on their profile). Replaces the sg_primary_group_id column that earlier
// migrations added directly to the core users table — see CLAUDE.md §45: no
// extension data on core tables, because ALTER TABLE on a large users table can
// hold a metadata lock long enough to cause visible downtime.
//
// 1:1 with users — the primary key IS user_id (incrementing disabled on the
// model). group_id carries a nullOnDelete FK so a deleted group clears the
// pointer instead of leaving a dangling id; user_id cascades so the row is
// removed with the account.
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('social_group_user_primary')) {
            return;
        }

        $schema->create('social_group_user_primary', function (Blueprint $table) {
            // user_id matches core users.id (INT UNSIGNED — Flarum uses
            // increments(), not bigIncrements). group_id matches
            // social_groups.id (BIGINT UNSIGNED). Mismatched integer widths
            // make MySQL reject the foreign key with errno 3780.
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedBigInteger('group_id')->nullable()->index();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->foreign('group_id')
                ->references('id')->on('social_groups')
                ->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('social_group_user_primary');
    },
];
