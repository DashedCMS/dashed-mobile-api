<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Dashed\DashedCore\Models\User;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedMobileApi\Models\UserNotificationPreference;

/**
 * Per-gebruiker voorkeuren: welke geregistreerde notificatietypes wil deze
 * gebruiker op zijn telefoon ontvangen. Zonder opgeslagen rij geldt de
 * standaard (`default`) van het type.
 */
class NotificationPreferences
{
    public function __construct(private MobileApiRegistry $registry)
    {
    }

    /**
     * Wil de gebruiker dit notificatietype ontvangen? Onbekend type → ja
     * (zo blokkeren we nooit een melding waarvoor geen toggle bestaat).
     */
    public function wants(User $user, string $type): bool
    {
        if (! $this->registry->notificationType($type)) {
            return true;
        }

        $pref = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->first();

        if ($pref) {
            return (bool) $pref->enabled;
        }

        return (bool) ($this->registry->notificationType($type)['default'] ?? true);
    }

    /**
     * De effectieve voorkeuren van een gebruiker voor álle geregistreerde
     * types (type-key => enabled).
     *
     * @return array<string, bool>
     */
    public function all(User $user): array
    {
        $stored = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->pluck('enabled', 'type');

        $out = [];
        foreach ($this->registry->notificationTypes() as $key => $type) {
            $out[$key] = $stored->has($key)
                ? (bool) $stored[$key]
                : (bool) ($type['default'] ?? true);
        }

        return $out;
    }

    public function set(User $user, string $type, bool $enabled): void
    {
        UserNotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => $type],
            ['enabled' => $enabled],
        );
    }
}
