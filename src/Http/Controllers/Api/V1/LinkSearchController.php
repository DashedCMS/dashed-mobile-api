<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;

/**
 * Deelbare links zoeken: producten, categorieën en CMS-pagina's van de actieve
 * site met hun publieke URL, zodat een medewerker in de livechat snel een linkje
 * kan opzoeken en in het bericht kan plakken. In tegenstelling tot /search (dat
 * app-routes teruggeeft voor navigatie) levert dit publieke, deelbare URL's.
 *
 * Elke bron wordt los en defensief bevraagd: een ontbrekende module (bijv. geen
 * dashed-pages) of een query-fout mag het zoeken niet breken, en alleen items met
 * een bruikbare URL komen terug.
 */
class LinkSearchController extends Controller
{
    /** Minimale lengte van de zoekterm voordat we gaan zoeken. */
    private const MIN_LENGTH = 2;

    /** Max. resultaten per bron. */
    private const PER_SOURCE = 8;

    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return response()->json(['results' => []]);
        }

        $site = (string) Sites::getActive();
        $locale = app()->getLocale();
        $results = [];

        // Producten (ec-core): zelfde site- + zoek-scope als de globale zoek-provider.
        $this->source($results, function () use ($site, $query, $locale): array {
            $class = \Dashed\DashedEcommerceCore\Models\Product::class;
            if (! class_exists($class)) {
                return [];
            }

            return $class::thisSite($site)
                ->search($query)
                ->orderBy('id')
                ->limit(self::PER_SOURCE)
                ->get()
                ->map(fn ($product): array => [
                    'type' => 'product',
                    'title' => (string) $product->name,
                    'subtitle' => $product->sku ? (string) $product->sku : null,
                    'url' => $this->publicUrl($product, $site, $locale),
                ])->all();
        });

        // Categorieën (ec-core).
        $this->source($results, function () use ($site, $query, $locale): array {
            $class = \Dashed\DashedEcommerceCore\Models\ProductCategory::class;
            if (! class_exists($class)) {
                return [];
            }

            return $this->byName($class, $query)
                ->map(fn ($category): array => [
                    'type' => 'category',
                    'title' => (string) $category->name,
                    'subtitle' => null,
                    'url' => $this->publicUrl($category, $site, $locale),
                ])->all();
        });

        // CMS-pagina's (dashed-pages, optioneel).
        $this->source($results, function () use ($site, $query, $locale): array {
            $class = 'Dashed\\DashedPages\\Models\\Page';
            if (! class_exists($class)) {
                return [];
            }

            return $this->byName($class, $query)
                ->map(fn ($page): array => [
                    'type' => 'page',
                    'title' => (string) $page->name,
                    'subtitle' => null,
                    'url' => $this->publicUrl($page, $site, $locale),
                ])->all();
        });

        // Alleen items met een bruikbare publieke URL.
        $results = array_values(array_filter(
            $results,
            static fn (array $item): bool => ! empty($item['url']),
        ));

        return response()->json(['results' => $results]);
    }

    /**
     * Bevraag een model op de (translatable) naam-kolom, publicShowable waar
     * beschikbaar. `name` is JSON ({"nl": "..."}); een LIKE matcht de vertaalde
     * waarde binnen die JSON, wat voor een snelle link-zoekactie volstaat.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function byName(string $class, string $query): \Illuminate\Support\Collection
    {
        $builder = $class::query();

        if (method_exists($class, 'scopePublicShowable')) {
            $builder->publicShowable();
        }

        return $builder
            ->where('name', 'like', '%' . $query . '%')
            ->limit(self::PER_SOURCE)
            ->get();
    }

    /**
     * Voeg de items van één bron toe; een ontbrekende module of query-fout mag het
     * hele zoeken niet laten omvallen.
     *
     * @param array<int, array<string, mixed>> $results
     */
    private function source(array &$results, callable $provider): void
    {
        try {
            foreach ((array) $provider() as $item) {
                if (is_array($item) && ! empty($item['type'])) {
                    $results[] = $item;
                }
            }
        } catch (\Throwable $e) {
            // Stil overslaan.
        }
    }

    /** Volledige, deelbare URL van een model op de actieve site (of null). */
    private function publicUrl(object $model, string $site, string $locale): ?string
    {
        try {
            if (! method_exists($model, 'getUrl')) {
                return null;
            }

            $path = $model->getUrl($locale);

            if (! is_string($path) || $path === '') {
                return null;
            }

            $url = Sites::url($path, $site);

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
