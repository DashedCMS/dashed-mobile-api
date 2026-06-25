<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Services\Summary\DailySummaryBuilder;

/**
 * Dag-overzicht voor de app: draait alle geregistreerde summary-contributors
 * voor de gevraagde dag (default gisteren) en geeft de secties als JSON terug —
 * dezelfde data als de samenvatting-mail (omzet, bestellingen, verlaten
 * winkelwagens incl. verzonden mails, verzending, popups, formulieren, AI-briefing).
 */
class DailySummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $input = trim((string) $request->query('date', ''));

        try {
            $date = $input !== '' ? Carbon::parse($input) : Carbon::yesterday();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Ongeldige datum.'], 422);
        }

        // Nooit een dag in de toekomst tonen.
        if ($date->copy()->startOfDay()->isFuture()) {
            $date = Carbon::today();
        }

        return response()->json([
            'data' => DailySummaryBuilder::buildForDate($date),
        ]);
    }
}
