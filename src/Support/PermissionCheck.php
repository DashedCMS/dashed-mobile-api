<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Closure;
use Dashed\DashedCore\Models\User;
use Dashed\DashedMobileApi\MobileApiRegistry;

/**
 * Toetst een lijst CMS-permissies uit de registry (any-of) voor een gebruiker.
 * Een element is een CMS-permissie (via $user->can(), dus Gate::before met de
 * extra_permissions van de rollen), MobileApiRegistry::PANEL_ACCESS of een
 * Closure die de gebruiker krijgt.
 */
class PermissionCheck
{
    /** @param array<int, string|Closure> $permissions */
    public static function any(User $user, array $permissions): bool
    {
        if ($user->isSuperadmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($permission instanceof Closure) {
                if ((bool) $permission($user)) {
                    return true;
                }

                continue;
            }

            if ($permission === MobileApiRegistry::PANEL_ACCESS) {
                if ($user->hasPanelAccess()) {
                    return true;
                }

                continue;
            }

            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
