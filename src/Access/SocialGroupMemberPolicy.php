<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Policy for the `SocialGroupMember` resource — consulted by the
 * Delete (kick), promote and demote endpoints on
 * SocialGroupMemberResource.
 *
 * Returning `$this->allow()` allows; `null` lets the pipeline
 * default-deny.
 */
class SocialGroupMemberPolicy extends AbstractPolicy
{
    /**
     * Promote/demote: only the group's own creator (or global admin).
     * In-group moderators do NOT promote other members — only the
     * owner can touch role levels. Exact mirror of the legacy
     * PromoteMemberController/DemoteMemberController.
     */
    public function promote(User $actor, SocialGroupMember $member)
    {
        if ($actor->isAdmin()) {
            return $this->allow();
        }
        return $this->isGroupCreator($actor, $member) ? $this->allow() : null;
    }

    public function demote(User $actor, SocialGroupMember $member)
    {
        if ($actor->isAdmin()) {
            return $this->allow();
        }
        return $this->isGroupCreator($actor, $member) ? $this->allow() : null;
    }

    /**
     * Delete (kick): admin, extension's global moderator, group
     * creator, or in-group moderator. Never allows removing the
     * group's own creator nor the actor themselves (self-kick is
     * leave, not kick).
     */
    public function delete(User $actor, SocialGroupMember $member)
    {
        if ($member->role === 'creator') {
            return $this->deny();
        }
        if ((int) $member->user_id === (int) $actor->id) {
            return $this->deny();
        }
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return $this->allow();
        }
        $group = $member->group;
        if ($group === null) {
            return null;
        }
        $isMod = $group->activeMembership($actor->id)
            ->whereIn('role', ['creator', 'moderator'])
            ->exists();
        return $isMod ? $this->allow() : null;
    }

    protected function isGroupCreator(User $actor, SocialGroupMember $member): bool
    {
        $group = $member->group;
        if ($group === null) {
            return false;
        }
        return $group->activeMembership($actor->id)
            ->where('role', 'creator')
            ->exists();
    }
}
