<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AppVersionController extends Controller
{
    /**
     * Geeft de versie-eisen voor de mobiele app terug zodat die kan bepalen of
     * er een update-prompt (banner) of een verplichte update (modal) nodig is.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'min_supported' => config('dashed-mobile-api.app_version.min_supported', '0.0.0'),
            'latest' => config('dashed-mobile-api.app_version.latest', '0.0.0'),
            'force_update' => (bool) config('dashed-mobile-api.app_version.force_update', false),
            'message' => config('dashed-mobile-api.app_version.message'),
        ]);
    }
}
