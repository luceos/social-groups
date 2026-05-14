<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DemoteMemberController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $params = $request->getQueryParams();
        $id     = $params['id'] ?? null;
        $userId = $params['userId'] ?? null;
        if (! $id) {
            preg_match('#/social-groups/(\d+)#', $request->getUri()->getPath(), $m);
            $id = $m[1] ?? null;
        }
        if (! $userId) {
            preg_match('#/members/(\d+)#', $request->getUri()->getPath(), $m);
            $userId = $m[1] ?? null;
        }

        $group = SocialGroup::findOrFail($id);

        // Only the group creator can demote members
        $actorMembership = $group->members()->where('user_id', $actor->id)->first();
        if (! $actorMembership || $actorMembership->role !== 'creator') {
            return new JsonResponse(['error' => 'Only the group creator can demote members'], 403);
        }

        $targetMembership = $group->members()->where('user_id', $userId)->whereNull('banned_at')->first();
        if (! $targetMembership) {
            return new JsonResponse(['error' => 'User is not a member of this group'], 404);
        }

        if ($targetMembership->role === 'creator') {
            return new JsonResponse(['error' => 'Cannot demote the group creator'], 422);
        }

        $targetMembership->role = 'member';
        $targetMembership->save();

        return new JsonResponse(['role' => 'member']);
    }
}
