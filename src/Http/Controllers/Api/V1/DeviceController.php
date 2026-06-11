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
            ['platform' => $data['platform']],
        );

        return response()->json([
            'id' => $device->id,
            'platform' => $device->platform,
        ], 201);
    }
}
