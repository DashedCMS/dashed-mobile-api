<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedMobileApi\MobileApiRegistry;

class DashboardController extends Controller
{
    public function index(Request $request, MobileApiRegistry $registry): JsonResponse
    {
        $site = (string) Sites::getActive();
        $stats = [];

        foreach ($registry->dashboardContributors() as $contributor) {
            $stats = array_merge($stats, $contributor($site));
        }

        return response()->json($stats);
    }
}
