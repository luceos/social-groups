<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListGroupMembersController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $id     = $params['id'] ?? null;
        if (! $id) {
            preg_match('#/social-groups/(\d+)#', $request->getUri()->getPath(), $m);
            $id = $m[1] ?? null;
        }
        $group = SocialGroup::findOrFail($id);

        $actorMember = $actor->exists
            ? $group->members()->where('user_id', $actor->id)->first()
            : null;
        $actorRole   = $actorMember?->role;
        $actorCanMod = $actor->isAdmin() || in_array($actorRole, ['creator', 'moderator'], true);
        $isCreator   = $actor->exists && $actor->id === $group->user_id;

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
