<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupUserPrimary;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SetPrimaryGroupController implements RequestHandlerInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body    = (array) ($request->getParsedBody() ?? []);
        $groupId = $body['groupId'] ?? null;

        if ($groupId === null) {
            // Clear primary group — drop the companion row entirely.
            SocialGroupUserPrimary::where('user_id', $actor->id)->delete();
            return new JsonResponse(['primaryGroupId' => null]);
        }

        // Verify actor is actually a member of this group
        $group = SocialGroup::find($groupId);

        if (! $group) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.group_not_found')], 404);
        }

        $isMember = $group->activeMembership($actor->id)->exists();

        if (! $isMember) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.not_member')], 403);
        }

        SocialGroupUserPrimary::updateOrCreate(
            ['user_id' => $actor->id],
            ['group_id' => $group->id]
        );

        return new JsonResponse([
            'primaryGroupId'    => $group->id,
            'primaryGroupName'  => $group->name,
            'primaryGroupColor' => $group->color,
            'primaryGroupSlug'  => $group->slug,
        ]);
    }
}
