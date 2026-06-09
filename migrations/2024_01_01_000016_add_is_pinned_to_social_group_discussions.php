<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Raw (not Migration::addColumns): hasColumn-guarded both ways so re-running
 * the migration is a no-op — the helper has no such guard.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('social_group_discussions', 'is_pinned')) {
            $schema->table('social_group_discussions', function (Blueprint $table) {
                $table->boolean('is_pinned')->default(false)->after('is_locked');
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('social_group_discussions', 'is_pinned')) {
            $schema->table('social_group_discussions', function (Blueprint $table) {
                $table->dropColumn('is_pinned');
            });
        }
    },
];
