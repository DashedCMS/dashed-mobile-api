<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedMobileApi\Support\SearchAccess;
use Dashed\DashedMobileApi\Support\AbilityResolver;

/**
 * Globaal zoeken: aggregeert resultaten van alle geregistreerde search-providers
 * (orders, producten, klanten, gesprekken). De providers worden door de
 * afzonderlijke packages bijgedragen, zodat hier geen harde dependency op
 * ecommerce/livechat ontstaat. Elke provider levert max. 5 items; een falende
 * provider mag het hele zoeken niet breken.
 */
class SearchController extends Controller
{
    /** Minimale lengte van de zoekterm voordat we providers aanroepen. */
    private const MIN_LENGTH = 2;

    public function index(Request $request, MobileApiRegistry $registry, AbilityResolver $resolver): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return response()->json(['results' => []]);
        }

        $site = (string) Sites::getActive();
        $access = new SearchAccess($request->user(), $site, $resolver);
        $results = [];

        foreach ($registry->searchProviderEntries() as $entry) {
            $provider = $entry['provider'];
            $ability = $entry['ability'];

            // Met een eigen ability roepen we de provider niet eens aan.
            if ($ability !== null && ! $access->has($ability)) {
                continue;
            }

            try {
                $items = $provider($site, $query);
            } catch (\Throwable $e) {
                // Een falende provider (ontbrekende module, query-fout, ...) mag de
                // hele zoekopdracht niet doen omvallen — sla 'm stil over.
                continue;
            }

            if (! is_array($items)) {
                continue;
            }

            foreach (array_slice(array_values($items), 0, 5) as $item) {
                if (! is_array($item) || empty($item['type']) || empty($item['route'])) {
                    continue;
                }

                // Zonder eigen ability beslist het type van het item.
                if ($ability === null && ! $access->allowsType((string) $item['type'])) {
                    continue;
                }

                $results[] = [
                    'type' => (string) $item['type'],
                    'id' => $item['id'] ?? null,
                    'title' => (string) ($item['title'] ?? ''),
                    'subtitle' => isset($item['subtitle']) ? (string) $item['subtitle'] : null,
                    'route' => (string) $item['route'],
                ];
            }
        }

        return response()->json(['results' => $results]);
    }
}
