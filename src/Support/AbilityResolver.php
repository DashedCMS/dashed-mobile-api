<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Dashed\DashedCore\Models\User;
use Dashed\DashedMobileApi\MobileApiRegistry;

/**
 * Leidt de app-abilities van een gebruiker af uit zijn CMS-rechten, zodat de
 * app precies toestaat wat het CMS toestaat:
 *  - superadmin: elke bekende ability;
 *  - klant (geen rol): alleen devices.write, en inloggen in de app kan hij
 *    sowieso niet (AuthController);
 *  - beheerder: elke ability waarvan minstens één gekoppelde CMS-permissie
 *    via $user->can() slaagt (MobileApiRegistry::registerAbilityPermissions()).
 *    Een ability zonder koppeling geeft alleen toegang als een rol de
 *    ability-naam letterlijk in zijn extra_permissions heeft.
 * devices.write krijgt iedereen: zonder kan de app zijn pushtoken niet
 * aanmelden of afmelden.
 */
class AbilityResolver
{
    public function __construct(private MobileApiRegistry $registry)
    {
    }

    /**
     * @return array<int, string>
     */
    public function abilitiesFor(User $user): array
    {
        // De mfa.*-namespace is gereserveerd voor de 2FA-status op het token
        // (mfa.passed / mfa.at:<ts>) en mag NOOIT via de rechten-resolver
        // uitgedeeld worden, ook niet als een package hem per ongeluk
        // registreert (een superadmin krijgt anders de hele known-lijst).
        $known = array_values(array_unique(array_filter(
            [...$this->registry->abilities(), 'devices.write'],
            fn (string $ability): bool => ! str_starts_with($ability, 'mfa.'),
        )));

        if ($user->isSuperadmin()) {
            return $known;
        }

        if (! $user->hasPanelAccess()) {
            return ['devices.write'];
        }

        // Rollen één keer laden: Gate::before loopt ze per can() af.
        $user->loadMissing('roles');

        return array_values(array_filter(
            $known,
            fn (string $ability): bool => $ability === 'devices.write' || $this->grants($user, $ability),
        ));
    }

    private function grants(User $user, string $ability): bool
    {
        $permissions = $this->registry->permissionsForAbility($ability);

        // Geen koppeling: alleen de ability-naam zelf in de rol telt.
        return PermissionCheck::any($user, $permissions ?? [$ability]);
    }
}
