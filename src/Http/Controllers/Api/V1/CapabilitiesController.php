<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedMobileApi\Support\AbilityResolver;

class CapabilitiesController extends Controller
{
    public function index(Request $request, MobileApiRegistry $registry, AbilityResolver $resolver): JsonResponse
    {
        $user = $request->user();

        // Op de ACTUELE rol-rechten i.p.v. de in het token gebakken abilities,
        // zodat nieuwe rechten meteen in het menu verschijnen (geen re-login nodig).
        $abilities = $resolver->abilitiesFor($user);

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

        // Merkkleur per shop (dezelfde die de shop voor e-mails gebruikt), zodat de
        // app zijn accent op de huisstijl van de website afstemt. Alleen doorgeven
        // als het een geldige hex is; anders valt de app terug op zijn eigen accent.
        $brandColor = Customsetting::get('mail_primary_color', $siteId);
        $accentColor = is_string($brandColor) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $brandColor)
            ? $brandColor
            : null;

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
            'accent_color' => $accentColor,
            'api_version' => $apiVersion,
        ], $context));
    }
}
