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
    private ?string $type = null;
    private ?string $orderOrigin = null;
    private ?string $site = null;
    private ?string $category = null;

    /** @var array<int, string> */
    private array $tokens = [];

    /** @var array<int, int>|null Expliciete ontvangers (toUsers()), anders null. */
    private ?array $userIds = null;

    /** Of het recht met toAbility() is gezet (en dus niet alleen uit het type kwam). */
    private bool $abilityExplicit = false;

    public function __construct(private ExpoPushService $push)
    {
    }

    /**
     * Koppel deze melding aan een geregistreerd notificatietype. De ontvanger-
     * filtering houdt dan rekening met de per-gebruiker voorkeur, en geluid +
     * recht worden (indien niet expliciet gezet) uit het type overgenomen.
     */
    public function type(string $key): self
    {
        $this->type = $key;

        $registry = app(\Dashed\DashedMobileApi\MobileApiRegistry::class)->notificationType($key);
        if ($registry) {
            if ($this->sound === 'default' && ! empty($registry['sound'])) {
                $this->sound = (string) $registry['sound'];
            }
            if ($this->ability === null && ! empty($registry['ability'])) {
                $this->ability = (string) $registry['ability'];
            }
        }

        return $this;
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
        $this->abilityExplicit = true;

        return $this;
    }

    /**
     * Verstuur naar de devices van precies deze gebruikers (User-modellen of
     * id's), bijvoorbeeld de medewerkers die ChatAccess voor een site toelaat.
     * Anders dan toTokens() gelden hier wél de voorkeur per type, de
     * order-origin, stille uren en het filter op uitgelogde sessies, en komt de
     * melding in de inbox van elke ontvanger. Het recht uit type() telt hier
     * niet als filter (de aanroeper koos de ontvangers al); een expliciete
     * toAbility() wel.
     *
     * @param iterable<int, \Dashed\DashedCore\Models\User|int|string> $users
     */
    public function toUsers(iterable $users): self
    {
        $ids = [];
        foreach ($users as $user) {
            $id = is_object($user) ? $user->getKey() : $user;
            if ($id !== null && $id !== '') {
                $ids[(int) $id] = (int) $id;
            }
        }

        $this->userIds = array_values($ids);

        return $this;
    }

    /** @param array<int, string> $tokens Verstuur naar specifieke Expo-tokens. */
    public function toTokens(array $tokens): self
    {
        $this->tokens = $tokens;

        return $this;
    }

    /**
     * Koppel de melding aan een order-origin (own/pos/Bol/…). Ontvangers die
     * deze origin in hun voorkeuren hebben uitgezet, krijgen 'm dan niet.
     */
    public function orderOrigin(?string $origin): self
    {
        $this->orderOrigin = $origin;

        return $this;
    }

    /** Site waarvoor deze melding geldt (default: de actieve site bij verzenden). */
    public function site(?string $siteId): self
    {
        $this->site = $siteId;

        return $this;
    }

    /**
     * Koppel een OS-notificatiecategorie (Expo `categoryId`) aan de melding. Die
     * categorie moet in de app geregistreerd zijn en bepaalt welke actie-knoppen
     * het OS bij de notificatie toont (bv. 'chat_handoff' → Overnemen / Open chat).
     */
    public function category(?string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function send(): void
    {
        [$iosSound, $channelId] = self::SOUNDS[$this->sound] ?? self::SOUNDS['default'];

        $data = $this->data;
        if ($this->route !== null) {
            $data['route'] = $this->route;
        }

        // Elke push draagt bij voorkeur een absolute CMS-URL (data.url), zodat
        // de app die kan openen als hij geen eigen scherm voor de route heeft.
        if (empty($data['url']) && ($url = self::urlForRoute($this->route)) !== null) {
            $data['url'] = $url;
        }

        // Titel én afbeelding van elke push zijn altijd de sitenaam + het
        // sitelogo. De meegegeven titel (bv. "Nieuwe bestelling") schuift door
        // naar de body.
        $branding = SiteBranding::for();

        // Site-identiteit meesturen zodat de app (bij meerdere ingelogde sites)
        // bij een tik naar de juiste site kan schakelen. De site-id is het
        // autoritatieve anker; naam + URL blijven als fallback voor oudere apps.
        $data['site'] = [
            'id' => $this->site ?? (string) \Dashed\DashedCore\Classes\Sites::getActive(),
            'name' => $branding['name'],
            'url' => rtrim((string) config('app.url'), '/'),
        ];

        $title = $branding['name'];
        $heading = trim($this->title);
        $body = $heading !== ''
            ? ($this->body !== '' ? "{$heading} — {$this->body}" : $heading)
            : $this->body;
        $imageUrl = $branding['logo_url'];

        if ($this->userIds !== null) {
            $ability = $this->abilityExplicit ? $this->ability : null;
            $this->persistFor($this->userIds, $ability, $heading !== '' ? $heading : $title, $this->body, $data);

            $this->push->notifyUsers($this->userIds, $title, $body, $data, $iosSound, $channelId, $imageUrl, $this->type, $this->orderOrigin, $this->site, $this->category, $ability);

            return;
        }

        if ($this->ability !== null) {
            // Persisteer één inbox-rij per distinct doel-gebruiker (zelfde
            // ability- + voorkeur-filter als de push), zodat de app-inbox de
            // melding kan tonen los van de vluchtige OS-push.
            $this->persistFor(null, $this->ability, $heading !== '' ? $heading : $title, $this->body, $data);

            $this->push->notifyAbility($this->ability, $title, $body, $data, $iosSound, $channelId, $imageUrl, $this->type, $this->orderOrigin, $this->site, $this->category);

            return;
        }

        if ($this->tokens !== []) {
            // Pure token-sends hebben geen vaste user→type-relatie (kunnen
            // anonieme of mixed-user tokens zijn) en worden niet gepersisteerd.
            $this->push->sendToTokens($this->tokens, $title, $body, $data, $iosSound, $channelId, $imageUrl, $this->category);
        }
    }

    /**
     * CMS page for a known in-app route ("/order/{id}", "/return/{id}"), via
     * ec-core's AdminRecordUrl when that package is installed. Null when no
     * URL can be derived; never throws.
     */
    public static function urlForRoute(?string $route): ?string
    {
        $helper = '\Dashed\DashedEcommerceCore\Support\AdminRecordUrl';
        if ($route === null || ! class_exists($helper)) {
            return null;
        }

        try {
            if (preg_match('#^/order/(\d+)/?$#', $route, $m)) {
                return $helper::order((int) $m[1]);
            }

            if (preg_match('#^/return/(\d+)/?$#', $route, $m)) {
                $return = \Dashed\DashedEcommerceCore\Models\OrderReturn::find((int) $m[1]);

                return $return ? $helper::orderReturn($return) : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Sla per distinct doel-gebruiker (met `user_id`) een inbox-rij op. Mirrort
     * de filtering uit ExpoPushService::notifyAbility/notifyUsers: alleen de
     * gekozen users (bij toUsers()), alleen users die het
     * recht hebben én — als er een type/origin is gekoppeld — de melding in hun
     * voorkeuren niet hebben uitgezet. Defensief: een fout hier blokkeert de
     * push nooit.
     *
     * @param array<int, int>|null $userIds
     * @param array<string, mixed> $data
     */
    private function persistFor(?array $userIds, ?string $ability, string $title, string $body, array $data): void
    {
        try {
            $siteId = $this->site ?? (string) \Dashed\DashedCore\Classes\Sites::getActive();

            $abilities = app(AbilityResolver::class);
            $preferences = $this->type !== null ? app(NotificationPreferences::class) : null;
            $origins = $this->orderOrigin !== null ? app(OrderOriginPreferences::class) : null;

            $users = \Dashed\DashedMobileApi\Models\DeviceToken::with('user')
                ->when($userIds !== null, fn ($query) => $query->whereIn('user_id', $userIds))
                ->get()
                ->map(fn ($d) => $d->user)
                ->filter()
                ->unique('id')
                ->filter(fn ($user): bool => ($ability === null || in_array($ability, $abilities->abilitiesFor($user), true))
                    && ($preferences === null || $preferences->wants($user, (string) $this->type, $siteId))
                    && ($origins === null || $origins->wants($user, $this->orderOrigin)));

            foreach ($users as $user) {
                \Dashed\DashedMobileApi\Models\MobileNotification::create([
                    'user_id' => $user->id,
                    'site_id' => $siteId,
                    'type' => (string) ($this->type ?? 'general'),
                    'title' => $title,
                    'body' => $body !== '' ? $body : null,
                    'data' => $data !== [] ? $data : null,
                    'route' => $this->route,
                ]);
            }
        } catch (\Throwable) {
            // Persistentie is best-effort; nooit de push laten falen.
        }
    }
}
