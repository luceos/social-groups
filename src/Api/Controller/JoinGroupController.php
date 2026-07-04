<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class JoinGroupController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id    = $this->routeParam($request, 'id', '/social-groups/{id}');
        $group = SocialGroup::findOrFail($id);

        // Private groups require an invite — for now just block joining
        if ($group->is_private && $actor->id !== $group->user_id && ! $actor->isAdmin()) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.group_private')], 403);
        }

        $existing = $group->members()->where('user_id', $actor->id)->first();

        if ($existing) {
            if ($existing->banned_at !== null) {
                return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.removed_from_group')], 403);
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
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.invite_only')], 403);
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
