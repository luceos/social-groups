<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * SocialGroupPost policy — consulted by
 * `Endpoint\Update->can('edit')` and `Endpoint\Delete->can('delete')`
 * on SocialGroupPostResource.
 *
 * Returning `null` lets the pipeline continue and default-denies; use
 * `$this->allow()` to allow explicitly when all preconditions pass.
 */
class SocialGroupPostPolicy extends AbstractPolicy
{
    /**
     * Edit: only the author, and only if they are still an active
     * (non-banned) member of the group. Global admin always passes.
     */
    public function edit(User $actor, SocialGroupPost $post)
    {
        if ($actor->isAdmin()) {
            return $this->allow();
        }
        if ((int) $actor->id !== (int) $post->user_id) {
            return null;
        }
        $group = $post->group;
        if ($group === null) {
            return null;
        }
        $active = $group->members()
            ->where('user_id', $actor->id)
            ->whereNull('banned_at')
            ->exists();
        return $active ? $this->allow() : null;
    }

    /**
     * Delete: author, global admin, extension's global moderator, or
     * the group's own creator/moderator.
     */
    public function delete(User $actor, SocialGroupPost $post)
    {
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return $this->allow();
        }
        if ((int) $actor->id === (int) $post->user_id) {
            return $this->allow();
        }
        $group = $post->group;
        if ($group === null) {
            return null;
        }
        $isMod = $group->activeMembership($actor->id)
            ->whereIn('role', ['creator', 'moderator'])
            ->exists();
        return $isMod ? $this->allow() : null;
    }

    /**
     * Pin: moderation action. The author alone CANNOT pin their own
     * post — only global admin, extension's global moderator, or the
     * creator/moderator of the group where the post lives.
     */
    public function pin(User $actor, SocialGroupPost $post)
    {
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return $this->allow();
        }
        $group = $post->group;
        if ($group === null) {
            return null;
        }
        $isMod = $group->activeMembership($actor->id)
            ->whereIn('role', ['creator', 'moderator'])
            ->exists();
        return $isMod ? $this->allow() : null;
    }

    /**
     * React: any active (non-banned) group member can react. Blocks
     * sequential-ID enumeration by outsiders.
     */
    public function react(User $actor, SocialGroupPost $post)
    {
        if ($actor->isAdmin()) {
            return $this->allow();
        }
        $group = $post->group;
        if ($group === null) {
            return null;
        }
        $active = $group->members()
            ->where('user_id', $actor->id)
            ->whereNull('banned_at')
            ->exists();
        return $active ? $this->allow() : null;
    }
}
