<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListUserGroupsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor  = RequestUtil::getActor($request);
            $userId = (int) $request->getAttribute('userId');
            if (! $userId) {
                preg_match('#/sg-user-groups/(\d+)#', $request->getUri()->getPath(), $m);
                $userId = (int) ($m[1] ?? 0);
            }

            if (! $userId || ! User::where('id', $userId)->exists()) {
                return new JsonResponse(['error' => 'User not found.'], 404);
            }

            $memberships = SocialGroupMember::where('user_id', $userId)
                ->whereNull('banned_at')
                ->with('group')
                ->get();

            $groups = $memberships->map(function ($membership) use ($actor) {
                $group = $membership->group;
                if (! $group) return null;

                if ($group->is_private) {
                    if (! $actor->exists) return null;
                    $isMember = $group->members()
                        ->where('user_id', $actor->id)
                        ->whereNull('banned_at')
                        ->exists();
                    if (! $isMember && ! $actor->isAdmin()) return null;
                }

                return [
                    'id'          => $group->id,
                    'name'        => $group->name,
                    'slug'        => $group->slug,
                    'imageUrl'    => $group->image_url,
                    'color'       => $group->color,
                    'memberCount' => (int) $group->member_count,
                    'role'        => $membership->role,
                ];
            })->filter()->values();

            return new JsonResponse(['data' => $groups->toArray()]);
        } catch (\Throwable $e) {
            resolve('log')->error('[social-groups] ListUserGroupsController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
