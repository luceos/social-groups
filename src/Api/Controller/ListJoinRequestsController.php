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
            'data' => $requests->map(function ($r) {
                // Defensive: a soft-deleted/deleted user leaves a join request
                // row behind; without this guard the controller 500'd on the
                // null user->id deref. createdAt similarly: string-vs-Carbon
                // is handled by the model now ($timestamps=true), but if the
                // column is genuinely null on old rows the toIso8601String
                // call would still throw — fall back to "now".
                $user = $r->user;
                $created = $r->created_at;
                $createdIso = $created instanceof \Carbon\Carbon
                    ? $created->toIso8601String()
                    : (is_string($created) && $created !== ''
                        ? \Carbon\Carbon::parse($created)->toIso8601String()
                        : \Carbon\Carbon::now()->toIso8601String());

                return [
                    'id'        => $r->id,
                    'userId'    => $r->user_id,
                    'user'      => $user ? [
                        'id'          => $user->id,
                        'displayName' => $user->display_name,
                        'avatarUrl'   => $user->avatar_url,
                    ] : null,
                    'createdAt' => $createdIso,
                ];
            })->values(),
        ]);
    }
}
