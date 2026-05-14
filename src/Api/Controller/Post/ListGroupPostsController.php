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

class ListGroupPostsController implements RequestHandlerInterface
{
    public function __construct(private Formatter $formatter) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor        = RequestUtil::getActor($request);
            $params       = $request->getQueryParams();
            $discussionId = $params['discussionId'] ?? null;

            if (! $discussionId) {
                return new JsonResponse(['error' => 'discussionId is required.'], 422);
            }

            $discussion = SocialGroupDiscussion::with('group')->findOrFail($discussionId);
            $group      = $discussion->group;

            if ($group->is_private) {
                $actor->assertRegistered();
                $isMember = $group->members()->where('user_id', $actor->id)->exists();
                if (! $isMember && ! $actor->isAdmin()) {
                    return new JsonResponse(['error' => 'This group is private.'], 403);
                }
            }

            $posts = SocialGroupPost::where('discussion_id', $discussionId)
                ->with('user')
                ->orderBy('created_at')
                ->get();

            $actorId = $actor->exists ? $actor->id : null;
            $now     = \Carbon\Carbon::now()->toIso8601String();

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
                    'canDelete'    => $actorId && ($actorId === $discussion->user_id || $actor->isAdmin()),
                ],
                'data' => $posts->map(fn ($p) => $this->serializePost($p, $actorId, $now, $reactionsByPost->all(), $actorReactions))->values(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Discussion not found.'], 404);
        } catch (\Throwable $e) {
            resolve('log')->error('[social-groups] ListGroupPostsController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    private function serializePost(SocialGroupPost $p, ?int $actorId, string $fallbackTime, array $reactionsByPost = [], array $actorReactions = []): array
    {
        $createdAt = $p->created_at?->toIso8601String() ?? $fallbackTime;
        $updatedAt = $p->updated_at?->toIso8601String() ?? $createdAt;

        $contentParsed = $p->content_parsed !== null
            ? $this->formatter->render($p->content_parsed)
            : nl2br(htmlspecialchars($p->content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

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
            'canEdit'       => $actorId && $actorId === $p->user_id,
            'canDelete'     => $actorId && $actorId === $p->user_id,
            'user'          => $p->user ? [
                'id'          => $p->user->id,
                'displayName' => $p->user->display_name,
                'avatarUrl'   => $p->user->avatar_url,
                'slug'        => $p->user->username,
            ] : null,
        ];
    }
}
