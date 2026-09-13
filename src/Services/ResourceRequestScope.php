<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

final readonly class ResourceRequestScope
{
    /** @param array<string, mixed> $resource */
    public static function matches(Request $request, array $resource): bool
    {
        if (isset($resource['resources'])) {
            foreach ($resource['resources'] as $scope) {
                if (self::matches($request, $scope)) {
                    return true;
                }
            }
            return false;
        }
        $path = $request->getUri()->getPath();
        $basePath = rtrim((string) $request->getAttribute(RouteContext::BASE_PATH, ''), '/');
        if (isset($resource['fileId'])) {
            return in_array($request->getMethod(), ['GET', 'HEAD'], true)
                && $path === $basePath . '/files/serve/' . rawurlencode($resource['fileId']);
        }

        $query = $request->getQueryParams();
        return $request->getMethod() === 'GET' && $path === $basePath . '/brain/stream'
            && isset($resource['threadId'], $resource['sessionId'])
            && ($query['threadId'] ?? null) === $resource['threadId']
            && ($query['sessionId'] ?? null) === $resource['sessionId'];
    }
}
