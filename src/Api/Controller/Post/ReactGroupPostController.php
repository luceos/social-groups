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

class ReactGroupPostController implements RequestHandlerInterface
{
    private const ALLOWED = ['like', 'heart', 'haha', 'wow', 'sad', 'angry'];

    public function __construct(private LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $postId = (int) ($request->getQueryParams()['postId'] ?? 0);
            if (! $postId) {
                preg_match('#/sg-posts/(\d+)/react#', $request->getUri()->getPath(), $m);
                $postId = (int) ($m[1] ?? 0);
            }

            $body     = (array) ($request->getParsedBody() ?? []);
            $reaction = trim((string) ($body['reaction'] ?? ''));

            if (! in_array($reaction, self::ALLOWED, true)) {
                return new JsonResponse(['error' => 'Invalid reaction.'], 422);
            }

            $post = SocialGroupPost::findOrFail($postId);

            SocialGroupPostReaction::updateOrCreate(
                ['post_id' => $post->id, 'user_id' => $actor->id],
                ['reaction' => $reaction]
            );

            return new JsonResponse(self::buildResponse($post->id, $actor->id));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return new JsonResponse(['error' => 'Post not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] ReactGroupPostController: ' . $e->getMessage());
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public static function buildResponse(int $postId, int $actorId): array
    {
        $rows = SocialGroupPostReaction::where('post_id', $postId)->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->reaction] = ($counts[$row->reaction] ?? 0) + 1;
        }

        $actorReaction = $rows->firstWhere('user_id', $actorId)?->reaction;

        return [
            'reactions'     => $counts,
            'actorReaction' => $actorReaction ?? null,
        ];
    }
}
