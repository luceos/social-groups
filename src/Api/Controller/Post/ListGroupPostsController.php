<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostReaction;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class ListGroupPostsController implements RequestHandlerInterface
{
    public function __construct(
        private Formatter $formatter,
        private LoggerInterface $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor        = RequestUtil::getActor($request);
            $params       = $request->getQueryParams();
            $discussionId = $request->getAttribute('discussionId') ?? ($params['discussionId'] ?? null);
            if (! $discussionId) {
                preg_match('#/sg-thread-posts/(\d+)#', $request->getUri()->getPath(), $m);
                $discussionId = $m[1] ?? null;
            }

            if (! $discussionId) {
                return new JsonResponse(['error' => 'discussionId is required.'], 422);
            }

            $discussion = SocialGroupDiscussion::with('group')->findOrFail($discussionId);
            $group      = $discussion->group;

            if ($group->is_private) {
                $actor->assertRegistered();
                $isMember  = $group->members()->where('user_id', $actor->id)->exists();
                $isCreator = (int) $group->user_id === (int) $actor->id;
                if (! $isMember && ! $isCreator && ! $actor->isAdmin()) {
                    return new JsonResponse(['error' => 'This group is private.'], 403);
                }
            }

            // Pinned posts always render at the top of the thread regardless of
            // their chronological position. The composite index added by the
            // 2026_05_17 migration (discussion_id, is_pinned) keeps the planner
            // happy here even on threads with hundreds of replies.
            $posts = SocialGroupPost::where('discussion_id', $discussionId)
                ->with('user')
                ->orderByDesc('is_pinned')
                ->orderBy('created_at')
                ->get();

            $actorId = $actor->exists ? $actor->id : null;
            $now     = \Carbon\Carbon::now()->toIso8601String();

            // Resolve actor's moderation rights once — reused for every post in the list.
            $isAdmin     = $actor->isAdmin();
            $isModerator = $actorId && ! $isAdmin
                ? $group->members()
                    ->where('user_id', $actorId)
                    ->whereIn('role', ['creator', 'admin'])
                    ->exists()
                : false;

            // Batch-load reaction counts grouped by (post_id, reaction)
            $postIds = $posts->pluck('id')->all();

            $reactionsByPost = SocialGroupPostReaction::whereIn('post_id', $postIds)
                ->selectRaw('post_id, reaction, COUNT(*) as cnt')
                ->groupBy('post_id', 'reaction')
                ->get()
                ->groupBy('post_id')
                ->map(fn ($rows) => $rows->pluck('cnt', 'reaction')->all());

            $actorReactions = $actorId
                ? SocialGroupPostReaction::whereIn('post_id', $postIds)
                    ->where('user_id', $actorId)
                    ->pluck('reaction', 'post_id')
                    ->all()
                : [];

            return new JsonResponse([
                'discussion' => [
                    'id'           => $discussion->id,
                    'groupId'      => $discussion->group_id,
                    'title'        => $discussion->title,
                    'commentCount' => $discussion->comment_count,
                    'isLocked'     => (bool) $discussion->is_locked,
                    'createdAt'    => ($discussion->created_at ?? $discussion->last_posted_at)?->toIso8601String() ?? $now,
                    'canDelete'    => $actorId && ($actorId === $discussion->user_id || $isAdmin || $isModerator),
                ],
                'data' => $posts->map(fn ($p) => $this->serializePost($p, $actorId, $isAdmin, $isModerator, $now, $reactionsByPost->all(), $actorReactions))->values(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Discussion not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] ListGroupPostsController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    private function serializePost(SocialGroupPost $p, ?int $actorId, bool $isAdmin, bool $isModerator, string $fallbackTime, array $reactionsByPost = [], array $actorReactions = []): array
    {
        $createdAt = $p->created_at?->toIso8601String() ?? $fallbackTime;
        $updatedAt = $p->updated_at?->toIso8601String() ?? $createdAt;

        try {
            $contentParsed = $p->content_parsed !== null
                ? $this->formatter->render($p->content_parsed)
                : nl2br(htmlspecialchars($p->content ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        } catch (\Throwable) {
            $contentParsed = nl2br(htmlspecialchars($p->content ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return [
            'id'            => $p->id,
            'discussionId'  => $p->discussion_id,
            'content'       => $p->content,
            'contentParsed' => $contentParsed,
            'createdAt'     => $createdAt,
            'updatedAt'     => $updatedAt,
            'reactions'     => (object) ($reactionsByPost[$p->id] ?? []),
            'actorReaction' => $actorReactions[$p->id] ?? null,
            'parentPostId'  => $p->parent_post_id,
            'linkPreview'   => $p->link_preview,
            'isPinned'      => (bool) ($p->is_pinned ?? false),
            'canEdit'       => $actorId && $actorId === $p->user_id,
            'canDelete'     => $actorId && ($actorId === $p->user_id || $isAdmin || $isModerator),
            'canPin'        => $actorId && ($isAdmin || $isModerator),
            'user'          => $p->user ? [
                'id'          => $p->user->id,
                'displayName' => $p->user->display_name,
                'avatarUrl'   => $p->user->avatar_url,
                'slug'        => $p->user->username,
            ] : null,
        ];
    }
}
