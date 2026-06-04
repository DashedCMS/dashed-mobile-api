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
        $known = array_values(array_unique([...$this->registry->abilities(), 'devices.write']));

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
