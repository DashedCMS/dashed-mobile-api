<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Illuminate\Support\Facades\Http;
use Dashed\DashedMobileApi\Models\DeviceToken;

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
    public function notifyAbility(string $ability, string $title, string $body, array $data = [], string $sound = 'default', ?string $channelId = null): void
    {
        $tokens = DeviceToken::with('user')->get()
            ->filter(fn (DeviceToken $d): bool => $d->user !== null
                && in_array($ability, $this->abilities->abilitiesFor($d->user), true))
            ->pluck('token')
            ->all();

        $this->sendToTokens($tokens, $title, $body, $data, $sound, $channelId);
    }

    /**
     * @param array<int, string|null> $tokens
     * @param array<string, mixed> $data
     * @param string $sound iOS-geluidsbestand (bv. 'order.wav') of 'default'
     * @param string|null $channelId Android-notificatiekanaal (bepaalt daar het geluid)
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = [], string $sound = 'default', ?string $channelId = null): void
    {
        $valid = array_values(array_filter(
            $tokens,
            static fn ($t): bool => is_string($t) && str_starts_with($t, 'ExponentPushToken'),
        ));

        if ($valid === []) {
            return;
        }

        $messages = array_map(static function (string $token) use ($title, $body, $data, $sound, $channelId): array {
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

            return $message;
        }, $valid);

        Http::acceptJson()->asJson()->post(self::ENDPOINT, $messages);
    }
}
