<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Discussion;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class PinGroupDiscussionController implements RequestHandlerInterface
{
    public function __construct(private LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor        = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $discussionId = $request->getAttribute('discussionId');
            if (! $discussionId) {
                preg_match('#/sg-discussions/(\d+)#', $request->getUri()->getPath(), $m);
                $discussionId = $m[1] ?? null;
            }
            $discussion   = SocialGroupDiscussion::with('group')->findOrFail($discussionId);
            $group        = $discussion->group;

            $isCreator = $group->members()
                ->where('user_id', $actor->id)
                ->whereIn('role', ['creator', 'moderator'])
                ->exists();

            if (! $isCreator && ! $actor->isAdmin()) {
                return new JsonResponse(['error' => 'Only group moderators and admins can pin discussions.'], 403);
            }

            $discussion->is_pinned = ! $discussion->is_pinned;
            $discussion->save();

            return new JsonResponse(['isPinned' => (bool) $discussion->is_pinned]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Discussion not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] PinGroupDiscussionController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
