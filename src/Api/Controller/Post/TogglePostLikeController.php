<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostLike;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TogglePostLikeController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $postId = (int) $request->getAttribute('postId');
        $post   = SocialGroupPost::findOrFail($postId);
        $method = $request->getMethod();

        if ($method === 'DELETE') {
            SocialGroupPostLike::where('post_id', $postId)
                ->where('user_id', $actor->id)
                ->delete();
        } else {
            SocialGroupPostLike::firstOrCreate([
                'post_id' => $postId,
                'user_id' => $actor->id,
            ]);
        }

        $likeCount = SocialGroupPostLike::where('post_id', $postId)->count();
        $isLiked   = $method !== 'DELETE';

        return new JsonResponse([
            'likeCount' => $likeCount,
            'isLiked'   => $isLiked,
        ]);
    }
}
