<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Policy for the `SocialGroupDiscussion` model. The verbs here are
 * consulted by `SocialGroupDiscussionResource` (Endpoint\*->can()) and
 * by the action controllers that survived the JSON:API migration (Pin,
 * Delete, Share). The duplicated inline check that lived in the legacy
 * `ListGroupDiscussionsController` left with the controller.
 *
 * Returning `null` lets the pipeline continue — don't use `deny()`
 * here without cause, otherwise a scenario where another extension
 * should allow (admin with a custom permission, etc.) gets cut off
 * preemptively.
 */
class SocialGroupDiscussionPolicy extends AbstractPolicy
{
    /**
     * Can view the discussion if able to view its group. On private
     * groups, requires being the owner, an active member, an admin or
     * the extension's global moderator.
     */
    public function view(User $actor, SocialGroupDiscussion $discussion)
    {
        $group = $discussion->group;
        if ($group === null) {
            return null;
        }
        // Allow (not abstain) when the group is visible: the Show endpoint
        // gates on `->can('view')`, and an abstain there denies — so an empty
        // thread (no posts to hydrate the include from) loaded by primary key
        // 403'd for everyone, including guests on public groups.
        return GroupVisibility::canSee($actor, $group) ? $this->allow() : $this->deny();
    }

    /**
     * Can create a discussion in the group if member, owner, admin or
     * global moderator. Endpoint\Create passes the model being created
     * via `$arguments`, but at gate time `group_id` may not be
     * populated yet — we prefer to check via the global permission +
     * inline verification in the resource's creating().
     */
    public function create(User $actor)
    {
        if (! $actor->exists) {
            return $this->deny();
        }
        return null;
    }

    /**
     * Deletes if the actor is the author, an admin, the global moderator,
     * or a creator/moderator of the discussion's own group. The last
     * branch mirrors the `isGroupModerator()` check in
     * SocialGroupDiscussionResource::canDelete so the rendered delete
     * button and the endpoint agree — without it, group moderators saw
     * the control but got a 403 on click.
     */
    public function delete(User $actor, SocialGroupDiscussion $discussion)
    {
        if ((int) $actor->id === (int) $discussion->user_id) {
            return $this->allow();
        }
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return $this->allow();
        }
        $group = $discussion->group;
        if ($group === null) {
            return null;
        }
        if ((int) $actor->id === (int) $group->user_id) {
            return $this->allow();
        }
        $isModInGroup = $group->activeMembership($actor->id)
            ->whereIn('role', ['creator', 'moderator'])
            ->exists();
        return $isModInGroup ? $this->allow() : null;
    }

    /**
     * Pin/unpin restricted to the group's own creator/moderator, an
     * admin or the extension's global moderator.
     */
    public function pin(User $actor, SocialGroupDiscussion $discussion)
    {
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return $this->allow();
        }
        $group = $discussion->group;
        if ($group === null) {
            return $this->deny();
        }
        if ((int) $actor->id === (int) $group->user_id) {
            return $this->allow();
        }
        $isModInGroup = $group->activeMembership($actor->id)
            ->whereIn('role', ['creator', 'moderator'])
            ->exists();
        return $isModInGroup ? $this->allow() : null;
    }

}
