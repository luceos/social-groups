<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupVisibility;
use Ernestdefoe\SocialGroups\Api\Concern\SanitizesLinkPreview;
use Ernestdefoe\SocialGroups\Event\SocialGroupPostWasCreated;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostReaction;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewPostBlueprint;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewReplyBlueprint;
use Ernestdefoe\SocialGroups\Schema\SchemaCapabilities;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * JSON:API resource for SocialGroupPost.
 *
 * Originally this resource existed only to satisfy NotificationResource
 * (subject polymorphism — without it, `?include=subject` on
 * /api/notifications crashed with a TypeError; see
 * vendor/flarum/json-api-server/src/Endpoint/Concerns/IncludesData.php:84).
 *
 * It is now also the backing store for SocialGroupDiscussionResource's
 * `firstPost` include and for thread-post listings
 * (filter[discussion]=N). CRUD endpoints (create/update/delete/pin/
 * react) live in classic controllers — they are specific actions that
 * don't fit JSON:API's pure CRUD contract.
 *
 * `reactions` and `actorReaction` are computed from the `reactions()`
 * relation, which must be pre-loaded via `with()` in the listing
 * resource's scope() — otherwise each post fires an extra query (N+1)
 * to resolve it. The `?include=firstPost` include already triggers
 * this through the `eagerLoad` configured in
 * SocialGroupDiscussionResource.
 */
class SocialGroupPostResource extends AbstractDatabaseResource
{
    use SanitizesLinkPreview;

    /**
     * Per-request memo for the group-moderator gate. canDelete and
     * canPin both call isInGroupModerator() for every Post in an Index
     * listing; a 50-post thread on a regular member used to fire 100
     * identical correlated subqueries against social_groups +
     * social_group_members. Every post in a thread shares the same
     * `group_id`, so caching by (actor_id, group_id) collapses the
     * whole page to a single check.
     *
     * Reset on each scope() invocation as a belt-and-braces guard
     * against the Resource being container-cached (see CLAUDE.md §44.2).
     *
     * @var array<string, bool>
     */
    protected array $moderatorCheckCache = [];

    public function __construct(
        protected Formatter $formatter,
        protected SchemaCapabilities $capabilities,
        protected NotificationSyncer $notifications,
        protected Dispatcher $events,
        protected LoggerInterface $log,
    ) {
    }

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
        $this->moderatorCheckCache = [];

        $actor = RequestUtil::getActor($context->request);

        // scope() runs for both Index and Show/include paths. Three
        // distinct branches:
        //   1. Index + discussionId=N → discussion-specific listing.
        //   2. Index without discussionId → refuse (we don't want to
        //      leak posts globally; the frontend always passes the
        //      param).
        //   3. Show / include → PK lookup; applies only the
        //      group-visibility filter, without requiring the param.
        $isIndex = $context->endpoint instanceof Endpoint\Index;
        $params  = $context->request->getQueryParams();
        $discId  = isset($params['discussionId']) ? (int) $params['discussionId'] : 0;

        if ($isIndex) {
            if ($discId <= 0) {
                // Index without a discussion filter: never returns
                // anything. Prevents `?include=posts` on another
                // endpoint from turning into a global listing of
                // visible posts (it would leak only via group
                // visibility, but the cost would be high and the
                // behaviour unexpected).
                $query->whereRaw('1 = 0');
                return;
            }

            $discussion = SocialGroupDiscussion::with('group')->find($discId);
            if ($discussion === null || $discussion->group === null) {
                $query->whereRaw('1 = 0');
                return;
            }
            if (! GroupVisibility::canSee($actor, $discussion->group)) {
                throw new PermissionDeniedException();
            }
            $query->where('social_group_posts.discussion_id', $discId);

            // Pinned at the top, then chronological — mirrors the legacy
            // controller and matches the compound index
            // (discussion_id, is_pinned).
            if ($this->capabilities->isPinned) {
                $query->orderByDesc('social_group_posts.is_pinned');
            }
            $query->orderBy('social_group_posts.created_at');

            $query->with('user');
            if ($this->capabilities->reactions) {
                $query->with('reactions');
            }
            return;
        }

        // Show / include path: PK lookup or polymorphic hydration
        // (e.g. notification pointing at a post as its subject). Admins
        // / moderators see any post; everyone else is restricted by
        // group visibility via a batch subquery.
        if ($actor->isAdmin()
            || $actor->hasPermission('ernestdefoe-social-groups.moderate')
        ) {
            return;
        }

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
        return [
            Endpoint\Index::make()
                ->paginate()
                ->defaultInclude(['user', 'discussion']),

            Endpoint\Create::make()
                ->authenticated(),

            Endpoint\Update::make()
                ->authenticated()
                ->can('edit'),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),

            // ── Action endpoints ─────────────────────────────────────────
            Endpoint\Endpoint::make('social-group-posts.pin')
                ->route('PATCH', '/{id}/pin')
                ->authenticated()
                ->can('pin')
                ->action(fn (Context $context) => $this->doPin($context)),

            Endpoint\Endpoint::make('social-group-posts.react')
                ->route('POST', '/{id}/react')
                ->authenticated()
                ->can('react')
                ->action(fn (Context $context) => $this->doReact($context, false)),

            Endpoint\Endpoint::make('social-group-posts.unreact')
                ->route('POST', '/{id}/unreact')
                ->authenticated()
                ->can('react')
                ->action(fn (Context $context) => $this->doReact($context, true)),
        ];
    }

    /**
     * Pin/unpin. Authorisation already ran via ->can('pin').
     */
    protected function doPin(Context $context): SocialGroupPost
    {
        if (! $this->capabilities->isPinned) {
            throw new BadRequestException('Pinning not available on this install.');
        }
        /** @var SocialGroupPost $p */
        $p = $context->model;
        $p->is_pinned = ! $p->is_pinned;
        $p->save();
        return $p;
    }

    /**
     * React / unreact. `$clear=true` removes the actor's reaction;
     * otherwise reads `reaction` from the body and upserts. Allowed
     * reaction list is enforced in the same Schema set the JS picker
     * uses.
     */
    protected function doReact(Context $context, bool $clear): SocialGroupPost
    {
        /** @var SocialGroupPost $p */
        $p = $context->model;
        $actor = $context->getActor();

        if ($clear) {
            SocialGroupPostReaction::where('post_id', $p->id)
                ->where('user_id', $actor->id)
                ->delete();
        } else {
            $body     = (array) ($context->request->getParsedBody() ?? []);
            $reaction = trim((string) ($body['reaction'] ?? 'like'));
            if (! in_array($reaction, self::REACTIONS, true)) {
                throw new BadRequestException('Invalid reaction type.');
            }
            SocialGroupPostReaction::updateOrInsert(
                ['post_id' => $p->id, 'user_id' => $actor->id],
                ['reaction' => $reaction]
            );
        }

        // Re-load reactions so the response's Schema getters see the
        // new state; without this the serializer reads the cached
        // collection from before the mutation.
        $p->load('reactions');
        return $p;
    }

    public const REACTIONS = ['like', 'heart', 'haha', 'wow', 'sad', 'angry'];

    public function creating(object $model, BaseContext $context): ?object
    {
        /** @var Context $context */
        $actor = $context->getActor();

        $body  = (array) ($context->request->getParsedBody() ?? []);
        $attrs = (array) ($body['data']['attributes'] ?? []);

        $discussionId = (int) ($attrs['discussionId'] ?? 0);
        $content      = trim((string) ($attrs['content'] ?? ''));
        $parentPostId = isset($attrs['parentPostId']) ? (int) $attrs['parentPostId'] : null;

        if ($discussionId <= 0 || $content === '') {
            throw new BadRequestException('discussionId and content are required.');
        }
        if (mb_strlen($content) > 20000) {
            throw new BadRequestException('Post content may not exceed 20 000 characters.');
        }

        $discussion = SocialGroupDiscussion::with('group')->find($discussionId);
        if ($discussion === null || $discussion->group === null) {
            throw new BadRequestException('Discussion not found.');
        }
        if ($discussion->is_locked) {
            throw new PermissionDeniedException();
        }

        // Active members can reply; global admins and the extension's global
        // moderators may also reply in any group without joining it first, to
        // match the React/Delete/Pin policies (which grant the same bypass).
        // Without this, an admin who isn't a member could react to a post but
        // got a 403 trying to reply to it.
        $group = $discussion->group;
        $privileged = $actor->isAdmin()
            || $actor->hasPermission('ernestdefoe-social-groups.moderate');
        if (! $privileged && ! $group->activeMembership($actor->id)->exists()) {
            throw new PermissionDeniedException();
        }

        // Flatten one level: replying to a reply attaches to its parent.
        if ($parentPostId !== null && $parentPostId > 0) {
            $parent = SocialGroupPost::where('id', $parentPostId)
                ->where('discussion_id', $discussion->id)
                ->first();
            if ($parent === null) {
                throw new BadRequestException('Parent post not found.');
            }
            $parentPostId = $parent->parent_post_id ?? $parent->id;
        } else {
            $parentPostId = null;
        }

        $linkPreview = is_array($attrs['linkPreview'] ?? null)
            ? $this->sanitizeLinkPreview($attrs['linkPreview'])
            : null;

        $model->discussion_id  = $discussion->id;
        $model->group_id       = $discussion->group_id;
        $model->user_id        = $actor->id;
        $model->content        = $content;
        $model->content_parsed = $this->formatter->parse($content);
        $model->parent_post_id = $parentPostId;
        $model->link_preview   = $linkPreview;

        $model->_sgDiscussionRef = $discussion;

        return null;
    }

    public function created(object $model, BaseContext $context): ?object
    {
        /** @var SocialGroupPost $model */
        $actor      = $context->getActor();
        $discussion = $model->_sgDiscussionRef ?? SocialGroupDiscussion::find($model->discussion_id);
        if ($discussion === null) {
            return null;
        }

        $discussion->increment('comment_count');
        $discussion->last_posted_at      = \Carbon\Carbon::now();
        $discussion->last_posted_user_id = $actor->id;
        $discussion->save();

        try {
            $this->events->dispatch(new SocialGroupPostWasCreated($model, $actor, $discussion));
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] Realtime event dispatch failed: ' . $e->getMessage());
        }

        try {
            if ($model->parent_post_id === null) {
                $recipients = $this->discussionParticipants($discussion, (int) $actor->id);
                if ($recipients) {
                    $this->notifications->sync(
                        new SocialGroupNewPostBlueprint($model, $actor, $discussion),
                        $recipients
                    );
                }
            } else {
                $parentPost = SocialGroupPost::find($model->parent_post_id);
                if ($parentPost !== null
                    && $parentPost->user_id
                    && (int) $parentPost->user_id !== (int) $actor->id
                ) {
                    $recipient = User::find($parentPost->user_id);
                    if ($recipient !== null) {
                        $this->notifications->sync(
                            new SocialGroupNewReplyBlueprint($model, $actor, $parentPost, $discussion),
                            [$recipient]
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] Notification failed: ' . $e->getMessage(), ['exception' => $e]);
        }

        return null;
    }

    public function updating(object $model, BaseContext $context): ?object
    {
        /** @var SocialGroupPost $model */
        $body  = (array) ($context->request->getParsedBody() ?? []);
        $attrs = (array) ($body['data']['attributes'] ?? []);

        if (! array_key_exists('content', $attrs)) {
            return null;
        }
        $content = trim((string) $attrs['content']);
        if ($content === '') {
            throw new BadRequestException('Content cannot be empty.');
        }
        if (mb_strlen($content) > 20000) {
            throw new BadRequestException('Post content may not exceed 20 000 characters.');
        }

        $model->content        = $content;
        $model->content_parsed = $this->formatter->parse($content);

        return null;
    }

    public function deleting(object $model, BaseContext $context): void
    {
        /** @var SocialGroupPost $model */
        $model->_sgDeletedDiscussionId = $model->discussion_id;
    }

    public function deleted(object $model, BaseContext $context): void
    {
        /** @var SocialGroupPost $model */
        $discussionId = $model->_sgDeletedDiscussionId ?? $model->discussion_id;
        if ($discussionId) {
            SocialGroupDiscussion::where('id', $discussionId)
                ->where('comment_count', '>', 0)
                ->decrement('comment_count');
        }
    }

    /**
     * Discussion participants minus the actor — same shape the
     * legacy CreateGroupPostController used for top-level notifications.
     *
     * The pluck is capped at 500 distinct user_ids by reading only the
     * most-recent posts: an active thread with thousands of posts from
     * dozens of users does not need every author since the start of
     * time loaded into memory just to derive a notification recipient
     * set. The discussion starter is unconditionally appended so they
     * are always notified even when they posted earlier than the
     * 500-row window.
     */
    protected function discussionParticipants(SocialGroupDiscussion $discussion, int $actorId): array
    {
        $ids = SocialGroupPost::where('discussion_id', $discussion->id)
            ->where('user_id', '!=', $actorId)
            ->orderByDesc('id')
            ->take(500)
            ->pluck('user_id')
            ->push($discussion->user_id)
            ->unique()
            ->filter(fn ($id) => $id && (int) $id !== $actorId)
            ->values()
            ->all();

        return $ids ? User::whereIn('id', $ids)->get()->all() : [];
    }

    public function fields(): array
    {
        return [
            // The reply input fields are consumed by creating()/updating()
            // from the raw request body (they need cross-field logic: the
            // membership gate, parent-post flattening, link-preview
            // sanitization). They still MUST be declared writable — the
            // JSON:API layer rejects any request whose body contains a
            // declared-but-unwritable field with a 403 ("Field [x] is not
            // writable"), which broke every reply and edit. The no-op
            // set() keeps the lifecycle hooks as the single writers.
            Schema\Integer::make('discussionId')
                ->property('discussion_id')
                ->writableOnCreate()
                ->set(fn () => null),

            Schema\Integer::make('groupId')
                ->property('group_id'),

            Schema\Str::make('content')
                ->writable()
                ->set(fn () => null),

            Schema\Str::make('contentParsed')
                ->get(function (SocialGroupPost $post) {
                    return $this->renderContent($post);
                }),

            Schema\Arr::make('linkPreview')
                ->writableOnCreate()
                ->set(fn () => null)
                ->visible(fn () => $this->capabilities->linkPreview)
                ->get(function (SocialGroupPost $post) {
                    if (! $this->capabilities->linkPreview) {
                        return null;
                    }
                    return $post->link_preview;
                }),

            Schema\Arr::make('reactions')
                ->visible(fn () => $this->capabilities->reactions)
                ->get(function (SocialGroupPost $post) {
                    if (! $this->capabilities->reactions) {
                        return (object) [];
                    }
                    return $this->aggregateReactions($post);
                }),

            Schema\Str::make('actorReaction')
                ->nullable()
                ->visible(fn () => $this->capabilities->reactions)
                ->get(function (SocialGroupPost $post, Context $context) {
                    if (! $this->capabilities->reactions) {
                        return null;
                    }
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return null;
                    }
                    foreach ($post->reactions as $r) {
                        if ((int) $r->user_id === (int) $actor->id) {
                            return $r->reaction;
                        }
                    }
                    return null;
                }),

            Schema\Boolean::make('isPinned')
                ->get(fn (SocialGroupPost $post) => (bool) $post->is_pinned),

            Schema\Integer::make('parentPostId')
                ->property('parent_post_id')
                ->nullable()
                ->writableOnCreate()
                ->set(fn () => null),

            Schema\Boolean::make('canEdit')
                ->get(function (SocialGroupPost $post, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    // Mirror SocialGroupPostPolicy::edit — global admins may
                    // edit any post. Without this the feed/thread UI hid the
                    // Edit action from admins even though the PATCH would pass.
                    return $actor->isAdmin() || (int) $actor->id === (int) $post->user_id;
                }),

            Schema\Boolean::make('canDelete')
                ->get(function (SocialGroupPost $post, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    if ((int) $actor->id === (int) $post->user_id) {
                        return true;
                    }
                    if ($actor->isAdmin()
                        || $actor->hasPermission('ernestdefoe-social-groups.moderate')
                    ) {
                        return true;
                    }
                    return $this->isInGroupModerator($actor, $post->group_id);
                }),

            Schema\Boolean::make('canPin')
                ->get(function (SocialGroupPost $post, Context $context) {
                    if (! $this->capabilities->isPinned) {
                        return false;
                    }
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    if ($actor->isAdmin()
                        || $actor->hasPermission('ernestdefoe-social-groups.moderate')
                    ) {
                        return true;
                    }
                    return $this->isInGroupModerator($actor, $post->group_id);
                }),

            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\DateTime::make('updatedAt')
                ->property('updated_at'),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('discussion')
                ->type('social-group-discussions')
                ->includable(),
        ];
    }

    /**
     * "Is the actor a group creator/moderator?" — used by both the
     * canDelete and canPin Schema gates. Memoized on
     * $this->moderatorCheckCache so a paginated thread of N posts in
     * the same group runs the underlying correlated subquery exactly
     * once (was N × 2 before the cache).
     */
    protected function isInGroupModerator($actor, int $groupId): bool
    {
        if (! $actor->exists || $groupId <= 0) {
            return false;
        }

        $key = ((int) $actor->id) . ':' . $groupId;
        if (isset($this->moderatorCheckCache[$key])) {
            return $this->moderatorCheckCache[$key];
        }

        $result = \Ernestdefoe\SocialGroups\Model\SocialGroup::query()
            ->where('id', $groupId)
            ->whereExists(function ($sub) use ($actor) {
                $sub->from('social_group_members')
                    ->whereColumn('social_group_members.group_id', 'social_groups.id')
                    ->where('user_id', $actor->id)
                    ->whereIn('role', ['creator', 'moderator']);
            })
            ->exists();

        return $this->moderatorCheckCache[$key] = $result;
    }

    /**
     * Renders the content via the formatter. On failure (inconsistent
     * parsed value, malformed source after migration), falls back to
     * escape + nl2br like the legacy controller — never blow up the
     * entire feed render because of ONE problematic post.
     */
    protected function renderContent(SocialGroupPost $post): string
    {
        if ($post->content_parsed !== null) {
            try {
                return $this->formatter->render($post->content_parsed);
            } catch (\Throwable) {
                // fall through to escape fallback
            }
        }
        return nl2br(htmlspecialchars($post->content ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Groups the pre-loaded reactions (via with('reactions') in the
     * listing scope) into `{reaction: count}`. Cast to object so that
     * the JSON:API payload serialises as `{}` instead of `[]` when
     * there are no reactions — the frontend expects an object.
     */
    protected function aggregateReactions(SocialGroupPost $post): object
    {
        $counts = [];
        foreach ($post->reactions as $r) {
            $key = $r->reaction;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return (object) $counts;
    }
}
