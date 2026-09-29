<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedMobileApi\Models\DeviceToken;

class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'string', 'in:ios,android'],
            'token' => ['required', 'string', 'max:512'],
        ]);

        // Voorkom token-hijacking: een push-token hoort bij precies één gebruiker.
        // Als hetzelfde device nu als een andere gebruiker inlogt, verhuist het token mee.
        DeviceToken::where('token', $data['token'])
            ->where('user_id', '!=', $request->user()->id)
            ->delete();

        $device = DeviceToken::updateOrCreate(
            ['user_id' => $request->user()->id, 'token' => $data['token']],
            [
                'platform' => $data['platform'],
                // Koppel aan de huidige Sanctum-sessie zodat de dispatch stopt zodra
                // die sessie (uitloggen) is ingetrokken.
                'access_token_id' => self::currentAccessTokenId($request),
            ],
        );

        return response()->json([
            'id' => $device->id,
            'platform' => $device->platform,
        ], 201);
    }

    /**
     * Deregistreer het push-token van dit toestel (bij uitloggen / account
     * verwijderen). Verwijdert alleen de rij van de ingelogde gebruiker; een
     * ander account op hetzelfde toestel blijft ongemoeid.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:512'],
        ]);

        $query = DeviceToken::where('user_id', $request->user()->id);
        if (! empty($data['token'])) {
            $query->where('token', $data['token']);
        } else {
            // Zonder token: ruim de rij(en) van de huidige sessie op.
            $tokenId = self::currentAccessTokenId($request);
            $tokenId === null ? $query->whereRaw('1 = 0') : $query->where('access_token_id', $tokenId);
        }
        $deleted = $query->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Id van de huidige Sanctum-access-token, of null bij een transient token
     * (bv. in tests met Sanctum::actingAs zonder echt token).
     */
    private static function currentAccessTokenId(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();

        return ($token && method_exists($token, 'getKey')) ? (int) $token->getKey() : null;
    }
}
