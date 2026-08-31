<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\RedirectResponse;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedMobileApi\Support\AbilityResolver;

/**
 * App-pagina's van modules zonder eigen app-schermen: het menu in de app toont
 * de geregistreerde pagina's (MobileApiRegistry::registerAppPage) en opent ze
 * via een éénmalige, kortlevende magic-link die de web-sessie inlogt en
 * doorstuurt naar de admin-pagina van de module.
 */
class AppPageController extends Controller
{
    private const TOKEN_TTL_SECONDS = 60;

    public function __construct(
        private MobileApiRegistry $registry,
        private AbilityResolver $abilities,
    ) {
    }

    /** Alle pagina's waarvoor de ingelogde gebruiker het recht heeft. */
    public function index(Request $request): JsonResponse
    {
        $abilities = $this->abilities->abilitiesFor($request->user());

        $data = [];
        foreach ($this->registry->appPages() as $key => $meta) {
            if (! in_array($this->pageAbility($meta), $abilities, true)) {
                continue;
            }
            $data[] = [
                'key' => $key,
                'title' => (string) ($meta['title'] ?? $key),
                'icon' => (string) ($meta['icon'] ?? 'apps-outline'),
                'group' => (string) ($meta['group'] ?? 'Modules'),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** Geef een éénmalige magic-link (60 s geldig) voor deze pagina. */
    public function open(Request $request, string $key): JsonResponse
    {
        $meta = $this->registry->appPage($key);
        abort_if($meta === null, 404);

        if (! in_array($this->pageAbility($meta), $this->abilities->abilitiesFor($request->user()), true)) {
            abort(403);
        }

        $token = Str::random(48);
        Cache::put('app-page-open:' . $token, [
            'user_id' => $request->user()->id,
            'key' => $key,
        ], self::TOKEN_TTL_SECONDS);

        return response()->json(['url' => url('/mobile-app-page/' . $token)]);
    }

    /**
     * Web-route: verzilver de magic-link — éénmalig (Cache::pull), log de
     * web-sessie in (zonder remember) en stuur door naar de module-pagina.
     */
    public function visit(string $token): RedirectResponse
    {
        $payload = Cache::pull('app-page-open:' . $token);
        abort_if(! is_array($payload), 403, 'Deze link is verlopen of al gebruikt.');

        $meta = $this->registry->appPage((string) ($payload['key'] ?? ''));
        $user = User::find($payload['user_id'] ?? null);
        abort_if($meta === null || $user === null, 403);

        $url = (string) value($meta['url'] ?? '');
        abort_if($url === '', 422, 'Deze module-pagina heeft geen web-adres.');

        Auth::guard('web')->login($user);

        return redirect()->away($url);
    }

    /** @param array<string, mixed> $meta */
    private function pageAbility(array $meta): string
    {
        return (string) ($meta['ability'] ?? 'dashboard.read');
    }
}
