<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Dashed\DashedCore\Models\User;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedMobileApi\Models\UserOrderOriginPreference;

/**
 * Per-gebruiker voorkeur: uit welke order-origins (own/pos/Bol/etsy/…) wil deze
 * gebruiker order-notificaties op zijn telefoon. Zonder opgeslagen rij geldt de
 * standaard van de geregistreerde origin (en onbekende origins → ja).
 */
class OrderOriginPreferences
{
    public function __construct(private MobileApiRegistry $registry)
    {
    }

    public function wants(User $user, ?string $origin): bool
    {
        $origin = $origin ?: 'own';

        $pref = UserOrderOriginPreference::query()
            ->where('user_id', $user->id)
            ->where('order_origin', $origin)
            ->first();

        if ($pref) {
            return (bool) $pref->enabled;
        }

        $registered = $this->registry->orderOrigin($origin);

        return (bool) ($registered['default'] ?? true);
    }

    /**
     * Beschikbare origins + of de gebruiker ze ontvangt.
     *
     * @return array<int, array{key: string, label: string, enabled: bool}>
     */
    public function all(User $user): array
    {
        $stored = UserOrderOriginPreference::query()
            ->where('user_id', $user->id)
            ->pluck('enabled', 'order_origin');

        $out = [];
        foreach ($this->registry->orderOrigins() as $key => $origin) {
            $out[] = [
                'key' => (string) $key,
                'label' => (string) ($origin['label'] ?? $key),
                'enabled' => $stored->has($key) ? (bool) $stored[$key] : (bool) ($origin['default'] ?? true),
            ];
        }

        return $out;
    }

    public function set(User $user, string $origin, bool $enabled): void
    {
        UserOrderOriginPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'order_origin' => $origin],
            ['enabled' => $enabled],
        );
    }
}
