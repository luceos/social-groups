<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('social_group_discussions', 'shared_from_discussion_id')) {
            $schema->table('social_group_discussions', function (Blueprint $table) {
                $table->unsignedBigInteger('shared_from_discussion_id')->nullable()->after('is_pinned');
                $table->foreign('shared_from_discussion_id')
                      ->references('id')
                      ->on('social_group_discussions')
                      ->onDelete('set null');
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('social_group_discussions', 'shared_from_discussion_id')) {
            $schema->table('social_group_discussions', function (Blueprint $table) {
                $table->dropForeign(['shared_from_discussion_id']);
                $table->dropColumn('shared_from_discussion_id');
            });
        }
    },
];
