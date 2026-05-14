<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LeaveGroupController implements RequestHandlerInterface
{
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
