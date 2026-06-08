<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedMobileApi\Support\DashboardPeriod;

class DashboardController extends Controller
{
    public function index(Request $request, MobileApiRegistry $registry): JsonResponse
    {
        $site = (string) Sites::getActive();
        $period = DashboardPeriod::fromRequest($request->query('period'), $request->query('anchor'));

        $stats = ['period' => $period->key];
        foreach ($registry->dashboardContributors() as $contributor) {
            $stats = array_merge($stats, $contributor($site, $period));
        }

        // Vergelijking met de vorige periode (vandaag↔gister, week↔vorige week, …).
        // Alleen voor omzet/bestellingen/gem. orderwaarde — "onafgehandeld" is een
        // totaal en livechat is live, die vergelijken we bewust niet.
        $previous = $period->previous();
        $prev = [];
        foreach ($registry->dashboardContributors() as $contributor) {
            $prev = array_merge($prev, $contributor($site, $previous));
        }
        $stats['revenue_previous'] = $prev['revenue'] ?? null;
        $stats['orders_previous'] = $prev['orders'] ?? null;
        $stats['average_order_value_previous'] = $prev['average_order_value'] ?? null;

        // Navigatie-/label-info zodat de app per dag/week/maand/jaar kan bladeren.
        $stats['period_meta'] = [
            'label' => $period->label,
            'anchor' => $period->anchor,
            'prev_anchor' => $period->prevAnchor,
            'next_anchor' => $period->nextAnchor,
            'is_current' => $period->isCurrent,
        ];

        return response()->json($stats);
    }
}
