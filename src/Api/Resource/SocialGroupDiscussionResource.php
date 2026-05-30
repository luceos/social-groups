<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupVisibility;
use Ernestdefoe\SocialGroups\Api\Concern\HandlesDiscussionPoll;
use Ernestdefoe\SocialGroups\Api\Concern\SanitizesLinkPreview;
use Ernestdefoe\SocialGroups\Model\SgPoll;
use Ernestdefoe\SocialGroups\Model\SgPollOption;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Schema\SchemaCapabilities;
use Ernestdefoe\SocialGroups\Service\Discussion\ShareDiscussionService;
use Ernestdefoe\SocialGroups\Support\PendingDiscussionPayload;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * JSON:API resource for social-group discussions. Replaced the legacy
 * `ListGroupDiscussionsController` (removed in Phase 2b of audit #4);
 * the JS calls it via `listDiscussions()` in utils/api.js, which
 * projects the JSON:API response into the legacy shape so the rest of
 * the feed pipeline doesn't need to change.
 *
 * Index requires `?filter[group]=<id>` (without it responds empty). The
 * group privacy check runs in scope(); the policy gate on `view` covers
 * individual Show fetches. Search via `?filter[q]` performs an escaped
 * LIKE against title and first-post content. is_pinned and sorting by
 * is_pinned only kick in when `SchemaCapabilities` reports the column
 * exists — installs that pre-date the migration creating it keep
 * working without a crash.
 */
class SocialGroupDiscussionResource extends AbstractDatabaseResource
{
    use SanitizesLinkPreview;
    use HandlesDiscussionPoll;

    /**
     * Per-request memo keyed by "actorId:groupId" → bool. The canDelete
     * Schema field used to call `$group->members()->where(...)->exists()`
     * on every row of an Index page; for 20 discussions all in the same
     * group that meant 20 identical queries. Cache the answer once per
     * (actor, group) pair so a paginated listing of N discussions in M
     * groups runs at most M queries instead of N.
     *
     * The cache is per Resource instance — tobyz/jsonapi-server resolves
     * the Resource fresh per JSON:API request, so the lifetime never
     * leaks across actors. Reset on each scope() invocation as a belt-
     * and-braces guard in case a future container binding turns this
     * into a singleton (see CLAUDE.md §44.2).
     *
     * @var array<string, bool>
     */
    protected array $moderatorCheckCache = [];

    public function __construct(
        protected SchemaCapabilities $capabilities,
        protected Formatter $formatter,
        protected ShareDiscussionService $shareService,
    ) {
    }

    public function type(): string
    {
        return 'social-group-discussions';
    }

    public function model(): string
    {
        return SocialGroupDiscussion::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $this->moderatorCheckCache = [];

        $actor = RequestUtil::getActor($context->request);
        $params = $context->request->getQueryParams();

        // scope() runs for the primary Index/Show AND for include-driven
        // hydration from sibling resources (e.g. posts?include=discussion).
        // Use Context::listing(self::class) — NOT `endpoint instanceof Index`
        // — because during an include the endpoint is still the PARENT's
        // Index (so the naive check is wrongly true), but $context->collection
        // stays the parent resource. listing(self::class) is therefore true
        // only when discussions are the primary collection being listed.
        // Without this, an included discussion hit the groupId-required guard
        // below, got killed by whereRaw('1=0'), and serialized as null — so a
        // cold-store thread load fell back to the Show endpoint and failed.
        $isIndex = $context->listing(self::class);
        if (! $isIndex) {
            /*
             * Eager loads still help here for the include=firstPost.user
             * case — applied below after the group-scope branch.
             */
            $this->applyEagerLoads($query, $actor);
            return;
        }

        // Use plain query params instead of JSON:API `?filter[group]`
        // because Flarum 2's AbstractDatabaseResource::filters() is
        // final and throws — JSON:API filter syntax requires registering
        // a Search\Filter class per param, which is heavier than the
        // single-group scoping we actually need.
        $groupId = isset($params['groupId']) ? (int) $params['groupId'] : 0;

        if ($groupId <= 0) {
            // Without a group filter, refuse to return a listing. Preserves
            // the legacy /sg-discussions/{groupId} contract: discussions
            // are always listed within the scope of ONE group.
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('social_group_discussions.group_id', $groupId);

        $group = SocialGroup::find($groupId);
        if ($group === null) {
            $query->whereRaw('1 = 0');
            return;
        }

        if (! GroupVisibility::canSee($actor, $group)) {
            throw new PermissionDeniedException();
        }

        if ($this->capabilities->isGallery) {
            $query->where(function ($q) {
                $q->whereNull('social_group_discussions.is_gallery')
                  ->orWhere('social_group_discussions.is_gallery', false);
            });
        }

        $q = isset($params['q']) ? trim((string) $params['q']) : '';
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('social_group_discussions.title', 'like', $like)
                  ->orWhereExists(function ($sub) use ($like) {
                      $sub->from('social_group_posts')
                          ->whereColumn('social_group_posts.discussion_id', 'social_group_discussions.id')
                          ->where('social_group_posts.content', 'like', $like);
                  });
            });
        }

        if ($this->capabilities->isPinned) {
            $query->orderByDesc('social_group_discussions.is_pinned');
        }
        $query->orderByDesc('social_group_discussions.last_posted_at');

        $this->applyEagerLoads($query, $actor);
    }

    /**
     * Eager loads to avoid N+1 when the computed fields (`sharedFrom`,
     * `poll`, `firstPost`) are rendered. Each `with()` here saves N
     * queries across the page (20 discussions → 1 query per relation
     * instead of 20).
     *
     * `firstPost.reactions` is what feeds the `reactions` field of
     * SocialGroupPostResource — without this pre-load, every post
     * included as firstPost fires 1 extra reactions query.
     *
     * Shared between scope()'s Index and Show branches because
     * include=firstPost.user via Show also benefits.
     */
    protected function applyEagerLoads(Builder $query, ?\Flarum\User\User $actor = null): void
    {
        $query->with([
            'user',
            'lastPostedUser',
            'firstPost.user',
            /*
             * The canDelete Schema field reaches into `$d->group` to
             * resolve per-actor moderator status. Without this eager
             * load, every row of an Index page fires a SELECT against
             * `social_groups` to hydrate the relation — one query per
             * discussion regardless of how many distinct groups are
             * involved.
             */
            'group',
        ]);

        if ($this->capabilities->reactions) {
            $query->with('firstPost.reactions');
        }

        if ($this->capabilities->sharedFrom) {
            $query->with([
                'sharedFromDiscussion.group',
                'sharedFromDiscussion.user',
                'sharedFromDiscussion.firstPost.user',
            ]);
        }

        if ($this->capabilities->polls) {
            /*
             * `withCount('votes')` on each option materialises a
             * `votes_count` attribute via a single GROUP BY query for
             * the entire page — replaces the per-row
             * `SgPollVote::whereIn(...)` that buildPoll() used to run
             * for every discussion with a poll. The actor's own votes
             * are eager-loaded into `poll.votes` constrained by
             * `user_id = $actor->id`, which is one more query for the
             * whole page (and skipped entirely for guests).
             *
             * Net: 20 discussions × 15 polls used to be 30 queries
             * (vote-count + actor-vote per poll). Now it's 2 — one
             * `withCount`, one constrained `with`.
             */
            $query->with([
                'poll.options' => fn ($q) => $q->withCount('votes'),
            ]);
            if ($actor !== null && $actor->exists) {
                $query->with([
                    'poll.votes' => fn ($q) => $q->where('user_id', $actor->id),
                ]);
            }
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate()
                ->defaultInclude(['firstPost', 'firstPost.user', 'user', 'lastPostedUser']),

            Endpoint\Show::make()
                ->can('view'),

            Endpoint\Create::make()
                ->authenticated()
                ->can('create'),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),

            // ── Action endpoints ─────────────────────────────────────────
            // pin/share don't fit pure JSON:API CRUD but are scoped to a
            // single discussion. Endpoint\Endpoint::make wires them into
            // the same /api/social-group-discussions/{id}/... prefix
            // with the standard auth pipeline. Replaces the legacy
            // PinGroupDiscussionController + ShareGroupDiscussionController.
            Endpoint\Endpoint::make('social-groups.pin')
                ->route('PATCH', '/{id}/pin')
                ->authenticated()
                ->can('pin')
                ->action(fn (Context $context) => $this->doPin($context)),

            Endpoint\Endpoint::make('social-groups.share')
                ->route('POST', '/{id}/share')
                ->authenticated()
                ->can('view')
                ->action(fn (Context $context) => $this->shareService->share($context)),
        ];
    }

    /**
     * Pin/unpin action — flips is_pinned and persists. The
     * authorisation already ran via ->can('pin') (SocialGroupDiscussionPolicy::pin).
     */
    protected function doPin(Context $context): SocialGroupDiscussion
    {
        /** @var SocialGroupDiscussion $d */
        $d = $context->model;
        if (! $this->capabilities->isPinned) {
            throw new BadRequestException('Pinning not available on this install.');
        }
        $d->is_pinned = ! $d->is_pinned;
        $d->save();
        return $d;
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('groupId')
                ->property('group_id')
                ->writableOnCreate()
                ->required(),

            Schema\Str::make('title')
                ->writableOnCreate()
                ->maxLength(255),

            Schema\Str::make('content')
                ->writableOnCreate()
                ->get(fn () => null)
                ->set(fn () => null),

            Schema\Arr::make('linkPreview')
                ->writableOnCreate()
                ->get(fn () => null)
                ->set(fn () => null),

            Schema\Integer::make('commentCount')
                ->property('comment_count'),

            Schema\Boolean::make('isLocked')
                ->property('is_locked'),

            Schema\Boolean::make('isPinned')
                ->visible(fn () => $this->capabilities->isPinned)
                ->get(fn ($d) => $this->capabilities->isPinned ? (bool) $d->is_pinned : false),

            Schema\DateTime::make('lastPostedAt')
                ->property('last_posted_at'),

            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\Boolean::make('canDelete')
                ->get(function ($d, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    if ((int) $actor->id === (int) $d->user_id) {
                        return true;
                    }
                    if ($actor->isAdmin()
                        || $actor->hasPermission('ernestdefoe-social-groups.moderate')
                    ) {
                        return true;
                    }
                    return $this->isGroupModerator(
                        (int) $actor->id,
                        (int) $d->group_id,
                        $d->group,
                    );
                }),

            Schema\Boolean::make('canShare')
                ->get(fn ($d, Context $context) => $context->getActor()->exists),

            Schema\Boolean::make('canPin')
                ->get(function ($d, Context $context) {
                    if (! $this->capabilities->isPinned) {
                        return false;
                    }
                    return $context->getActor()->can('pin', $d);
                }),

            Schema\Arr::make('sharedFrom')
                ->visible(fn () => $this->capabilities->sharedFrom)
                ->get(function (SocialGroupDiscussion $d) {
                    if (! $this->capabilities->sharedFrom) {
                        return null;
                    }
                    return $this->buildSharedFrom($d);
                }),

            Schema\Arr::make('poll')
                ->visible(fn () => $this->capabilities->polls)
                ->writableOnCreate(fn () => $this->capabilities->polls)
                ->set(fn () => null)
                ->get(function (SocialGroupDiscussion $d, Context $context) {
                    if (! $this->capabilities->polls) {
                        return null;
                    }
                    return $this->buildPoll($d, $context);
                }),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('lastPostedUser')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('firstPost')
                ->type('social-group-posts')
                ->includable(),
        ];
    }

    /**
     * Builds the denormalised "shared from" structure in the shape the
     * frontend expects (SGFeed-sharedCard). Reads from the relations
     * eager-loaded in scope() — `sharedFromDiscussion`,
     * `sharedFromDiscussion.group`, `sharedFromDiscussion.firstPost.user`.
     * If `shared_from_discussion_id` hasn't been migrated yet, the field
     * is already hidden via `visible()` — this function doesn't run.
     */
    /**
     * Memoized check — is the actor a group moderator/creator for the
     * given group? Cached on $this->moderatorCheckCache (reset per
     * request in scope()). Group membership state doesn't change within
     * a single API request, so one query per (actor, group) pair is the
     * minimum work this can do.
     *
     * Takes `?SocialGroup $group` so callers that have the eager-loaded
     * relation in hand can skip a re-fetch. If $group is null AND the
     * cache miss path runs, we fall back to a direct membership query
     * against the pivot table — keeps the helper correct in include
     * paths where group wasn't loaded.
     */
    protected function isGroupModerator(int $actorId, int $groupId, ?SocialGroup $group): bool
    {
        if ($actorId <= 0 || $groupId <= 0) {
            return false;
        }
        $key = $actorId . ':' . $groupId;
        if (isset($this->moderatorCheckCache[$key])) {
            return $this->moderatorCheckCache[$key];
        }

        if ($group !== null) {
            $result = $group->members()
                ->where('user_id', $actorId)
                ->whereIn('role', ['creator', 'moderator'])
                ->exists();
        } else {
            $result = SocialGroup::query()
                ->where('id', $groupId)
                ->whereHas('members', fn ($q) =>
                    $q->where('user_id', $actorId)
                      ->whereIn('role', ['creator', 'moderator'])
                )
                ->exists();
        }

        return $this->moderatorCheckCache[$key] = $result;
    }

    protected function buildSharedFrom(SocialGroupDiscussion $d): ?array
    {
        $orig = $d->sharedFromDiscussion;
        if ($orig === null) {
            return null;
        }
        $fp = $orig->firstPost;
        return [
            'discussionId' => (int) $orig->id,
            'title'        => $orig->title,
            'groupId'      => (int) $orig->group_id,
            'groupName'    => $orig->group?->name,
            'groupSlug'    => $orig->group?->slug,
            'snippet'      => $fp ? mb_substr(strip_tags($fp->content ?? ''), 0, 200) : '',
            'user'         => $orig->user ? [
                'displayName' => $orig->user->display_name,
                'avatarUrl'   => $orig->user->avatar_url,
            ] : null,
        ];
    }

    public function creating(object $model, BaseContext $context): ?object
    {
        /** @var Context $context */
        $actor = $context->getActor();

        $groupId = (int) ($model->group_id ?? 0);
        if ($groupId <= 0) {
            throw new BadRequestException('groupId is required');
        }

        $group = SocialGroup::find($groupId);
        if ($group === null) {
            throw new BadRequestException('Group not found');
        }

        $isMember = $group->activeMembership($actor->id)->exists();
        $isOwner  = (int) $actor->id === (int) $group->user_id;
        $isMod    = $actor->isAdmin()
                  || $actor->hasPermission('ernestdefoe-social-groups.moderate');

        if (! ($isMember || $isOwner || $isMod)) {
            throw new PermissionDeniedException();
        }

        $body  = (array) ($context->request->getParsedBody() ?? []);
        $attrs = (array) ($body['data']['attributes'] ?? []);

        $content     = trim((string) ($attrs['content'] ?? ''));
        $linkPreview = is_array($attrs['linkPreview'] ?? null)
            ? $this->sanitizeLinkPreview($attrs['linkPreview'])
            : null;
        $pollData    = $this->normalisePollInput($attrs['poll'] ?? null);

        if ($content === '' && $pollData === null) {
            throw new BadRequestException('content or poll required');
        }
        if (mb_strlen($content) > 20000) {
            throw new BadRequestException('Post content may not exceed 20 000 characters.');
        }

        if (empty($model->title)) {
            $derived = $content !== ''
                ? mb_substr(preg_replace('/\s+/', ' ', $content), 0, 80)
                : mb_substr($pollData['question'], 0, 80);
            if (mb_strlen($content) > 80) {
                $derived .= '…';
            }
            $model->title = $derived;
        }
        if (mb_strlen($model->title) > 255) {
            throw new BadRequestException('Title may not exceed 255 characters.');
        }

        $model->user_id             = $actor->id;
        $model->comment_count       = 1;
        $model->last_posted_at      = \Carbon\Carbon::now();
        $model->last_posted_user_id = $actor->id;
        $model->is_locked           = false;

        // Hand the first-post + poll payload to created() as one typed value
        // object on a declared model property (see SocialGroupDiscussion).
        $model->_sgPending = new PendingDiscussionPayload($content, $linkPreview, $pollData);

        return null;
    }

    public function created(object $model, BaseContext $context): ?object
    {
        /** @var SocialGroupDiscussion $model */
        $payload     = $model->_sgPending;
        $content     = $payload?->content ?? '';
        $linkPreview = $payload?->linkPreview;
        $pollData    = $payload?->poll;

        SocialGroupPost::create([
            'discussion_id'  => $model->id,
            'group_id'       => $model->group_id,
            'user_id'        => $model->user_id,
            'content'        => $content,
            'content_parsed' => $content !== '' ? $this->formatter->parse($content) : null,
            'link_preview'   => $linkPreview,
        ]);

        if ($pollData !== null && $this->capabilities->polls) {
            $poll = SgPoll::create([
                'discussion_id'   => $model->id,
                'question'        => $pollData['question'],
                'is_multi_select' => $pollData['is_multi_select'],
                'ends_at'         => $pollData['ends_at'],
            ]);
            foreach ($pollData['options'] as $i => $optionText) {
                SgPollOption::create([
                    'poll_id'    => $poll->id,
                    'text'       => $optionText,
                    'sort_order' => $i,
                ]);
            }
        }

        return null;
    }
}
