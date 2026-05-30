<?php

use Illuminate\Database\Schema\Builder;

// Copies every existing users.sg_primary_group_id into the companion table
// (social_group_user_primary) created by migration 000025, BEFORE migration
// 000027 drops the column. Runs as a single INSERT ... SELECT so it stays
// constant-memory regardless of how many users have a primary group set.
//
// Flarum injects the schema builder into migration closures (not a
// ConnectionInterface); the DB connection for the query builder comes from
// $schema->getConnection().
//
// Guarded three ways so it is safe on fresh installs and re-runs:
//   - skips if the legacy column was never present (new installs),
//   - only copies rows whose target group still exists (FK safety),
//   - skips users already in the companion table (idempotency).
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'sg_primary_group_id')
            || ! $schema->hasTable('social_group_user_primary')) {
            return;
        }

        $db = $schema->getConnection();

        $source = $db->table('users')
            ->whereNotNull('users.sg_primary_group_id')
            ->whereIn('users.sg_primary_group_id', function ($q) {
                $q->select('id')->from('social_groups');
            })
            ->whereNotExists(function ($q) use ($db) {
                $q->select($db->raw(1))
                    ->from('social_group_user_primary')
                    ->whereColumn('social_group_user_primary.user_id', 'users.id');
            })
            ->select('users.id', 'users.sg_primary_group_id');

        $db->table('social_group_user_primary')
            ->insertUsing(['user_id', 'group_id'], $source);
    },

    'down' => function () {
        // No rollback: the companion table is dropped by migration 000025's
        // down(), and copying the data back would require re-adding a column to
        // the core users table — the ALTER §45 exists to avoid.
    },
];
