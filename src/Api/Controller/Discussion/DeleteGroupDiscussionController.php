<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Discussion;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DeleteGroupDiscussionController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor        = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $params       = $request->getQueryParams();
        $discussionId = $params['discussionId'] ?? null;
        if (! $discussionId) {
            preg_match('#/sg-discussions/(\d+)#', $request->getUri()->getPath(), $m);
            $discussionId = $m[1] ?? null;
        }
        $discussion   = SocialGroupDiscussion::findOrFail($discussionId);

        $isModerator = $discussion->group->members()
            ->where('user_id', $actor->id)
            ->whereIn('role', ['creator', 'admin'])
            ->exists();

        if ($actor->id !== $discussion->user_id && ! $actor->isAdmin() && ! $isModerator) {
            return new JsonResponse(['error' => 'You cannot delete this discussion.'], 403);
        }

        $discussion->delete();

        return new EmptyResponse(204);
    }
}
