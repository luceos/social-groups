<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UpdateGroupPostController implements RequestHandlerInterface
{
    public function __construct(private Formatter $formatter) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor  = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $params  = $request->getQueryParams();
        $postId  = $params['postId'] ?? null;
        if (! $postId) {
            preg_match('#/sg-posts/(\d+)#', $request->getUri()->getPath(), $m);
            $postId = $m[1] ?? null;
        }
        $post = SocialGroupPost::with('group')->findOrFail($postId);

        if ($actor->id !== $post->user_id) {
            return new JsonResponse(['error' => 'You cannot edit this post.'], 403);
        }

        // Even authors must still hold an active (non-banned) membership
        // in the post's group to edit. Without this check a member who
        // was later kicked or banned retains edit rights on every post
        // they ever authored — letting them rewrite group content after
        // moderation. Admins bypass.
        if (! $actor->isAdmin()) {
            $hasActiveMembership = $post->group
                ? $post->group->members()
                    ->where('user_id', $actor->id)
                    ->whereNull('banned_at')
                    ->exists()
                : false;

            if (! $hasActiveMembership) {
                return new JsonResponse(['error' => 'You cannot edit this post.'], 403);
            }
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $content = trim((string) ($body['content'] ?? ''));

        if (! $content) {
            return new JsonResponse(['error' => 'Content cannot be empty.'], 422);
        }

        if (mb_strlen($content) > 20000) {
            return new JsonResponse(['error' => 'Post content may not exceed 20 000 characters.'], 422);
        }

        $contentParsed = $this->formatter->parse($content);

        $post->content        = $content;
        $post->content_parsed = $contentParsed;
        $post->save();

        return new JsonResponse([
            'id'            => $post->id,
            'discussionId'  => $post->discussion_id,
            'content'       => $post->content,
            'contentParsed' => $this->formatter->render($post->content_parsed),
            'createdAt'     => $post->created_at->toIso8601String(),
            'updatedAt'     => $post->updated_at->toIso8601String(),
            'canEdit'       => true,
            'canDelete'     => true,
            'user'          => [
                'id'          => $actor->id,
                'displayName' => $actor->display_name,
                'avatarUrl'   => $actor->avatar_url,
                'slug'        => $actor->username,
            ],
        ]);
    }
}
