<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
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

        // Actieve site (door mobile.site-middleware bepaald) + branding voor de app.
        $siteId = (string) ($request->attributes->get('mobile_site_id')
            ?: (Sites::getFirstSite()['id'] ?? ''));
        $configName = collect(Sites::getSites())->firstWhere('id', $siteId)['name'] ?? $siteId;
        $siteName = Customsetting::get('site_name', $siteId, $configName);
        $logoId = Customsetting::get('site_logo', $siteId);
        $logoUrl = $logoId ? (mediaHelper()->getSingleMedia($logoId)->url ?? null) : null;

        // Module-specifieke context (bv. livechat-medewerkerstatus + rechten),
        // afhankelijk van de user en de actieve site.
        $context = [];
        foreach ($registry->capabilityContextContributors() as $contributor) {
            $context = array_merge($context, (array) $contributor($user, $siteId));
        }

        return response()->json(array_merge([
            'capabilities' => $registry->capabilities(),
            'abilities' => $abilities,
            'sites' => $sites,
            'site' => [
                'id' => $siteId,
                'name' => $siteName,
                'logo_url' => $logoUrl,
            ],
            'api_version' => $apiVersion,
        ], $context));
    }
}
