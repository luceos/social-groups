<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListJoinRequestsController implements RequestHandlerInterface
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

        if ($actor->id !== $group->user_id && ! $actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $requests = $group->joinRequests()->where('status', 'pending')->with('user')->get();

        return new JsonResponse([
            'data' => $requests->map(fn ($r) => [
                'id'        => $r->id,
                'userId'    => $r->user_id,
                'user'      => [
                    'id'          => $r->user->id,
                    'displayName' => $r->user->display_name,
                    'avatarUrl'   => $r->user->avatar_url,
                ],
                'createdAt' => $r->created_at->toIso8601String(),
            ])->values(),
        ]);
    }
}
