<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\User;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedMobileApi\Models\UserNotificationPreference;

/**
 * Per-gebruiker én per-site voorkeuren: welke geregistreerde notificatietypes
 * wil deze gebruiker op deze site op zijn telefoon ontvangen. Zonder opgeslagen
 * rij voor die site geldt de standaard (`default`) van het type.
 */
class NotificationPreferences
{
    public function __construct(private MobileApiRegistry $registry)
    {
    }

    private function siteId(?string $siteId): string
    {
        return $siteId ?? (string) Sites::getActive();
    }

    /**
     * Wil de gebruiker dit notificatietype op deze site ontvangen? Onbekend type
     * → ja (zo blokkeren we nooit een melding waarvoor geen toggle bestaat).
     */
    public function wants(User $user, string $type, ?string $siteId = null): bool
    {
        if (! $this->registry->notificationType($type)) {
            return true;
        }

        $pref = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('site_id', $this->siteId($siteId))
            ->first();

        if ($pref) {
            return (bool) $pref->enabled;
        }

        return (bool) ($this->registry->notificationType($type)['default'] ?? true);
    }

    /**
     * De effectieve voorkeuren van een gebruiker voor álle geregistreerde types
     * op deze site (type-key => enabled).
     *
     * @return array<string, bool>
     */
    public function all(User $user, ?string $siteId = null): array
    {
        $stored = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('site_id', $this->siteId($siteId))
            ->pluck('enabled', 'type');

        $out = [];
        foreach ($this->registry->notificationTypes() as $key => $type) {
            $out[$key] = $stored->has($key)
                ? (bool) $stored[$key]
                : (bool) ($type['default'] ?? true);
        }

        return $out;
    }

    public function set(User $user, string $type, bool $enabled, ?string $siteId = null): void
    {
        UserNotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => $type, 'site_id' => $this->siteId($siteId)],
            ['enabled' => $enabled],
        );
    }
}
