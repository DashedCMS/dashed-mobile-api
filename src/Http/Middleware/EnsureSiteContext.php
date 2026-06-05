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
        $sites = Sites::getSites();
        $validIds = array_map(
            static fn (array $site): string => (string) $site['id'],
            $sites,
        );

        $headerSite = (string) $request->header('X-Site-Id', '');

        if ($headerSite !== '' && in_array($headerSite, $validIds, true)) {
            // 1. Expliciete, geldige site-keuze respecteren (multi-site).
            $siteId = $headerSite;
        } else {
            // 2. Anders: oplossen via het domein van het verzoek...
            //    ...en anders terugvallen op de standaard-/eerste site.
            $siteId = $this->resolveByHost($request->getHost(), $sites)
                ?? (string) (Sites::getFirstSite()['id'] ?? '');
        }

        if ($siteId === '') {
            return response()->json(['message' => 'Geen site geconfigureerd.'], 400);
        }

        // Single switch every existing thisSite()/publicShowable()/unhandled()
        // scope reads through via Sites::getActive().
        config(['dashed-core.dashed_site_id' => $siteId]);
        $request->attributes->set('mobile_site_id', $siteId);

        return $next($request);
    }

    /**
     * Koppel het verzoek-domein aan een site die datzelfde domein declareert
     * (via een url/domain/host-veld). Geeft null als er geen match is.
     */
    private function resolveByHost(string $host, array $sites): ?string
    {
        if ($host === '') {
            return null;
        }

        // 1. Site die dit domein expliciet declareert (multi-site: 'url'/'domain'/'host').
        foreach ($sites as $site) {
            foreach (['url', 'domain', 'host'] as $key) {
                $value = $site[$key] ?? null;
                if (is_string($value) && $value !== '' && str_contains($value, $host)) {
                    return (string) $site['id'];
                }
            }
        }

        // 2. Single-site: het verzoek-domein is de app-URL → de actieve/eerste site.
        if (str_contains((string) config('app.url'), $host)) {
            $id = Sites::getFirstSite()['id'] ?? null;

            return $id !== null ? (string) $id : null;
        }

        return null;
    }
}
