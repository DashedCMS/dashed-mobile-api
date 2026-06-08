<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;

/**
 * Sitenaam + logo-URL voor een site. Gebruikt o.a. voor de app-branding in
 * /capabilities én als titel/afbeelding van push-notificaties.
 */
class SiteBranding
{
    /**
     * @return array{name: string, logo_url: ?string}
     */
    public static function for(?string $siteId = null): array
    {
        $siteId = (string) ($siteId ?: Sites::getActive() ?: (Sites::getFirstSite()['id'] ?? ''));

        $configName = collect(Sites::getSites())->firstWhere('id', $siteId)['name'] ?? $siteId;
        $name = (string) Customsetting::get('site_name', $siteId, $configName);

        $logoId = Customsetting::get('site_logo', $siteId);
        $logoUrl = $logoId ? (mediaHelper()->getSingleMedia($logoId)->url ?? null) : null;

        return ['name' => $name, 'logo_url' => $logoUrl];
    }
}
