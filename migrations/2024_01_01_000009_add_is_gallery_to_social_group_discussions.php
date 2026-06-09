<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Raw (not Migration::addColumns): the hasColumn guards make the add and the
 * drop idempotent against re-application — the helper provides no such guard.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('social_group_discussions', 'is_gallery')) {
            $schema->table('social_group_discussions', function (Blueprint $table) {
                $table->boolean('is_gallery')->default(false)->after('is_locked');
            });
        }
    },

    'down' => function (Builder $schema) {
        if ($schema->hasColumn('social_group_discussions', 'is_gallery')) {
            $schema->table('social_group_discussions', function (Blueprint $table) {
                $table->dropColumn('is_gallery');
            });
        }
    },
];
