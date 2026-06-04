<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Illuminate\Support\Str;
use Dashed\DashedCore\Models\User;

class AbilityResolver
{
    public const ALL = [
        'products.read',
        'products.write',
        'orders.read',
        'orders.write',
        'chat.read',
        'chat.reply',
        'chat.takeover',
        'dashboard.read',
        'devices.write',
    ];

    /**
     * @return array<int, string>
     */
    public function abilitiesFor(User $user): array
    {
        if (in_array($user->role, ['superadmin', 'admin'], true)) {
            return self::ALL;
        }

        $map = config('dashed-mobile-api.role_abilities', []);
        $abilities = ['devices.write'];

        foreach ($user->roles as $role) {
            $slug = Str::slug((string) $role->name);
            $abilities = array_merge($abilities, $map[$slug] ?? []);

            $extra = is_array($role->extra_permissions) ? $role->extra_permissions : [];
            $abilities = array_merge($abilities, array_values(array_intersect($extra, self::ALL)));
        }

        return array_values(array_intersect(array_unique($abilities), self::ALL));
    }
}
