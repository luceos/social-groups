<?php

namespace Ernestdefoe\SocialGroups\Schema;

use Illuminate\Database\Schema\Builder as SchemaBuilder;

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
     * Schema introspection is the one job `hasTable()`/`hasColumn()` exist
     * for and has no Eloquent-model equivalent. Inject the narrow
     * `Schema\Builder` (bound in SocialGroupsServiceProvider) rather than a
     * whole `ConnectionInterface`, keeping the connection abstraction out of
     * application code (CLAUDE.md §10 / §39.3). No query over user data runs
     * here; just DDL state.
     */
    public function __construct(SchemaBuilder $sb)
    {
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
