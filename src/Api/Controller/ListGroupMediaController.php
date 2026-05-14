<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListGroupMediaController implements RequestHandlerInterface
{
    private const PER_PAGE = 24;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor   = RequestUtil::getActor($request);
            $params  = $request->getQueryParams();
            $groupId = $request->getAttribute('groupId');
            $page    = max(1, (int) ($params['page'] ?? 1));
            $offset  = ($page - 1) * self::PER_PAGE;

            $group = SocialGroup::findOrFail($groupId);

            if ($group->is_private) {
                $actor->assertRegistered();
                $isMember = $group->members()->where('user_id', $actor->id)->exists();
                if (! $isMember && ! $actor->isAdmin()) {
                    return new JsonResponse(['error' => 'This group is private.'], 403);
                }
            }

            $total = SocialGroupPost::where('group_id', $groupId)
                ->where('content_parsed', 'like', '%<img%')
                ->count();

            $posts = SocialGroupPost::where('group_id', $groupId)
                ->where('content_parsed', 'like', '%<img%')
                ->with('user')
                ->orderByDesc('created_at')
                ->skip($offset)
                ->take(self::PER_PAGE)
                ->get();

            $items = [];
            foreach ($posts as $post) {
                $urls = $this->extractImageUrls($post->content_parsed ?? '');
                foreach ($urls as $url) {
                    $items[] = [
                        'url'          => $url,
                        'postId'       => $post->id,
                        'discussionId' => $post->discussion_id,
                        'createdAt'    => $post->created_at?->toIso8601String(),
                        'user'         => $post->user ? [
                            'id'          => $post->user->id,
                            'displayName' => $post->user->display_name,
                            'avatarUrl'   => $post->user->avatar_url,
                        ] : null,
                    ];
                }
            }

            return new JsonResponse([
                'data'  => $items,
                'total' => $total,
                'page'  => $page,
                'pages' => (int) ceil($total / self::PER_PAGE),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Group not found.'], 404);
        } catch (\Throwable $e) {
            resolve('log')->error('[social-groups] ListGroupMediaController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    private function extractImageUrls(string $html): array
    {
        if ($html === '') return [];

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $urls = [];
        foreach ($doc->getElementsByTagName('img') as $img) {
            /** @var \DOMElement $img */
            $src = $img->getAttribute('src');
            if ($src !== '' && ! str_starts_with($src, 'data:')) {
                $urls[] = $src;
            }
        }

        return $urls;
    }
}
