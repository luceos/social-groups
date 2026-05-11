<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListGroupPostsController implements RequestHandlerInterface
{
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

            // Private groups: only members can view posts
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
                'data' => $posts->map(fn ($p) => $this->serializePost($p, $actorId, $now))->values(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Discussion not found.'], 404);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'trace' => $e->getFile().':'.$e->getLine()], 500);
        }
    }

    private function serializePost(SocialGroupPost $p, ?int $actorId, string $fallbackTime): array
    {
        $createdAt = $p->created_at?->toIso8601String() ?? $fallbackTime;
        $updatedAt = $p->updated_at?->toIso8601String() ?? $createdAt;

        return [
            'id'           => $p->id,
            'discussionId' => $p->discussion_id,
            'content'      => $p->content,
            'createdAt'    => $createdAt,
            'updatedAt'    => $updatedAt,
            'canEdit'      => $actorId && $actorId === $p->user_id,
            'canDelete'    => $actorId && $actorId === $p->user_id,
            'user'         => $p->user ? [
                'id'          => $p->user->id,
                'displayName' => $p->user->display_name,
                'avatarUrl'   => $p->user->avatar_url,
                'slug'        => $p->user->username,
            ] : null,
        ];
    }
}
