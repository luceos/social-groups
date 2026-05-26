<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class ListUserGroupsController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function __construct(private LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor  = RequestUtil::getActor($request);
            $userId = (int) ($this->routeParam($request, 'userId', '/sg-user-groups/{userId}') ?? 0);

            $profileUser = User::find($userId);
            if (! $profileUser) {
                return new JsonResponse(['error' => 'User not found.'], 404);
            }

            $primaryGroupId = $profileUser->sg_primary_group_id;

            $memberships = SocialGroupMember::where('user_id', $userId)
                ->whereNull('banned_at')
                ->with('group')
                ->get();

            $groups = $memberships->map(function ($membership) use ($actor, $primaryGroupId) {
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
                    'isPrimary'   => $primaryGroupId && $group->id === $primaryGroupId,
                ];
            })->filter()->values();

            return new JsonResponse(['data' => $groups->toArray()]);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] ListUserGroupsController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
