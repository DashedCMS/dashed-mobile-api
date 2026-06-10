<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Dashed\DashedMobileApi\Support\AbilityResolver;

/**
 * Autoriseert op basis van de ACTUELE rol-rechten van de gebruiker
 * (via AbilityResolver), niet op de in het Sanctum-token gebakken abilities.
 *
 * Zo werken nieuw toegekende rechten meteen — zonder dat de gebruiker opnieuw
 * hoeft in te loggen of z'n token te verversen. Semantiek gelijk aan Sanctum's
 * CheckForAnyAbility (any-of): toegang zodra de gebruiker één van de gevraagde
 * rechten heeft.
 */
class EnsureCurrentAbility
{
    public function __construct(private AbilityResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $current = $this->resolver->abilitiesFor($user);

        if (in_array('*', $current, true)) {
            return $next($request);
        }

        foreach ($abilities as $ability) {
            if (in_array($ability, $current, true)) {
                return $next($request);
            }
        }

        abort(403, 'Onvoldoende rechten voor deze actie.');
    }
}
