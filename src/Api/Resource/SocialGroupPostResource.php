<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Api\Context;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * Minimal API resource for SocialGroupPost.
 *
 * Purpose: SocialGroupNewPostBlueprint / SocialGroupNewReplyBlueprint declare
 * SocialGroupPost as their notification subject (via getSubjectModel()).
 * Flarum's NotificationResource builds a polymorphic `subject` relationship
 * whose collection list comes from `typesForModels()` — which returns null
 * for any subject model that has no registered API resource. A single null
 * in that list propagates into the include-validation path and throws a
 * TypeError on every `?include=subject` query against /api/notifications
 * (see vendor/flarum/json-api-server/src/Endpoint/Concerns/IncludesData.php:84).
 *
 * Without this resource the whole notifications endpoint 500s as soon as a
 * SocialGroupPost-subjected notification exists. Registering even a minimal
 * resource is enough — typeForModel() resolves to 'social-group-posts' and
 * the polymorphic collection becomes well-formed.
 *
 * No endpoints are exposed — group posts are listed via their own custom
 * controllers (ListGroupPostsController etc.) which have richer per-actor
 * gating than the JSON:API stock pipeline can express. This resource is
 * deliberately "include-only" — it surfaces the type to the framework so
 * polymorphic relations work, nothing else.
 */
class SocialGroupPostResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'social-group-posts';
    }

    public function model(): string
    {
        return SocialGroupPost::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = RequestUtil::getActor($context->request);

        if ($actor->isAdmin()
            || $actor->hasPermission('ernestdefoe-social-groups.moderate')
        ) {
            return;
        }

        /**
         * Restrict reads to posts in groups the actor can see — public
         * groups, groups they created, or groups they're a member of.
         * Mirrors the visibility predicate on SocialGroupResource so a
         * leaked post id can't expose private-group content via
         * include=subject on a notification.
         */
        $actorId = $actor->exists ? (int) $actor->id : null;

        $query->whereExists(function ($sub) use ($actorId) {
            $sub->from('social_groups')
                ->whereColumn('social_groups.id', 'social_group_posts.group_id')
                ->where(function ($q) use ($actorId) {
                    $q->where('social_groups.is_private', false);
                    if ($actorId !== null) {
                        $q->orWhere('social_groups.user_id', $actorId)
                          ->orWhereExists(function ($mem) use ($actorId) {
                              $mem->from('social_group_members')
                                  ->whereColumn('social_group_members.group_id', 'social_groups.id')
                                  ->where('social_group_members.user_id', $actorId);
                          });
                    }
                });
        });
    }

    public function endpoints(): array
    {
        // No CRUD endpoints — group posts are served by dedicated
        // controllers (ListGroupPostsController, CreateGroupPostController,
        // etc.). This resource exists only to register the type name with
        // the JSON:API server so polymorphic relations resolve.
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('discussionId')
                ->property('discussion_id'),
            Schema\Integer::make('groupId')
                ->property('group_id'),
            Schema\DateTime::make('createdAt')
                ->property('created_at'),
        ];
    }
}
