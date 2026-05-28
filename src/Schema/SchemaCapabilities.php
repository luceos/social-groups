<?php

namespace Ernestdefoe\SocialGroups\Schema;

use Illuminate\Database\ConnectionInterface;

/**
 * Snapshot of schema-capability flags. Probes the database exactly once
 * at container boot and exposes the results as readonly properties for
 * every controller and service.
 *
 * Replaced the `static $schema = null` cache inside `handle()`: statics
 * in queue / Octane / RoadRunner workers persist for the entire
 * process lifetime. If a new migration runs while workers are alive,
 * the old static would keep responding as if the column didn't exist,
 * indefinitely, until the next restart. Container singletons have
 * exactly the same process scope, but the dependency is now explicit
 * in the constructor and the contract is documented in one place —
 * production operators are told to run `cache:clear` + restart workers
 * after migrate (standard Flarum practice). See CLAUDE.md §44.
 */
class SchemaCapabilities
{
    public readonly bool $isGallery;
    public readonly bool $isPinned;
    public readonly bool $sharedFrom;
    public readonly bool $polls;
    public readonly bool $reactions;
    public readonly bool $linkPreview;

    /**
     * One of the rare legitimate exceptions to "prefer Eloquent over
     * `ConnectionInterface`" (CLAUDE.md §10 / §39.3). `getSchemaBuilder()`
     * + `hasTable()` / `hasColumn()` have no Eloquent-model equivalent
     * — schema introspection is precisely what `ConnectionInterface`
     * exists to expose. No query over user data runs here; just
     * DDL state.
     */
    public function __construct(ConnectionInterface $db)
    {
        $sb = $db->getSchemaBuilder();

        $this->isGallery   = $sb->hasColumn('social_group_discussions', 'is_gallery');
        $this->isPinned    = $sb->hasColumn('social_group_discussions', 'is_pinned');
        $this->sharedFrom  = $sb->hasColumn('social_group_discussions', 'shared_from_discussion_id');
        $this->polls       = $sb->hasTable('sg_polls')
                          && $sb->hasTable('sg_poll_options')
                          && $sb->hasTable('sg_poll_votes');
        $this->reactions   = $sb->hasTable('social_group_post_reactions');
        $this->linkPreview = $sb->hasColumn('social_group_posts', 'link_preview');
    }

}
