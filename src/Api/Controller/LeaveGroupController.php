<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LeaveGroupController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id    = $this->routeParam($request, 'id', '/social-groups/{id}');
        $group = SocialGroup::findOrFail($id);

        // Creators cannot leave their own group — they must delete it
        $deleted = $group->members()
            ->where('user_id', $actor->id)
            ->where('role', '!=', 'creator')
            ->delete();

        if ($deleted) {
            $group->decrement('member_count');
        }

        return new JsonResponse([
            'memberCount' => $group->fresh()->member_count,
            'isMember'    => false,
        ]);
    }
}
