<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CreateGroupPostController implements RequestHandlerInterface
{
    public function __construct(private Formatter $formatter) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body         = (array) ($request->getParsedBody() ?? []);
        $discussionId = (int) ($body['discussionId'] ?? 0);
        $content      = trim((string) ($body['content'] ?? ''));

        if (! $discussionId || ! $content) {
            return new JsonResponse(['error' => 'discussionId and content are required.'], 422);
        }

        if (mb_strlen($content) > 20000) {
            return new JsonResponse(['error' => 'Post content may not exceed 20 000 characters.'], 422);
        }

        $discussion = SocialGroupDiscussion::with('group')->findOrFail($discussionId);

        if ($discussion->is_locked) {
            return new JsonResponse(['error' => 'This discussion is locked.'], 403);
        }

        // Must be a member to reply
        $group    = $discussion->group;
        $isMember = $group->members()->where('user_id', $actor->id)->exists();
        if (! $isMember) {
            return new JsonResponse(['error' => 'You must be a member of this group to reply.'], 403);
        }

        $contentParsed = $this->formatter->parse($content);

        $post = SocialGroupPost::create([
            'discussion_id'  => $discussion->id,
            'group_id'       => $discussion->group_id,
            'user_id'        => $actor->id,
            'content'        => $content,
            'content_parsed' => $contentParsed,
        ]);

        // Update discussion metadata
        $discussion->increment('comment_count');
        $discussion->last_posted_at      = \Carbon\Carbon::now();
        $discussion->last_posted_user_id = $actor->id;
        $discussion->save();

        return new JsonResponse([
            'id'             => $post->id,
            'discussionId'   => $post->discussion_id,
            'content'        => $post->content,
            'contentParsed'  => $this->formatter->render($post->content_parsed),
            'createdAt'      => $post->created_at->toIso8601String(),
            'updatedAt'      => $post->updated_at->toIso8601String(),
            'likeCount'      => 0,
            'isLiked'        => false,
            'canEdit'        => true,
            'canDelete'      => true,
            'user'           => [
                'id'          => $actor->id,
                'displayName' => $actor->display_name,
                'avatarUrl'   => $actor->avatar_url,
                'slug'        => $actor->username,
            ],
        ], 201);
    }
}
