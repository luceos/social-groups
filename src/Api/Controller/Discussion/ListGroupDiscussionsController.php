<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Discussion;

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
    public function __construct(private Formatter $formatter) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor   = RequestUtil::getActor($request);
            $params  = $request->getQueryParams();
            $groupId = $params['groupId'] ?? null;
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

            $total = SocialGroupDiscussion::where('group_id', $groupId)->count();

            $discussions = SocialGroupDiscussion::where('group_id', $groupId)
                ->with(['user', 'lastPostedUser'])
                ->orderByDesc('last_posted_at')
                ->skip($offset)
                ->take($limit)
                ->get();

            $actorId       = $actor->exists ? $actor->id : null;
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

            $now = \Carbon\Carbon::now()->toIso8601String();

            return new JsonResponse([
                'data'  => $discussions->map(function ($d) use ($firstPostsByDiscussion, $reactionsByPost, $actorReactions, $actorId, $now) {
                    return $this->serialize($d, $firstPostsByDiscussion[$d->id] ?? null, $reactionsByPost->all(), $actorReactions, $actorId, $now);
                })->values(),
                'total' => $total,
                'page'  => $page,
                'pages' => (int) ceil($total / $limit),
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
            'lastPostedAt'   => $d->last_posted_at?->toIso8601String(),
            'createdAt'      => ($d->created_at ?? $d->last_posted_at)?->toIso8601String() ?? $now,
            'canDelete'      => $actorId && ($actorId === $d->user_id || \Flarum\User\User::find($actorId)?->isAdmin()),
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
