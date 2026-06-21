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
                $isMember = $group->activeMembership($actor->id)->exists();
                if (! $isMember && ! $actor->isAdmin()) {
                    return new JsonResponse(['error' => 'This group is private.'], 403);
                }
            }

            // Two sources feed the gallery:
            //   1. Posts in the hidden "__gallery__" archive discussion — the
            //      Media tab's own upload button stores raw image URLs there.
            //   2. Ordinary feed posts that embed an uploaded image — so a
            //      photo shared in the feed also shows up under Media (was the
            //      reported "fof/upload images don't appear in the Media tab").
            $galleryDiscussionIds = \Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion
                ::where('group_id', $groupId)
                ->where('is_gallery', true)
                ->pluck('id')
                ->all();
            $gallerySet = array_flip($galleryDiscussionIds);

            $mediaQuery = fn () => SocialGroupPost::where('group_id', $groupId)
                ->where(function ($q) use ($galleryDiscussionIds) {
                    if (! empty($galleryDiscussionIds)) {
                        $q->whereIn('discussion_id', $galleryDiscussionIds);
                    }
                    // Feed posts that contain an uploaded/embedded image. The
                    // markers cover fof/upload previews, markdown/bbcode image
                    // syntax, and raw <img> — narrowing the scan so we don't
                    // render every post in the group through the formatter.
                    $q->orWhere(function ($w) {
                        $w->where('content', 'like', '%upl-image%')
                          ->orWhere('content', 'like', '%![%')
                          ->orWhere('content', 'like', '%[img%')
                          ->orWhere('content', 'like', '%<img%');
                    });
                });

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
                        // Gallery-archive items live in the hidden "__gallery__"
                        // discussion, which isn't a navigable thread — the
                        // lightbox uses this to suppress its "View post" link
                        // (was the reported broken edit/reply on that thread).
                        'isGallery'    => isset($gallerySet[$post->discussion_id]),
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
            $src   = $img->getAttribute('src');
            $class = $img->getAttribute('class');
            // Skip inline emoji (Flarum renders them as <img class="emoji">),
            // data: URIs, and any other non-photo glyph — they aren't gallery
            // media and would otherwise flood the grid with tiny icons.
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }
            if (str_contains($class, 'emoji') || str_contains($src, '/emoji/') || str_contains($src, 'twemoji')) {
                continue;
            }
            $urls[] = $src;
        }

        return array_values(array_unique($urls));
    }
}
