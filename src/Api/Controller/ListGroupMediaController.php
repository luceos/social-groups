<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class ListGroupMediaController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    private const PER_PAGE = 24;

    public function __construct(
        private Formatter $formatter,
        private LoggerInterface $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor   = RequestUtil::getActor($request);
            $params  = $request->getQueryParams();
            $groupId = (int) ($this->routeParam($request, 'groupId', '/sg-media/{groupId}') ?? 0);
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

            // Only surface images that were uploaded into gallery archive discussions
            $galleryDiscussionIds = \Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion
                ::where('group_id', $groupId)
                ->where('is_gallery', true)
                ->pluck('id')
                ->all();

            // All posts in gallery discussions are image posts — no content filter needed.
            // If no gallery discussion exists yet the result set is intentionally empty.
            $mediaQuery = fn () => SocialGroupPost::where('group_id', $groupId)
                ->whereIn('discussion_id', $galleryDiscussionIds);

            $total = $mediaQuery()->count();

            $posts = $mediaQuery()
                ->with('user')
                ->orderByDesc('created_at')
                ->skip($offset)
                ->take(self::PER_PAGE)
                ->get();

            $items = [];
            foreach ($posts as $post) {
                // New gallery posts store direct image URLs in `content` (no formatter).
                // Old posts (if any) may have gone through the formatter and have content_parsed.
                try {
                    if ($post->content_parsed !== null) {
                        $rendered = $this->formatter->render($post->content_parsed);
                        $urls     = $this->extractImageUrls($rendered);
                    } else {
                        $urls = $this->extractRawUrls($post->content ?? '');
                    }
                } catch (\Throwable) {
                    $urls = $this->extractRawUrls($post->content ?? '');
                }

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
            $this->log->error('[social-groups] ListGroupMediaController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    /**
     * Extract https?:// URLs from raw gallery post content.
     * Excludes brackets/quotes so bbcode wrappers like [upl-file]URL[/upl-file]
     * don't bleed ] or [/tag] onto the matched URL.
     */
    private function extractRawUrls(string $content): array
    {
        preg_match_all('#https?://[^\s\[\]<>"\']+#i', $content, $matches);
        // Strip any trailing punctuation that may still be attached
        $urls = array_map(fn ($u) => rtrim($u, '.,;:!?)}"\''), $matches[0]);
        return array_values(array_unique(array_filter($urls)));
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
