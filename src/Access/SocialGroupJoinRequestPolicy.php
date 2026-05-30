<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupJoinRequest;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * SocialGroupJoinRequest policy — approve and delete (rejection) share
 * one gate: anyone who can `edit` the parent group (owner, global admin,
 * or holder of the `moderate` permission). Delegating to the `edit`
 * ability keeps this in lockstep with the `canEdit` attribute that the
 * JoinRequestsPanel renders against, so the panel is never shown to an
 * actor the backend would then reject.
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
        $group = $request->group;
        return $group !== null && $actor->can('edit', $group);
    }
}
