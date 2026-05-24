<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Política do recurso `SocialGroupMember` — consultada pelos endpoints
 * Delete (kick), promote e demote em SocialGroupMemberResource.
 *
 * Retornar `$this->allow()` libera; `null` deixa o pipeline default-deny.
 */
class SocialGroupMemberPolicy extends AbstractPolicy
{
    /**
     * Promote/demote: somente o creator do próprio grupo (ou admin
     * global). Moderadores in-group NÃO promovem outros membros — só o
     * dono pode mexer no nível de role. Mirror exato do
     * PromoteMemberController/DemoteMemberController legados.
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
     * Delete (kick): admin, moderador global da extensão, creator do
     * grupo, ou moderator in-group. Nunca permite remover o próprio
     * creator do grupo nem o próprio actor (auto-kick é leave, não
     * kick).
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
        $actorMember = $group->members()
            ->where('user_id', $actor->id)
            ->first();
        if ($actorMember && in_array($actorMember->role, ['creator', 'moderator'], true)) {
            return $this->allow();
        }
        return null;
    }

    protected function isGroupCreator(User $actor, SocialGroupMember $member): bool
    {
        $group = $member->group;
        if ($group === null) {
            return false;
        }
        $actorMember = $group->members()
            ->where('user_id', $actor->id)
            ->first();
        return $actorMember !== null && $actorMember->role === 'creator';
    }
}
