<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedMobileApi\MobileApiRegistry;

class CapabilitiesController extends Controller
{
    public function index(Request $request, MobileApiRegistry $registry): JsonResponse
    {
        $user = $request->user();

        $allKnownAbilities = array_values(array_unique([...$registry->abilities(), 'devices.write']));
        $abilities = array_values(array_filter($allKnownAbilities, static fn (string $a): bool => $user->tokenCan($a)));

        $sites = array_map(static fn (array $site): array => [
            'id' => (string) $site['id'],
            'name' => $site['name'] ?? (string) $site['id'],
            'locales' => $site['locales'] ?? [],
        ], Sites::getSites());

        $apiVersion = \Composer\InstalledVersions::isInstalled('dashed/dashed-mobile-api')
            ? \Composer\InstalledVersions::getPrettyVersion('dashed/dashed-mobile-api')
            : null;

        return response()->json([
            'capabilities' => $registry->capabilities(),
            'abilities' => $abilities,
            'sites' => $sites,
            'api_version' => $apiVersion,
        ]);
    }
}
