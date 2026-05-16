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

class TogglePostReactionController implements RequestHandlerInterface
{
    private const ALLOWED = ['like', 'heart', 'haha', 'wow', 'sad', 'angry'];

    public function __construct(private LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $postId = (int) $request->getAttribute('postId');
            if (! $postId) {
                preg_match('#/sg-posts/(\d+)/#', $request->getUri()->getPath(), $m);
                $postId = (int) ($m[1] ?? 0);
            }

            if (! $postId || ! SocialGroupPost::where('id', $postId)->exists()) {
                return new JsonResponse(['error' => 'Post not found.'], 404);
            }

            $path = $request->getUri()->getPath();
            $isUnreact = str_ends_with($path, '/unreact');

            if ($isUnreact) {
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
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] TogglePostReactionController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
