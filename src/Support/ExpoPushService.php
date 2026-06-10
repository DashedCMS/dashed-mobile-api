<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Illuminate\Support\Facades\Http;
use Dashed\DashedMobileApi\Models\DeviceToken;
use Dashed\DashedMobileApi\Support\NotificationPreferences;
use Dashed\DashedMobileApi\Support\OrderOriginPreferences;

class ExpoPushService
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function __construct(private AbilityResolver $abilities)
    {
    }

    /**
     * Stuur een push naar alle geregistreerde devices van users die `$ability` hebben.
     *
     * @param array<string, mixed> $data
     */
    public function notifyAbility(string $ability, string $title, string $body, array $data = [], string $sound = 'default', ?string $channelId = null, ?string $imageUrl = null, ?string $notificationType = null, ?string $orderOrigin = null, ?string $siteId = null): void
    {
        $preferences = $notificationType !== null ? app(NotificationPreferences::class) : null;
        $origins = $orderOrigin !== null ? app(OrderOriginPreferences::class) : null;

        $tokens = DeviceToken::with('user')->get()
            ->filter(fn (DeviceToken $d): bool => $d->user !== null
                && in_array($ability, $this->abilities->abilitiesFor($d->user), true)
                && ($preferences === null || $preferences->wants($d->user, (string) $notificationType, $siteId))
                && ($origins === null || $origins->wants($d->user, $orderOrigin)))
            ->pluck('token')
            ->all();

        $this->sendToTokens($tokens, $title, $body, $data, $sound, $channelId, $imageUrl);
    }

    /**
     * @param array<int, string|null> $tokens
     * @param array<string, mixed> $data
     * @param string $sound iOS-geluidsbestand (bv. 'order.wav') of 'default'
     * @param string|null $channelId Android-notificatiekanaal (bepaalt daar het geluid)
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = [], string $sound = 'default', ?string $channelId = null, ?string $imageUrl = null): void
    {
        $valid = array_values(array_filter(
            $tokens,
            static fn ($t): bool => is_string($t) && str_starts_with($t, 'ExponentPushToken'),
        ));

        if ($valid === []) {
            return;
        }

        $messages = array_map(static function (string $token) use ($title, $body, $data, $sound, $channelId, $imageUrl): array {
            $message = [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'sound' => $sound,
                'data' => $data,
            ];
            if ($channelId !== null) {
                $message['channelId'] = $channelId;
            }
            if ($imageUrl !== null) {
                // Toont het sitelogo bij de notificatie (Android direct; iOS via
                // de notification service extension van expo-notifications).
                $message['richContent'] = ['image' => $imageUrl];
            }

            return $message;
        }, $valid);

        Http::acceptJson()->asJson()->post(self::ENDPOINT, $messages);
    }
}
