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

        $params = $request->getQueryParams();
        $id     = $params['id'] ?? null;
        if (! $id) {
            preg_match('#/social-groups/(\d+)#', $request->getUri()->getPath(), $m);
            $id = $m[1] ?? null;
        }
        $group = SocialGroup::findOrFail($id);

        // Private groups require an invite — for now just block joining
        if ($group->is_private && $actor->id !== $group->user_id && ! $actor->isAdmin()) {
            return new JsonResponse(['error' => 'This group is private'], 403);
        }

        $existing = $group->members()->where('user_id', $actor->id)->first();

        if ($existing) {
            if ($existing->banned_at !== null) {
                return new JsonResponse(['error' => 'You have been removed from this group.'], 403);
            }
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

        // Invite-only: membership is granted by a moderator via
        // InviteUserController. POST /join must refuse — falling through
        // to the open-join path would let any registered user self-admit.
        // The `is_private` guard above is not enough on its own: a group
        // can be invite-only without being marked private.
        if ($group->membership_type === 'invite') {
            return new JsonResponse(['error' => 'This group is invite-only.'], 403);
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
