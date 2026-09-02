<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Illuminate\Support\Str;
use Dashed\DashedCore\Models\User;
use Dashed\DashedMobileApi\MobileApiRegistry;

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
        // uitgedeeld worden — ook niet als een package 'm per ongeluk
        // registreert (admins krijgen anders de hele known-lijst).
        $known = array_values(array_unique(array_filter(
            [...$this->registry->abilities(), 'devices.write'],
            fn (string $ability): bool => ! str_starts_with($ability, 'mfa.'),
        )));

        if (in_array($user->role, ['superadmin', 'admin'], true)) {
            return $known;
        }

        $map = $this->registry->roleAbilities();
        $abilities = ['devices.write'];

        foreach ($user->roles as $role) {
            $slug = Str::slug((string) $role->name);
            $abilities = array_merge($abilities, $map[$slug] ?? []);

            $extra = is_array($role->extra_permissions) ? $role->extra_permissions : [];
            $abilities = array_merge($abilities, array_values(array_intersect($extra, $known)));
        }

        return array_values(array_intersect(array_unique($abilities), $known));
    }
}
