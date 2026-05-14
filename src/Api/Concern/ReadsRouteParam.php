<?php

namespace Ernestdefoe\SocialGroups\Api\Concern;

use Psr\Http\Message\ServerRequestInterface;

trait ReadsRouteParam
{
    /**
     * Read a named route parameter, falling back to URI path extraction when
     * Flarum 2 does not inject route params as request attributes.
     *
     * @param string $routeTemplate  e.g. '/sg-analytics/{groupId}'
     */
    private function routeParam(ServerRequestInterface $request, string $name, string $routeTemplate): ?string
    {
        $val = $request->getAttribute($name)
            ?? ($request->getQueryParams()[$name] ?? null);

        if ($val !== null && $val !== '') {
            return (string) $val;
        }

        $regex = '#' . preg_replace('#\\\{(\w+)\\\}#', '(?P<$1>[^/]+)',
                preg_quote(ltrim($routeTemplate, '/'), '#')) . '$#';

        if (preg_match($regex, ltrim($request->getUri()->getPath(), '/'), $m)) {
            return $m[$name] ?? null;
        }

        return null;
    }
}
