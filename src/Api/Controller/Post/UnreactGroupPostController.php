<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostReaction;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class UnreactGroupPostController implements RequestHandlerInterface
{
    public function __construct(private LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $postId = (int) ($request->getQueryParams()['postId'] ?? 0);
            if (! $postId) {
                preg_match('#/sg-posts/(\d+)/unreact#', $request->getUri()->getPath(), $m);
                $postId = (int) ($m[1] ?? 0);
            }

            $post = SocialGroupPost::findOrFail($postId);

            SocialGroupPostReaction::where('post_id', $post->id)
                ->where('user_id', $actor->id)
                ->delete();

            return new JsonResponse(ReactGroupPostController::buildResponse($post->id, $actor->id));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return new JsonResponse(['error' => 'Post not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] UnreactGroupPostController: ' . $e->getMessage());
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
