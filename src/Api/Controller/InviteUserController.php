<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class InviteUserController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $params  = $request->getQueryParams();
            $groupId = $params['id'] ?? null;

            $body     = (array) ($request->getParsedBody() ?? []);
            $username = trim((string) ($body['username'] ?? ''));

            if (! $groupId || ! $username) {
                return new JsonResponse(['error' => 'Group ID and username are required.'], 422);
            }

            $group = SocialGroup::findOrFail($groupId);

            // Only creator or moderator (admin role) or forum admin can invite
            $actorMember = $group->members()->where('user_id', $actor->id)->first();
            $canInvite   = $actor->isAdmin()
                || ($actorMember && in_array($actorMember->role, ['creator', 'admin']));

            if (! $canInvite) {
                return new JsonResponse(['error' => 'Only group moderators can invite users.'], 403);
            }

            // Find target user by username
            $targetUser = User::where('username', $username)->first();
            if (! $targetUser) {
                return new JsonResponse(['error' => 'User not found.'], 404);
            }

            // Already a member?
            if ($group->members()->where('user_id', $targetUser->id)->exists()) {
                return new JsonResponse(['error' => 'That user is already a member of this group.'], 422);
            }

            $group->members()->create([
                'user_id'   => $targetUser->id,
                'role'      => 'member',
                'joined_at' => \Carbon\Carbon::now(),
            ]);
            $group->increment('member_count');

            return new JsonResponse([
                'userId'      => $targetUser->id,
                'displayName' => $targetUser->display_name,
                'avatarUrl'   => $targetUser->avatar_url,
                'slug'        => $targetUser->username,
                'role'        => 'member',
                'memberCount' => $group->fresh()->member_count,
            ], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Group not found.'], 404);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
