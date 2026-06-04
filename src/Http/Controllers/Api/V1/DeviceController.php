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

        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform']],
        );

        return response()->json([
            'id' => $device->id,
            'platform' => $device->platform,
        ], 201);
    }
}
