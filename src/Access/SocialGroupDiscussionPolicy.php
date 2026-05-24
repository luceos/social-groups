<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Política do modelo `SocialGroupDiscussion`. Espelha as checagens
 * inline que o `ListGroupDiscussionsController` e o
 * `DeleteGroupDiscussionController` faziam imperativamente, expostas
 * agora como verbs de policy para que o `SocialGroupDiscussionResource`
 * e seus `Endpoint\*->can()` consultem o pipeline padrão de
 * autorização do Flarum 2.
 *
 * Retornar `null` deixa o pipeline continuar — não use `deny()` aqui
 * sem motivo, senão um cenário onde outra extensão deveria liberar
 * (admin com permissão custom, etc.) é cortado preemptivamente.
 */
class SocialGroupDiscussionPolicy extends AbstractPolicy
{
    /**
     * Pode ver a discussão se puder ver o grupo dela. Em grupos
     * privados, exige ser dono, membro ativo, admin ou moderador
     * global da extensão.
     */
    public function view(User $actor, SocialGroupDiscussion $discussion)
    {
        $group = $discussion->group;
        if ($group === null) {
            return null;
        }
        if (! $this->canSeeGroup($actor, $group)) {
            return $this->deny();
        }
        return null;
    }

    /**
     * Pode criar discussão no grupo se for membro, dono, admin ou
     * moderador global. O Endpoint\Create passa o modelo sendo criado
     * em `$arguments`, mas no momento do gate o `group_id` pode ainda
     * não ter sido populado — preferimos checar via permission global +
     * verificação inline no resource creating().
     */
    public function create(User $actor)
    {
        if (! $actor->exists) {
            return $this->deny();
        }
        return null;
    }

    /**
     * Apaga só se for o autor, admin ou moderador global.
     */
    public function delete(User $actor, SocialGroupDiscussion $discussion)
    {
        if ((int) $actor->id === (int) $discussion->user_id) {
            return $this->allow();
        }
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return $this->allow();
        }
        return null;
    }

    /**
     * Pin/unpin restrito a creator/moderator do próprio grupo, admin
     * ou moderador global da extensão.
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
        $isModInGroup = $group->members()
            ->where('user_id', $actor->id)
            ->whereIn('role', ['creator', 'moderator'])
            ->exists();
        return $isModInGroup ? $this->allow() : null;
    }

    protected function canSeeGroup(User $actor, SocialGroup $group): bool
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
