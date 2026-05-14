<?php

namespace Ernestdefoe\SocialGroups\Api\Concern;

trait SanitizesLinkPreview
{
    private function sanitizeLinkPreview(array $raw): ?array
    {
        $url = filter_var($raw['url'] ?? '', FILTER_VALIDATE_URL);
        if (! $url || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        $image = filter_var($raw['image'] ?? '', FILTER_VALIDATE_URL);
        if (! $image) {
            // Accept absolute-path relative URLs like /img/foo.jpg
            $raw_image = $raw['image'] ?? '';
            $image = (str_starts_with($raw_image, '/') && ! str_starts_with($raw_image, '//'))
                ? $raw_image
                : null;
        }

        return [
            'url'         => $url,
            'title'       => mb_substr(strip_tags($raw['title']       ?? ''), 0, 200),
            'description' => mb_substr(strip_tags($raw['description'] ?? ''), 0, 500),
            'image'       => $image ?: null,
            'siteName'    => mb_substr(strip_tags($raw['siteName']    ?? ''), 0, 100),
        ];
    }
}
