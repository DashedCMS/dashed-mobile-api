<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

/**
 * Vloeiende builder om eenvoudig een push-notificatie samen te stellen en te
 * versturen. Vertaalt een logische geluidssleutel naar het juiste iOS-geluids-
 * bestand én Android-notificatiekanaal, en zet de deep-link (`route`) in de data
 * zodat de app de juiste pagina opent bij een tik.
 *
 * Gebruik (bv. vanuit een listener of CMS-actie):
 *
 *   app(NotificationCenter::class)->push()
 *       ->title('Nieuwe bestelling')
 *       ->body("€ {$total} — {$name}")
 *       ->sound('order')
 *       ->route("/order/{$order->id}")
 *       ->data(['type' => 'order', 'id' => $order->id])
 *       ->toAbility('orders.read')
 *       ->send();
 */
class PushNotification
{
    /**
     * Logische geluidssleutel => [iOS-geluidsbestand, Android-kanaal].
     * De bestanden/kanalen moeten in de app geregistreerd zijn (app.json + notifications.ts).
     *
     * @var array<string, array{0:string,1:string}>
     */
    public const SOUNDS = [
        'default' => ['default', 'default'],
        'order' => ['order.wav', 'orders'],
        'chat' => ['chat.wav', 'chat'],
    ];

    private string $title = '';
    private string $body = '';
    private string $sound = 'default';
    private ?string $route = null;

    /** @var array<string, mixed> */
    private array $data = [];

    private ?string $ability = null;

    /** @var array<int, string> */
    private array $tokens = [];

    public function __construct(private ExpoPushService $push)
    {
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /** Logische geluidssleutel: 'default', 'order' of 'chat'. */
    public function sound(string $sound): self
    {
        $this->sound = isset(self::SOUNDS[$sound]) ? $sound : 'default';

        return $this;
    }

    /** Deep-link naar een app-pagina, bv. "/order/12" of "/conversation/8". */
    public function route(?string $route): self
    {
        $this->route = $route;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function data(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /** Verstuur naar alle devices van users met dit recht. */
    public function toAbility(string $ability): self
    {
        $this->ability = $ability;

        return $this;
    }

    /** @param array<int, string> $tokens Verstuur naar specifieke Expo-tokens. */
    public function toTokens(array $tokens): self
    {
        $this->tokens = $tokens;

        return $this;
    }

    public function send(): void
    {
        [$iosSound, $channelId] = self::SOUNDS[$this->sound] ?? self::SOUNDS['default'];

        $data = $this->data;
        if ($this->route !== null) {
            $data['route'] = $this->route;
        }

        if ($this->ability !== null) {
            $this->push->notifyAbility($this->ability, $this->title, $this->body, $data, $iosSound, $channelId);

            return;
        }

        if ($this->tokens !== []) {
            $this->push->sendToTokens($this->tokens, $this->title, $this->body, $data, $iosSound, $channelId);
        }
    }
}
