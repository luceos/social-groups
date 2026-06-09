<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Raw (not Migration::addColumns): adds an FK to the core users table and is
 * hasColumn-guarded both ways — the helper covers neither the FK nor the
 * idempotency guard. (Superseded by the companion table in 000025; retained
 * for install-history continuity.)
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'sg_primary_group_id')) {
            $schema->table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('sg_primary_group_id')->nullable()->after('id');
                $table->foreign('sg_primary_group_id')
                      ->references('id')->on('social_groups')
                      ->nullOnDelete();
            });
        }
    },

    'down' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'sg_primary_group_id')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropForeign(['sg_primary_group_id']);
                $table->dropColumn('sg_primary_group_id');
            });
        }
    },
];
