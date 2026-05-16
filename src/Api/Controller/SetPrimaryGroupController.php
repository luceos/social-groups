<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SetPrimaryGroupController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body    = (array) ($request->getParsedBody() ?? []);
        $groupId = $body['groupId'] ?? null;

        if ($groupId === null) {
            // Clear primary group
            $actor->sg_primary_group_id = null;
            $actor->save();
            return new JsonResponse(['primaryGroupId' => null]);
        }

        // Verify actor is actually a member of this group
        $group = SocialGroup::find($groupId);

        if (! $group) {
            return new JsonResponse(['error' => 'Group not found'], 404);
        }

        $isMember = $group->members()->where('user_id', $actor->id)->exists();

        if (! $isMember) {
            return new JsonResponse(['error' => 'You are not a member of this group'], 403);
        }

        $actor->sg_primary_group_id = $group->id;
        $actor->save();

        return new JsonResponse([
            'primaryGroupId'    => $group->id,
            'primaryGroupName'  => $group->name,
            'primaryGroupColor' => $group->color,
            'primaryGroupSlug'  => $group->slug,
        ]);
    }
}
