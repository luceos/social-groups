<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Support\GroupAssetUrl;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ListUserGroupsController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function __construct(private LoggerInterface $log, private GroupAssetUrl $assetUrl, private TranslatorInterface $translator) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor  = RequestUtil::getActor($request);
            $userId = (int) ($this->routeParam($request, 'userId', '/sg-user-groups/{userId}') ?? 0);

            $profileUser = User::find($userId);
            if (! $profileUser) {
                return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.user_not_found')], 404);
            }

            $primaryGroupId = $profileUser->socialGroupPrimary?->group_id;
            $primaryGroupId = $primaryGroupId !== null ? (int) $primaryGroupId : null;

            $memberships = SocialGroupMember::where('user_id', $userId)
                ->whereNull('banned_at')
                ->with('group')
                ->get();

            // Preload the viewing actor's own active memberships once, so the
            // private-group gate below is an in-memory check instead of one
            // query per private group in the list.
            $actorGroupIds = $actor->exists
                ? SocialGroupMember::where('user_id', $actor->id)
                    ->whereNull('banned_at')
                    ->pluck('group_id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
                : [];

            $groups = $memberships->map(function ($membership) use ($actor, $primaryGroupId, $actorGroupIds) {
                $group = $membership->group;
                if (! $group) return null;

                if ($group->is_private) {
                    if (! $actor->exists) return null;
                    $isMember = in_array((int) $group->id, $actorGroupIds, true);
                    if (! $isMember && ! $actor->isAdmin()) return null;
                }

                return [
                    'id'          => $group->id,
                    'name'        => $group->name,
                    'slug'        => $group->slug,
                    'imageUrl'    => $this->assetUrl->resolve($group->image_url),
                    'color'       => $group->color,
                    'memberCount' => (int) $group->member_count,
                    'role'        => $membership->role,
                    'isPrimary'   => $primaryGroupId !== null && (int) $group->id === $primaryGroupId,
                ];
            })->filter()->values();

            return new JsonResponse(['data' => $groups->toArray()]);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] ListUserGroupsController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.unexpected')], 500);
        }
    }
}
