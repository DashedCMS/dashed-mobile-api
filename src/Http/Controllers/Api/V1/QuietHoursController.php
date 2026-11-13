<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedMobileApi\Support\QuietHours;

class QuietHoursController extends Controller
{
    public function __construct(private QuietHours $quietHours)
    {
    }

    /**
     * De stille-uren-instelling van de ingelogde gebruiker.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quietHours->forUser($request->user())]);
    }

    /**
     * Werk de stille uren bij. Body: { enabled, start: "HH:MM", end: "HH:MM", timezone }.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
        ]);

        $this->quietHours->set(
            $request->user(),
            (bool) $data['enabled'],
            (string) $data['start'],
            (string) $data['end'],
            (string) $data['timezone'],
        );

        return $this->index($request);
    }
}
