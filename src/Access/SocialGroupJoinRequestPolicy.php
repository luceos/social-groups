<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupJoinRequest;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Política de SocialGroupJoinRequest — espelha a checagem `actor->id ===
 * group->user_id || isAdmin()` que ApproveJoinRequestController e
 * RejectJoinRequestController duplicavam.
 *
 * Approve e delete (rejeição) usam o mesmo gate: só o dono do grupo
 * ou admin global pode decidir.
 */
class SocialGroupJoinRequestPolicy extends AbstractPolicy
{
    public function approve(User $actor, SocialGroupJoinRequest $request)
    {
        return $this->canDecide($actor, $request) ? $this->allow() : null;
    }

    public function delete(User $actor, SocialGroupJoinRequest $request)
    {
        return $this->canDecide($actor, $request) ? $this->allow() : null;
    }

    protected function canDecide(User $actor, SocialGroupJoinRequest $request): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        $group = $request->group;
        return $group !== null && (int) $actor->id === (int) $group->user_id;
    }
}
