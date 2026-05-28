<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupJoinRequest;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * SocialGroupJoinRequest policy — mirrors the
 * `actor->id === group->user_id || isAdmin()` check that
 * ApproveJoinRequestController and RejectJoinRequestController used to
 * duplicate.
 *
 * Approve and delete (rejection) use the same gate: only the group
 * owner or a global admin can decide.
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
