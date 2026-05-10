<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class SocialGroupPolicy extends AbstractPolicy
{
    public function create(User $actor): bool
    {
        return $actor->hasPermission('ernestdefoe-social-groups.create');
    }

    public function edit(User $actor, SocialGroup $group): bool
    {
        return $actor->id === $group->user_id || $actor->isAdmin();
    }

    public function delete(User $actor, SocialGroup $group): bool
    {
        return $actor->id === $group->user_id || $actor->isAdmin();
    }
}
