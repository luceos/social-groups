<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Discussion;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CreateGroupDiscussionController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor   = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body    = json_decode((string) $request->getBody(), true) ?? [];
        $groupId = (int) ($body['groupId'] ?? 0);
        $title   = trim($body['title'] ?? '');
        $content = trim($body['content'] ?? '');

        if (! $groupId || ! $title || ! $content) {
            return new JsonResponse(['error' => 'groupId, title, and content are required.'], 422);
        }

        if (mb_strlen($title) > 255) {
            return new JsonResponse(['error' => 'Title may not exceed 255 characters.'], 422);
        }

        if (mb_strlen($content) > 20000) {
            return new JsonResponse(['error' => 'Post content may not exceed 20 000 characters.'], 422);
        }

        $group = SocialGroup::findOrFail($groupId);

        // Must be a member to post
        $isMember = $group->members()->where('user_id', $actor->id)->exists();
        if (! $isMember) {
            return new JsonResponse(['error' => 'You must be a member of this group to post.'], 403);
        }

        $discussion = SocialGroupDiscussion::create([
            'group_id'             => $group->id,
            'user_id'              => $actor->id,
            'title'                => $title,
            'comment_count'        => 1,
            'last_posted_at'       => \Carbon\Carbon::now(),
            'last_posted_user_id'  => $actor->id,
        ]);

        SocialGroupPost::create([
            'discussion_id' => $discussion->id,
            'group_id'      => $group->id,
            'user_id'       => $actor->id,
            'content'       => $content,
        ]);

        $discussion->load('user');

        return new JsonResponse([
            'id'           => $discussion->id,
            'groupId'      => $discussion->group_id,
            'title'        => $discussion->title,
            'commentCount' => $discussion->comment_count,
            'isLocked'     => false,
            'lastPostedAt' => $discussion->last_posted_at->toIso8601String(),
            'createdAt'    => $discussion->created_at->toIso8601String(),
            'canDelete'    => true,
            'user'         => [
                'id'          => $actor->id,
                'displayName' => $actor->display_name,
                'avatarUrl'   => $actor->avatar_url,
            ],
            'lastPostedUser' => [
                'id'          => $actor->id,
                'displayName' => $actor->display_name,
                'avatarUrl'   => $actor->avatar_url,
            ],
        ], 201);
    }
}
