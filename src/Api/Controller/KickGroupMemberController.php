<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class KickGroupMemberController implements RequestHandlerInterface
{
    public function __construct(private LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor  = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $groupId      = $request->getAttribute('id');
            $targetUserId = (int) $request->getAttribute('userId');
            if (! $groupId) {
                preg_match('#/social-groups/(\d+)#', $request->getUri()->getPath(), $m);
                $groupId = $m[1] ?? null;
            }
            if (! $targetUserId) {
                preg_match('#/members/(\d+)#', $request->getUri()->getPath(), $m);
                $targetUserId = (int) ($m[1] ?? 0);
            }

            $group = SocialGroup::findOrFail($groupId);

            $actorMember = $group->members()->where('user_id', $actor->id)->first();
            $actorRole   = $actorMember?->role;

            $canKick = $actor->isAdmin()
                || in_array($actorRole, ['creator', 'moderator'], true);

            if (! $canKick) {
                return new JsonResponse(['error' => 'Only group moderators and admins can remove members.'], 403);
            }

            $targetMember = $group->members()->where('user_id', $targetUserId)->first();

            if (! $targetMember) {
                return new JsonResponse(['error' => 'Member not found.'], 404);
            }

            if ($targetMember->role === 'creator') {
                return new JsonResponse(['error' => 'The group creator cannot be removed.'], 403);
            }

            if ($targetUserId === $actor->id) {
                return new JsonResponse(['error' => 'You cannot remove yourself.'], 422);
            }

            $targetMember->banned_at = \Carbon\Carbon::now();
            $targetMember->save();

            $group->decrement('member_count');

            return new JsonResponse([
                'success'     => true,
                'memberCount' => $group->fresh()->member_count,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Group not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] KickGroupMemberController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
