<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Dashed\DashedCore\Classes\Sites;
use Symfony\Component\HttpFoundation\Response;

class EnsureSiteContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $siteId = $request->header('X-Site-Id');

        if (! $siteId) {
            return response()->json(['message' => 'X-Site-Id header is verplicht.'], 400);
        }

        $validIds = array_map(
            static fn (array $site): string => (string) $site['id'],
            Sites::getSites(),
        );

        if (! in_array((string) $siteId, $validIds, true)) {
            return response()->json(['message' => 'Onbekende site.'], 400);
        }

        // Single switch every existing thisSite()/publicShowable()/unhandled()
        // scope reads through via Sites::getActive().
        config(['dashed-core.dashed_site_id' => (string) $siteId]);
        $request->attributes->set('mobile_site_id', (string) $siteId);

        return $next($request);
    }
}
