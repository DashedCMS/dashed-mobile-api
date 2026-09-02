<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Dashed\DashedCore\Models\User;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedCore\Models\Customsetting;
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

        $user = $request->user();
        if (! in_array($this->pageAbility($meta), $this->abilities->abilitiesFor($user), true)) {
            abort(403);
        }

        // De magic-link logt een volwaardige CMS-sessie in en zou daarmee de
        // tweestapsverificatie van het paneel omzeilen. Een app-sessie die bij
        // het inloggen een tweede factor bewees (mfa.passed) mag wél, zolang
        // die verificatie nog vers is volgens dezelfde termijn als het paneel
        // (MfaFreshness); anders geven we bij MFA geen link uit.
        $mfaPassed = $this->tokenMfaIsFresh($request);
        if ($this->mfaApplies($user) && ! $mfaPassed) {
            return response()->json([
                'success' => false,
                'message' => 'Dit account gebruikt tweestapsverificatie; log opnieuw in (met code) in de app of open het CMS in de browser.',
            ], 422);
        }

        $token = Str::random(48);
        Cache::put('app-page-open:' . $token, [
            'user_id' => $user->id,
            'key' => $key,
            // Site-context vastleggen: de web-route heeft geen X-Site-Id, dus
            // zonder dit zou de module-URL op de verkeerde site kunnen uitkomen.
            'site_id' => (string) Sites::getActive(),
            // Zodat de web-route de MFA-versheid van het paneel kan stempelen —
            // anders stuurt EnsureMfaIsFresh de sessie alsnog naar een 2FA-scherm.
            'mfa_passed' => $mfaPassed,
        ], self::TOKEN_TTL_SECONDS);

        return response()->json(['url' => url('/mobile-app-page/' . $token)]);
    }

    /**
     * Web-route: verzilver de magic-link — éénmalig (atomair via een lock),
     * log de web-sessie in (zonder remember) en stuur door naar de module-pagina.
     */
    public function visit(string $token): RedirectResponse
    {
        // Atomair consumeren: Cache::pull is get()+forget() en dus raceable;
        // met een lock kan een tweede gelijktijdige GET de link niet ook
        // verzilveren. Validatie vóór forget(), zodat een kapotte registratie
        // de link niet nutteloos opbrandt.
        $payload = Cache::lock('app-page-open-lock:' . $token, 5)->block(3, function () use ($token) {
            $payload = Cache::get('app-page-open:' . $token);
            if (is_array($payload)) {
                Cache::forget('app-page-open:' . $token);
            }

            return $payload;
        });
        abort_if(! is_array($payload), 403, 'Deze link is verlopen of al gebruikt.');

        $meta = $this->registry->appPage((string) ($payload['key'] ?? ''));
        $user = User::find($payload['user_id'] ?? null);
        abort_if($meta === null || $user === null, 403);

        // Zelfde site-context als waarin de link is aangevraagd (multi-site).
        if (! empty($payload['site_id'])) {
            config(['dashed-core.dashed_site_id' => (string) $payload['site_id']]);
        }

        $url = (string) value($meta['url'] ?? '');
        abort_if($url === '', 422, 'Deze module-pagina heeft geen web-adres.');

        // Alleen doorsturen binnen de eigen host: een module-URL mag nooit een
        // open redirect worden op het request dat net een sessie aanmaakte.
        $host = parse_url($url, PHP_URL_HOST);
        abort_if($host !== null && $host !== parse_url(url('/'), PHP_URL_HOST), 422, 'Deze module-pagina verwijst buiten de eigen omgeving.');

        Auth::guard('web')->login($user);

        // MFA-versheid van het paneel stempelen wanneer de app-sessie de tweede
        // factor bewees: zonder stempel zou EnsureMfaIsFresh de net ingelogde
        // sessie direct naar het 2FA-herverificatiescherm sturen.
        if (! empty($payload['mfa_passed']) && class_exists(\Dashed\DashedCore\Classes\MfaFreshness::class)) {
            \Dashed\DashedCore\Classes\MfaFreshness::stamp();
        }

        return redirect()->away($url);
    }

    /**
     * Heeft dit token een 2FA-verificatie die nog vers is volgens de
     * paneel-termijn (mfa_reverify_hours; 0 = verloopt nooit)?
     */
    private function tokenMfaIsFresh(Request $request): bool
    {
        $token = $request->user()->currentAccessToken();
        if (! $token || ! $token->can('mfa.passed')) {
            return false;
        }

        $hours = class_exists(\Dashed\DashedCore\Classes\MfaFreshness::class)
            ? \Dashed\DashedCore\Classes\MfaFreshness::hours()
            : 0;
        if ($hours <= 0) {
            return true;
        }

        foreach (($token->abilities ?? []) as $ability) {
            if (str_starts_with((string) $ability, 'mfa.at:')) {
                $at = (int) substr((string) $ability, strlen('mfa.at:'));

                return $at >= now()->subHours($hours)->timestamp;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $meta */
    private function pageAbility(array $meta): string
    {
        return (string) ($meta['ability'] ?? 'dashboard.read');
    }

    /**
     * Geldt er (verplichte of ingestelde) tweestapsverificatie voor deze
     * gebruiker? Zelfde bronnen als het paneel: providers kunnen daar per site
     * uitgezet zijn, en mfaIsRequired() is o.a. uit in local-omgevingen.
     */
    private function mfaApplies(User $user): bool
    {
        if (method_exists(cms(), 'mfaIsRequired') && cms()->mfaIsRequired()) {
            return true;
        }

        return class_exists(\Dashed\DashedCore\Classes\MfaFreshness::class)
            && \Dashed\DashedCore\Classes\MfaFreshness::enabledProviders($user) !== [];
    }
}
