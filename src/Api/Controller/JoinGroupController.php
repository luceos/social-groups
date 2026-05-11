<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class JoinGroupController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id    = $request->getAttribute('id');
        $group = SocialGroup::findOrFail($id);

        // Private groups require an invite — for now just block joining
        if ($group->is_private && $actor->id !== $group->user_id && ! $actor->isAdmin()) {
            return new JsonResponse(['error' => 'This group is private'], 403);
        }

        $existing = $group->members()->where('user_id', $actor->id)->first();

        if ($existing) {
            return new JsonResponse([
                'status'      => 'joined',
                'memberCount' => $group->member_count,
                'isMember'    => true,
            ]);
        }

        // If approval required, create a join request instead
        if ($group->membership_type === 'approval') {
            $pendingRequest = $group->joinRequests()->where('user_id', $actor->id)->first();
            if (! $pendingRequest) {
                $group->joinRequests()->create(['user_id' => $actor->id, 'status' => 'pending']);
            }
            return new JsonResponse(['status' => 'pending', 'memberCount' => $group->member_count]);
        }

        $group->members()->create([
            'user_id'   => $actor->id,
            'role'      => 'member',
            'joined_at' => \Carbon\Carbon::now(),
        ]);
        $group->increment('member_count');

        return new JsonResponse([
            'status'      => 'joined',
            'memberCount' => $group->fresh()->member_count,
            'isMember'    => true,
        ]);
    }
}
