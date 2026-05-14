<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostReaction;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TogglePostReactionController implements RequestHandlerInterface
{
    private const ALLOWED = ['like', 'heart', 'haha', 'wow', 'sad', 'angry'];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $postId = (int) $request->getAttribute('postId');
        SocialGroupPost::findOrFail($postId);

        $method = $request->getMethod();

        if ($method === 'DELETE') {
            SocialGroupPostReaction::where('post_id', $postId)
                ->where('user_id', $actor->id)
                ->delete();

            $actorReaction = null;
        } else {
            $body     = (array) ($request->getParsedBody() ?? []);
            $reaction = trim((string) ($body['reaction'] ?? 'like'));

            if (! in_array($reaction, self::ALLOWED, true)) {
                return new JsonResponse(['error' => 'Invalid reaction type.'], 422);
            }

            SocialGroupPostReaction::updateOrInsert(
                ['post_id' => $postId, 'user_id' => $actor->id],
                ['reaction' => $reaction]
            );

            $actorReaction = $reaction;
        }

        $reactions = SocialGroupPostReaction::where('post_id', $postId)
            ->selectRaw('reaction, COUNT(*) as cnt')
            ->groupBy('reaction')
            ->pluck('cnt', 'reaction')
            ->all();

        return new JsonResponse([
            'reactions'     => (object) $reactions,
            'actorReaction' => $actorReaction,
        ]);
    }
}
