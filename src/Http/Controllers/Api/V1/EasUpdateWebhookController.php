<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedMobileApi\Models\DeviceToken;
use Dashed\DashedMobileApi\Support\ExpoPushService;

/**
 * Ontvangt de EAS Update-webhook: EAS roept dit endpoint aan zodra een OTA
 * gepubliceerd is. We verifiëren de HMAC-handtekening (`expo-signature`) met het
 * webhook-secret en sturen dan één push naar alle geregistreerde toestellen.
 * De app (OtaUpdater) pakt de update daarop meteen op en toont "tik om te
 * herstarten"; tikken op de push brengt de app sowieso naar voren, wat de
 * update-check ook triggert.
 *
 * Bewust onbeveiligd door Sanctum (EAS heeft geen sessie): de handtekening is
 * de authenticatie. Het endpoint stuurt nooit gevoelige data terug.
 */
class EasUpdateWebhookController extends Controller
{
    public function handle(Request $request, ExpoPushService $push): JsonResponse
    {
        $secret = (string) (config('dashed-mobile-api.eas_webhook.secret') ?? '');
        if ($secret === '') {
            // Niet geconfigureerd: niets doen, maar 200 zodat EAS niet eindeloos
            // blijft herproberen.
            return response()->json(['skipped' => 'not_configured']);
        }

        if (! $this->signatureValid($request, $secret)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = $request->json()->all();

        // Alleen pushen voor de ingestelde branch (meestal 'production'). Komt er
        // geen branch mee, dan pushen we (defensief) toch.
        $branchFilter = (string) (config('dashed-mobile-api.eas_webhook.branch') ?? '');
        $branch = (string) ($payload['branch'] ?? $payload['updateBranch'] ?? '');
        if ($branchFilter !== '' && $branch !== '' && $branch !== $branchFilter) {
            return response()->json(['skipped' => 'other_branch']);
        }

        // Eén `eas update` publiceert iOS én Android; EAS kan daardoor twee keer
        // afvuren voor dezelfde publish. Dedupliceer op de update-group zodat de
        // gebruiker maar één melding krijgt.
        $group = (string) ($payload['group'] ?? $payload['id'] ?? '');
        if ($group !== '' && ! Cache::add('eas-update-push:' . $group, true, now()->addMinutes(10))) {
            return response()->json(['skipped' => 'duplicate']);
        }

        $tokens = DeviceToken::query()->pluck('token')->all();
        $push->sendToTokens(
            $tokens,
            'Nieuwe versie beschikbaar',
            'Open de app om bij te werken.',
            ['type' => 'ota'],
            'default',
            'default',
        );

        return response()->json(['pushed' => count($tokens)]);
    }

    /**
     * Verifieert de EAS-handtekening: HMAC-SHA1 van de ruwe body met het secret.
     * EAS stuurt die als header `expo-signature` in de vorm "sha1=<hex>"; we
     * accepteren ook een kale hex. `hash_equals` voorkomt timing-aanvallen.
     */
    private function signatureValid(Request $request, string $secret): bool
    {
        $header = (string) $request->header('expo-signature', '');
        if ($header === '') {
            return false;
        }
        if (str_contains($header, '=')) {
            [, $header] = explode('=', $header, 2);
        }
        $expected = hash_hmac('sha1', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }
}
