<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Flarum\Http\RequestUtil;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class FetchLinkPreviewController implements RequestHandlerInterface
{
    private const CACHE_TTL   = 3600;  // 1 hour
    private const MAX_BYTES   = 524288; // 512 KB — enough to find <head> OG tags
    private const USER_AGENT  = 'flarum-social-groups/1.0 (+https://github.com/ernestdefoe/social-groups)';

    public function __construct(
        private CacheRepository $cache,
        private LoggerInterface $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $url = trim((string) ($request->getQueryParams()['url'] ?? ''));

        $url = filter_var($url, FILTER_VALIDATE_URL);
        if (! $url || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return new JsonResponse(['error' => 'Invalid URL.'], 422);
        }

        $cacheKey = 'sg-link-preview:' . md5($url);

        if ($cached = $this->cache->get($cacheKey)) {
            return new JsonResponse($cached);
        }

        try {
            $client   = new Client(['timeout' => 8, 'connect_timeout' => 5, 'allow_redirects' => ['max' => 5]]);
            $response = $client->get($url, [
                'stream'  => true,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                ],
            ]);

            $html = $response->getBody()->read(self::MAX_BYTES);
        } catch (RequestException $e) {
            return new JsonResponse(['error' => 'Could not fetch URL.'], 502);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Unexpected error.'], 500);
        }

        $preview = $this->parseOgData($html, $url);

        $this->cache->put($cacheKey, $preview, self::CACHE_TTL);

        return new JsonResponse($preview);
    }

    private function parseOgData(string $html, string $requestUrl): array
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $og    = [];

        // Collect all <meta> tags
        foreach ($xpath->query('//head/meta') as $meta) {
            $prop    = $meta->getAttribute('property') ?: $meta->getAttribute('name');
            $content = $meta->getAttribute('content');
            if ($prop && $content) {
                $og[$prop] = $content;
            }
        }

        // Collect <title> fallback
        $titleEl   = $xpath->query('//head/title')->item(0);
        $pageTitle = $titleEl ? trim($titleEl->textContent) : '';

        $title       = $this->clean($og['og:title']       ?? $og['twitter:title']       ?? $pageTitle,       150);
        $description = $this->clean($og['og:description'] ?? $og['twitter:description'] ?? $og['description'] ?? '', 300);
        $siteName    = $this->clean($og['og:site_name']   ?? parse_url($requestUrl, PHP_URL_HOST) ?? '', 80);
        $canonicalUrl = $og['og:url'] ?? $requestUrl;

        // Resolve image URL
        $image = $og['og:image'] ?? $og['og:image:url'] ?? $og['twitter:image'] ?? null;
        if ($image && ! filter_var($image, FILTER_VALIDATE_URL)) {
            $parts = parse_url($requestUrl);
            $image = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
                . (str_starts_with($image, '/') ? '' : '/') . ltrim($image, '/');
        }
        if ($image && ! filter_var($image, FILTER_VALIDATE_URL)) {
            $image = null;
        }

        return [
            'url'         => filter_var($canonicalUrl, FILTER_VALIDATE_URL) ? $canonicalUrl : $requestUrl,
            'title'       => $title,
            'description' => $description,
            'image'       => $image,
            'siteName'    => $siteName,
        ];
    }

    private function clean(string $value, int $maxLen): string
    {
        return mb_substr(trim(strip_tags($value)), 0, $maxLen);
    }
}
