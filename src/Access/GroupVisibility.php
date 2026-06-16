<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\User\User;

/**
 * Single helper for deciding whether an actor can see a group.
 * Previously a slightly different copy of this rule lived in four
 * places (SocialGroupResource, SocialGroupDiscussionResource,
 * SocialGroupDiscussionPolicy, ListGroupMembersController) and each
 * copy had subtle drift — one checked `whereNull('banned_at')`, another
 * didn't; one let the global admin pass but not the extension's custom
 * moderator, etc.
 *
 * Centralised here:
 *  - global admin passes,
 *  - anyone with `ernestdefoe-social-groups.moderate` passes,
 *  - actors without core `viewForum` are denied (mirrors how core's
 *    ScopeDiscussionVisibility gates the main forum) — on a stock forum
 *    guests have viewForum so public groups stay visible, but a
 *    login-restricted forum that revokes guest viewForum hides group
 *    content too instead of leaking it,
 *  - public groups pass for everyone who has viewForum,
 *  - private groups: owner OR active member (banned_at IS NULL).
 *
 * Guests on private groups are always denied — call sites that need
 * `assertRegistered()` should do so before calling this; don't rely
 * on "guest has id 0 which never matches user_id".
 */
class GroupVisibility
{
    public static function canSee(User $actor, SocialGroup $group): bool
    {
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return true;
        }

        if (! $actor->hasPermission('viewForum')) {
            return false;
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
