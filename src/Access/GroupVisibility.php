<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\User\User;

/**
 * Helper único para decidir se um actor pode ver um grupo. Antes
 * existia uma cópia ligeiramente diferente desta regra em quatro
 * lugares (SocialGroupResource, SocialGroupDiscussionResource,
 * SocialGroupDiscussionPolicy, ListGroupMembersController) e cada
 * cópia tinha drift sutil — uma checava `whereNull('banned_at')`,
 * outra não; uma deixava admin global passar mas não o moderador
 * customizado da extensão, etc.
 *
 * Centralizando aqui:
 *  - admin global passa,
 *  - quem tem `ernestdefoe-social-groups.moderate` passa,
 *  - grupo público passa para todos,
 *  - grupo privado: dono OU membro ativo (banned_at IS NULL).
 *
 * Guest em grupo privado é sempre negado — o callsite que precisar
 * de `assertRegistered()` deve fazê-lo antes desta chamada, não
 * confiar em "guest tem id 0 que coincide com user_id nunca".
 */
class GroupVisibility
{
    public static function canSee(User $actor, SocialGroup $group): bool
    {
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return true;
        }

        if (! $group->is_private) {
            return true;
        }

        if (! $actor->exists) {
            return false;
        }

        if ((int) $actor->id === (int) $group->user_id) {
            return true;
        }

        return $group->members()
            ->where('user_id', $actor->id)
            ->whereNull('banned_at')
            ->exists();
    }
}
