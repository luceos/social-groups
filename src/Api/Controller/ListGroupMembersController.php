<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Access\GroupVisibility;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListGroupMembersController implements RequestHandlerInterface
{
    /**
     * Devolve o roster de membros de um grupo. Exige actor registrado;
     * para grupos privados, restringe a membros ativos, ao dono e a
     * admins — guests e estranhos recebem 403 sem vazar existência ou
     * tamanho do roster.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $params = $request->getQueryParams();
        $id     = $params['id'] ?? null;
        if (! $id) {
            preg_match('#/social-groups/(\d+)#', $request->getUri()->getPath(), $m);
            $id = $m[1] ?? null;
        }
        if (! $id || ! ctype_digit((string) $id)) {
            throw new RouteNotFoundException();
        }

        $group = SocialGroup::findOrFail((int) $id);

        $actorId     = (int) $actor->id;
        $isCreator   = $actorId === (int) $group->user_id;
        $actorMember = $group->members()->where('user_id', $actorId)->first();
        $actorRole   = $actorMember?->role;
        $actorCanMod = $actor->isAdmin() || in_array($actorRole, ['creator', 'moderator'], true);

        if (! GroupVisibility::canSee($actor, $group)) {
            throw new PermissionDeniedException();
        }

        $members = $group->members()->with('user')->whereNull('banned_at')->get()->map(function ($member) use ($actorCanMod, $isCreator, $actor) {
            $user = $member->user;
            if (! $user) return null;

            return [
                'userId'      => $user->id,
                'displayName' => $user->display_name,
                'avatarUrl'   => $user->avatar_url,
                'slug'        => $user->username,
                'role'        => $member->role,
                'joinedAt'    => $member->joined_at?->toIso8601String(),
                'canModerate' => $isCreator,
                'canRemove'   => $actorCanMod && $member->role !== 'creator' && $user->id !== $actor->id,
            ];
        })->filter()->values();

        return new JsonResponse(['data' => $members->toArray()]);
    }
}
