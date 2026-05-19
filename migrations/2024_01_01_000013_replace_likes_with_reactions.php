<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('social_group_post_reactions')) {
            $schema->create('social_group_post_reactions', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->string('reaction', 20)->default('like');

                $table->primary(['post_id', 'user_id']);
                $table->foreign('post_id')
                    ->references('id')
                    ->on('social_group_posts')
                    ->onDelete('cascade');
            });

            // Migrate existing likes → reactions. The previous version
            // used `INSERT ... ON DUPLICATE KEY UPDATE`, which is
            // MySQL/MariaDB-only and crashes on PostgreSQL + SQLite
            // (both supported by Flarum 2.x). Use Eloquent's portable
            // upsert() instead: it compiles to the driver's native
            // upsert (MySQL's ON DUPLICATE, PostgreSQL/SQLite's
            // ON CONFLICT) at runtime, with the same idempotency
            // guarantee against re-runs.
            if ($schema->hasTable('social_group_post_likes')) {
                $db = $schema->getConnection();
                $db->table('social_group_post_likes')
                    ->select(['post_id', 'user_id'])
                    ->orderBy('post_id')
                    ->orderBy('user_id')
                    ->chunk(500, function ($rows) use ($db) {
                        $payload = [];
                        foreach ($rows as $row) {
                            $payload[] = [
                                'post_id'  => $row->post_id,
                                'user_id'  => $row->user_id,
                                'reaction' => 'like',
                            ];
                        }
                        if (! empty($payload)) {
                            $db->table('social_group_post_reactions')->upsert(
                                $payload,
                                ['post_id', 'user_id'],
                                ['reaction']
                            );
                        }
                    });
            }
        }

        $schema->dropIfExists('social_group_post_likes');
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('social_group_post_reactions');
    },
];
