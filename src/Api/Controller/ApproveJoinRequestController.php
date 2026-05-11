<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupJoinRequest;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApproveJoinRequestController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id        = $request->getAttribute('id');
        $requestId = $request->getAttribute('requestId');

        $group       = SocialGroup::findOrFail($id);
        $joinRequest = SocialGroupJoinRequest::findOrFail($requestId);

        if ($actor->id !== $group->user_id && ! $actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        if ((int) $joinRequest->group_id !== (int) $group->id) {
            return new JsonResponse(['error' => 'Request does not belong to this group'], 422);
        }

        if ($joinRequest->status !== 'pending') {
            return new JsonResponse(['error' => 'Request is not pending'], 422);
        }

        $joinRequest->status = 'approved';
        $joinRequest->save();

        // Only create member record if they are not already a member
        $existing = $group->members()->where('user_id', $joinRequest->user_id)->first();
        if (! $existing) {
            $group->members()->create([
                'user_id'   => $joinRequest->user_id,
                'role'      => 'member',
                'joined_at' => \Carbon\Carbon::now(),
            ]);
            $group->increment('member_count');
        }

        return new JsonResponse(['memberCount' => $group->fresh()->member_count]);
    }
}
