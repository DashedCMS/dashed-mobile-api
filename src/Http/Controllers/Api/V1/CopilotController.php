<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedMobileApi\Support\SiteBranding;

/**
 * AI Ops-Copilot: beantwoordt natuurlijke-taalvragen over de webshop. Baseert
 * zich op een actuele context-snapshot die packages via de registry bijdragen
 * (omzet, voorraad, bestellingen, …). Read-only: geeft antwoorden, voert (nog)
 * geen acties uit.
 */
class CopilotController extends Controller
{
    private const AI = '\Dashed\DashedAi\Facades\Ai';

    public function ask(Request $request, MobileApiRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        if (! class_exists(self::AI) || ! (self::AI)::hasProvider()) {
            return response()->json(['message' => 'Er is geen AI-provider gekoppeld. Stel die in onder AI-instellingen.'], 422);
        }

        $site = (string) Sites::getActive();
        $context = $registry->copilotContext($site);
        $siteName = SiteBranding::for()['name'] ?? 'de webshop';

        $system = "Je bent de AI Ops-Copilot in het beheerpaneel van webshop \"{$siteName}\"."
            . ' Je helpt de eigenaar en medewerkers met korte, concrete antwoorden over hun shop:'
            . ' omzet, bestellingen, voorraad, wat er te verzenden is, enzovoort.'
            . " Vandaag is " . now()->translatedFormat('l j F Y H:i') . '.'
            . "\n\nGebruik UITSLUITEND onderstaande actuele gegevens. Verzin niets en noem geen cijfers"
            . ' die er niet staan; als iets niet in de gegevens staat, zeg dat eerlijk. Antwoord in het Nederlands,'
            . ' kort en bruikbaar (gebruik gerust opsommingen). Bedragen in euro.'
            . "\n\n=== ACTUELE GEGEVENS ===\n" . ($context !== '' ? $context : '(geen gegevens beschikbaar)');

        $messages = [];
        foreach ($data['history'] ?? [] as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $data['question']];

        $response = (self::AI)::messages($messages, [
            'system' => $system,
            'temperature' => 0.3,
            'max_tokens' => 1024,
        ]);

        $answer = collect($response['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        $answer = trim((string) $answer);
        if ($answer === '') {
            return response()->json(['message' => 'De assistent gaf geen antwoord. Probeer het opnieuw.'], 422);
        }

        return response()->json(['answer' => $answer]);
    }
}
