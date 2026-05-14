<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Discussion;

use Ernestdefoe\SocialGroups\Api\Concern\SerializesPoll;
use Ernestdefoe\SocialGroups\Model\SgPoll;
use Ernestdefoe\SocialGroups\Model\SgPollVote;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostReaction;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListGroupDiscussionsController implements RequestHandlerInterface
{
    use SerializesPoll;

    public function __construct(private Formatter $formatter) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor   = RequestUtil::getActor($request);
            $params  = $request->getQueryParams();
            $groupId = $request->getAttribute('groupId') ?? ($params['groupId'] ?? null);
            $page    = max(1, (int) ($params['page'] ?? 1));
            $limit   = 20;
            $offset  = ($page - 1) * $limit;

            if (! $groupId) {
                return new JsonResponse(['error' => 'groupId is required.'], 422);
            }

            $group = SocialGroup::findOrFail($groupId);

            if ($group->is_private) {
                $actor->assertRegistered();
                $isMember = $group->members()->where('user_id', $actor->id)->exists();
                if (! $isMember && ! $actor->isAdmin()) {
                    return new JsonResponse(['error' => 'This group is private.'], 403);
                }
            }

            $search = trim((string) ($params['q'] ?? ''));

            $applySearch = function ($query) use ($search) {
                if ($search === '') return;
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $query->leftJoin('social_group_posts as sgp_s', function ($join) {
                    $join->on('sgp_s.discussion_id', '=', 'social_group_discussions.id')
                         ->whereRaw('sgp_s.id = (SELECT MIN(id) FROM social_group_posts WHERE discussion_id = social_group_discussions.id)');
                })
                ->where(function ($q) use ($like) {
                    $q->where('social_group_discussions.title', 'like', $like)
                      ->orWhere('sgp_s.content', 'like', $like);
                })
                ->select('social_group_discussions.*');
            };

            $countQuery = SocialGroupDiscussion::where('social_group_discussions.group_id', $groupId);
            $applySearch($countQuery);
            $total = $countQuery->count();

            $discussionsQuery = SocialGroupDiscussion::where('social_group_discussions.group_id', $groupId)
                ->with(['user', 'lastPostedUser']);
            $applySearch($discussionsQuery);
            $discussions = $discussionsQuery
                ->orderByDesc('social_group_discussions.is_pinned')
                ->orderByDesc('social_group_discussions.last_posted_at')
                ->skip($offset)
                ->take($limit)
                ->get();

            $actorId       = $actor->exists ? $actor->id : null;

            $actorCanPin = $actorId
                ? ($actor->isAdmin() || $group->members()
                    ->where('user_id', $actorId)
                    ->whereIn('role', ['creator', 'moderator'])
                    ->exists())
                : false;

            $discussionIds = $discussions->pluck('id')->all();

            // Batch-load first post for each discussion
            $firstPostsByDiscussion = SocialGroupPost::with('user')
                ->whereIn('discussion_id', $discussionIds)
                ->orderBy('created_at')
                ->get()
                ->groupBy('discussion_id')
                ->map(fn ($posts) => $posts->first());

            $firstPostIds = $firstPostsByDiscussion->map(fn ($p) => $p?->id)->filter()->values()->all();

            // Batch-load reaction counts per post, grouped by (post_id, reaction)
            $reactionsByPost = SocialGroupPostReaction::whereIn('post_id', $firstPostIds)
                ->selectRaw('post_id, reaction, COUNT(*) as cnt')
                ->groupBy('post_id', 'reaction')
                ->get()
                ->groupBy('post_id')
                ->map(fn ($rows) => $rows->pluck('cnt', 'reaction')->all());

            $actorReactions = $actorId
                ? SocialGroupPostReaction::whereIn('post_id', $firstPostIds)
                    ->where('user_id', $actorId)
                    ->pluck('reaction', 'post_id')
                    ->all()
                : [];

            // Batch-load sharedFrom data
            $sharedIds = $discussions->pluck('shared_from_discussion_id')->filter()->unique()->values()->all();
            $sharedFromMap = [];

            if (! empty($sharedIds)) {
                $sharedDiscussions = SocialGroupDiscussion::whereIn('id', $sharedIds)
                    ->with(['group', 'user'])
                    ->get()
                    ->keyBy('id');

                $sharedFirstPosts = SocialGroupPost::with('user')
                    ->whereIn('discussion_id', $sharedIds)
                    ->orderBy('created_at')
                    ->get()
                    ->groupBy('discussion_id')
                    ->map(fn ($posts) => $posts->first());

                foreach ($sharedIds as $sharedId) {
                    $orig = $sharedDiscussions[$sharedId] ?? null;
                    if (! $orig) continue;
                    $fp = $sharedFirstPosts[$sharedId] ?? null;
                    $sharedFromMap[$sharedId] = [
                        'discussionId' => $orig->id,
                        'title'        => $orig->title,
                        'groupId'      => $orig->group_id,
                        'groupName'    => $orig->group?->name,
                        'groupSlug'    => $orig->group?->slug,
                        'snippet'      => $fp ? mb_substr(strip_tags($fp->content), 0, 200) : '',
                        'user'         => $orig->user ? [
                            'displayName' => $orig->user->display_name,
                            'avatarUrl'   => $orig->user->avatar_url,
                        ] : null,
                    ];
                }
            }

            // Batch-load polls
            $pollsByDiscussion = SgPoll::with('options')
                ->whereIn('discussion_id', $discussionIds)
                ->get()
                ->keyBy('discussion_id');

            $pollMap = [];
            if ($pollsByDiscussion->isNotEmpty()) {
                $allOptionIds = $pollsByDiscussion->flatMap(fn ($p) => $p->options->pluck('id'))->all();

                $allVoteCounts = SgPollVote::whereIn('option_id', $allOptionIds)
                    ->selectRaw('option_id, COUNT(*) as cnt')
                    ->groupBy('option_id')
                    ->pluck('cnt', 'option_id')
                    ->all();

                $actorPollVotes = $actorId
                    ? SgPollVote::whereIn('poll_id', $pollsByDiscussion->pluck('id')->all())
                        ->where('user_id', $actorId)
                        ->get()
                        ->groupBy('poll_id')
                        ->map(fn ($rows) => $rows->pluck('option_id')->all())
                    : collect();

                foreach ($pollsByDiscussion as $discId => $poll) {
                    $options = $poll->options->sortBy('sort_order');
                    $pollMap[$discId] = [
                        'id'                  => $poll->id,
                        'question'            => $poll->question,
                        'isMultiSelect'       => (bool) $poll->is_multi_select,
                        'endsAt'              => $poll->ends_at?->toIso8601String(),
                        'totalVotes'          => (int) $options->sum(fn ($o) => $allVoteCounts[$o->id] ?? 0),
                        'actorVotedOptionIds' => $actorPollVotes[$poll->id] ?? [],
                        'options'             => $options->map(fn ($o) => [
                            'id'        => $o->id,
                            'text'      => $o->text,
                            'voteCount' => (int) ($allVoteCounts[$o->id] ?? 0),
                        ])->values()->all(),
                    ];
                }
            }

            $now          = \Carbon\Carbon::now()->toIso8601String();
            $actorIsAdmin = $actorId ? $actor->isAdmin() : false;

            return new JsonResponse([
                'data'  => $discussions->map(function ($d) use ($firstPostsByDiscussion, $reactionsByPost, $actorReactions, $actorId, $actorIsAdmin, $actorCanPin, $sharedFromMap, $pollMap, $now) {
                    return $this->serialize($d, $firstPostsByDiscussion[$d->id] ?? null, $reactionsByPost->all(), $actorReactions, $actorId, $actorIsAdmin, $actorCanPin, $sharedFromMap, $pollMap, $now);
                })->values(),
                'total' => $total,
                'page'  => $page,
                'pages' => (int) ceil($total / $limit),
                'q'     => $search !== '' ? $search : null,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Group not found.'], 404);
        } catch (\Throwable $e) {
            resolve('log')->error('[social-groups] ListGroupDiscussionsController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    private function serialize(
        SocialGroupDiscussion $d,
        ?SocialGroupPost $firstPost,
        array $reactionsByPost,
        array $actorReactions,
        ?int $actorId,
        bool $actorIsAdmin,
        bool $actorCanPin,
        array $sharedFromMap,
        array $pollMap,
        string $now
    ): array {
        $serializedFirstPost = null;

        if ($firstPost) {
            $contentParsed = $firstPost->content_parsed !== null
                ? $this->formatter->render($firstPost->content_parsed)
                : nl2br(htmlspecialchars($firstPost->content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

            $serializedFirstPost = [
                'id'            => $firstPost->id,
                'content'       => $firstPost->content,
                'contentParsed' => $contentParsed,
                'reactions'     => (object) ($reactionsByPost[$firstPost->id] ?? []),
                'actorReaction' => $actorReactions[$firstPost->id] ?? null,
                'linkPreview'   => $firstPost->link_preview,
                'canEdit'       => $actorId && $actorId === $firstPost->user_id,
                'createdAt'     => $firstPost->created_at?->toIso8601String() ?? $now,
                'user'          => $firstPost->user ? [
                    'id'          => $firstPost->user->id,
                    'displayName' => $firstPost->user->display_name,
                    'avatarUrl'   => $firstPost->user->avatar_url,
                ] : null,
            ];
        }

        return [
            'id'             => $d->id,
            'groupId'        => $d->group_id,
            'title'          => $d->title,
            'commentCount'   => $d->comment_count,
            'isLocked'       => (bool) $d->is_locked,
            'isPinned'       => (bool) $d->is_pinned,
            'canPin'         => $actorCanPin,
            'lastPostedAt'   => $d->last_posted_at?->toIso8601String(),
            'createdAt'      => ($d->created_at ?? $d->last_posted_at)?->toIso8601String() ?? $now,
            'canDelete'      => $actorId && ($actorId === $d->user_id || $actorIsAdmin),
            'canShare'       => $actorId !== null,
            'sharedFrom'     => $d->shared_from_discussion_id
                ? ($sharedFromMap[$d->shared_from_discussion_id] ?? null)
                : null,
            'poll'           => $pollMap[$d->id] ?? null,
            'firstPost'      => $serializedFirstPost,
            'user'           => $d->user ? [
                'id'          => $d->user->id,
                'displayName' => $d->user->display_name,
                'avatarUrl'   => $d->user->avatar_url,
            ] : null,
            'lastPostedUser' => $d->lastPostedUser ? [
                'id'          => $d->lastPostedUser->id,
                'displayName' => $d->lastPostedUser->display_name,
                'avatarUrl'   => $d->lastPostedUser->avatar_url,
            ] : null,
        ];
    }
}
