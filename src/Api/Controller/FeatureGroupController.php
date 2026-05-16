<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class FeatureGroupController implements RequestHandlerInterface
{
    public function __construct(private LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertRegistered();

            if (! $actor->isAdmin()) {
                return new JsonResponse(['error' => 'Only admins can feature groups.'], 403);
            }

            $id    = $request->getAttribute('id');
            if (! $id) {
            preg_match('#/social-groups/(\d+)#', $request->getUri()->getPath(), $m);
            $id = $m[1] ?? null;
            }
            $group = SocialGroup::findOrFail($id);

            $group->is_featured = ! $group->is_featured;
            $group->save();

            return new JsonResponse(['isFeatured' => (bool) $group->is_featured]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Group not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] FeatureGroupController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
