<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Dashed\DashedCore\Models\User;
use Dashed\DashedMobileApi\MobileApiRegistry;

/**
 * Haalt uit een dagoverzicht (DailySummaryBuilder) de secties weg waar de
 * gebruiker in het CMS geen recht op heeft. Welke permissie een sectie vraagt
 * staat in MobileApiRegistry::registerSummarySectionPermissions(); een sectie
 * zonder koppeling ziet elke beheerder.
 */
class SummaryAccess
{
    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public static function filter(User $user, array $summary): array
    {
        if (! isset($summary['sections']) || ! is_array($summary['sections'])) {
            return $summary;
        }

        $registry = app(MobileApiRegistry::class);

        $summary['sections'] = array_values(array_filter(
            $summary['sections'],
            function ($section) use ($user, $registry): bool {
                $permissions = $registry->permissionsForSummarySection((string) ($section['key'] ?? ''));

                return $permissions === null || PermissionCheck::any($user, $permissions);
            },
        ));

        return $summary;
    }
}
