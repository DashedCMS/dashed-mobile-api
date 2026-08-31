<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi;

class MobileApiRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $capabilities = [];

    /** @var array<int, string> */
    private array $abilities = [];

    /** @var array<string, array<int, string>> */
    private array $roleAbilities = [];

    /** @var array<int, callable> */
    private array $dashboardContributors = [];

    /** @var array<int, callable> */
    private array $copilotContextContributors = [];

    /** @var array<int, callable> */
    private array $capabilityContextContributors = [];

    /** @var array<int, callable> Globaal-zoeken-providers (orders/producten/klanten/gesprekken). */
    private array $searchProviders = [];

    /** @var array<string, array<string, mixed>> */
    private array $notificationTypes = [];

    /** @var array<string, array<string, mixed>> */
    private array $orderOrigins = [];

    /** @var array<string, array<string, mixed>> Order-acties die de app dynamisch kan tonen/uitvoeren. */
    private array $orderActions = [];

    /** @var array<string, array<string, mixed>> Triggers voor automatiseringsregels ("als dit gebeurt en deze voorwaarden gelden, doe dat"). */
    private array $automationTriggers = [];

    /** @var array<string, array<string, mixed>> App-pagina's van modules zonder eigen app-schermen. */
    private array $appPages = [];

    public function registerCapability(string $key, array $meta = []): void
    {
        $this->capabilities[$key] = array_merge($this->capabilities[$key] ?? [], $meta);
    }

    /** @param array<int, string> $abilities */
    public function registerAbilities(array $abilities): void
    {
        $this->abilities = array_values(array_unique([...$this->abilities, ...$abilities]));
    }

    /** @param array<string, array<int, string>> $bySlug */
    public function registerRoleAbilities(array $bySlug): void
    {
        foreach ($bySlug as $slug => $abilities) {
            $this->roleAbilities[$slug] = array_values(array_unique([
                ...($this->roleAbilities[$slug] ?? []),
                ...$abilities,
            ]));
        }
    }

    public function registerDashboardContributor(callable $contributor): void
    {
        $this->dashboardContributors[] = $contributor;
    }

    /**
     * Een contributor die extra context aan de /capabilities-respons toevoegt,
     * afhankelijk van de ingelogde user en de actieve site. Krijgt (User $user,
     * string $siteId) en geeft een associatieve array terug die in de respons
     * wordt samengevoegd (bv. ['chat' => ['is_agent' => true, ...]]).
     */
    public function registerCapabilityContextContributor(callable $contributor): void
    {
        $this->capabilityContextContributors[] = $contributor;
    }

    /** @return array<int, callable> */
    public function capabilityContextContributors(): array
    {
        return $this->capabilityContextContributors;
    }

    /**
     * Registreer app-notificatietypes waar een gebruiker per stuk voor kan
     * kiezen of die op zijn telefoon binnenkomen. Elk type:
     *  key, label, description, group, sound, ability, default (bool).
     *
     * @param array<int, array<string, mixed>> $types
     */
    public function registerNotificationTypes(array $types): void
    {
        foreach ($types as $type) {
            if (! empty($type['key'])) {
                $this->notificationTypes[(string) $type['key']] = $type;
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function notificationTypes(): array
    {
        return $this->notificationTypes;
    }

    /** @return array<string, mixed>|null */
    public function notificationType(string $key): ?array
    {
        return $this->notificationTypes[$key] ?? null;
    }

    /**
     * Registreer bekende order-origins (own/pos/Bol/etsy) waar een gebruiker per
     * stuk kan kiezen of die order-notificaties geven. Elk: key, label, default.
     *
     * @param array<int, array<string, mixed>> $origins
     */
    public function registerOrderOrigins(array $origins): void
    {
        foreach ($origins as $origin) {
            if (! empty($origin['key'])) {
                $this->orderOrigins[(string) $origin['key']] = $origin;
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function orderOrigins(): array
    {
        return $this->orderOrigins;
    }

    /** @return array<string, mixed>|null */
    public function orderOrigin(string $key): ?array
    {
        return $this->orderOrigins[$key] ?? null;
    }

    /**
     * Registreer order-acties (zoals op de Filament ViewOrder-pagina) die de app
     * dynamisch kan tonen en uitvoeren. Elke actie:
     *  key, label, group, icon, destructive, confirm, fields[], visible(Order),
     *  handle(Order, array $data).
     *
     * @param array<int, array<string, mixed>> $actions
     */
    public function registerOrderActions(array $actions): void
    {
        foreach ($actions as $action) {
            if (! empty($action['key'])) {
                $this->orderActions[(string) $action['key']] = $action;
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function orderActions(): array
    {
        return $this->orderActions;
    }

    /** @return array<string, mixed>|null */
    public function orderAction(string $key): ?array
    {
        return $this->orderActions[$key] ?? null;
    }

    /**
     * Registreer triggers voor automatiseringsregels ("als dit gebeurt en deze
     * voorwaarden gelden, doe dat"). Elke trigger:
     *  key, label, subject, event (class-string), fields[], resolve(callable).
     *
     * @param array<int, array<string, mixed>> $triggers
     */
    public function registerAutomationTriggers(array $triggers): void
    {
        foreach ($triggers as $trigger) {
            if (! empty($trigger['key'])) {
                $this->automationTriggers[(string) $trigger['key']] = $trigger;
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function automationTriggers(): array
    {
        return $this->automationTriggers;
    }

    /** @return array<string, mixed>|null */
    public function automationTrigger(string $key): ?array
    {
        return $this->automationTriggers[$key] ?? null;
    }

    /**
     * Registreer een app-pagina voor een module zonder eigen app-schermen. De
     * app toont 'm in het menu en opent 'm via een éénmalige magic-link. Meta:
     *  title (string), icon (Ionicons-naam), group (default 'Modules'),
     *  ability (default 'dashboard.read'; een eigen ability ook via
     *  registerAbilities aanmelden), url (closure → absolute admin-URL).
     *
     * @param array<string, mixed> $meta
     */
    public function registerAppPage(string $key, array $meta): void
    {
        $this->appPages[$key] = $meta;
    }

    /** @return array<string, array<string, mixed>> */
    public function appPages(): array
    {
        return $this->appPages;
    }

    /** @return array<string, mixed>|null */
    public function appPage(string $key): ?array
    {
        return $this->appPages[$key] ?? null;
    }

    /** @return array<int, array{key: string, version: ?string}> */
    public function capabilities(): array
    {
        $out = [];
        foreach ($this->capabilities as $key => $meta) {
            $out[] = ['key' => $key, 'version' => $meta['version'] ?? null];
        }

        return $out;
    }

    /** @return array<int, string> */
    public function abilities(): array
    {
        return $this->abilities;
    }

    /** @return array<string, array<int, string>> */
    public function roleAbilities(): array
    {
        return $this->roleAbilities;
    }

    /** @return array<int, callable> */
    public function dashboardContributors(): array
    {
        return $this->dashboardContributors;
    }

    /**
     * Registreer een provider voor globaal zoeken. Elke provider is
     * `function (string $siteId, string $query): array` en geeft maximaal 5 items
     * terug in de vorm:
     *   ['type' => 'order'|'product'|'customer'|'conversation', 'id' => mixed,
     *    'title' => string, 'subtitle' => string|null, 'route' => string]
     * waarbij `route` de app-deeplink is (bv. /order/123, /product/45,
     * /customer/<email>, /conversation/7). Providers respecteren de actieve site;
     * de /search-endpoint draait al achter auth.
     */
    public function registerSearchProvider(callable $provider): void
    {
        $this->searchProviders[] = $provider;
    }

    /** @return array<int, callable> */
    public function searchProviders(): array
    {
        return $this->searchProviders;
    }

    /**
     * AI-copilot context: elke bijdrager geeft per site een leesbaar tekstblok
     * met actuele cijfers/feiten waarop de assistent zijn antwoorden baseert.
     */
    public function registerCopilotContext(callable $contributor): void
    {
        $this->copilotContextContributors[] = $contributor;
    }

    /** @return array<int, callable> */
    public function copilotContextContributors(): array
    {
        return $this->copilotContextContributors;
    }

    /** Bouw de volledige copilot-context voor een site (alle bijdragers samengevoegd). */
    public function copilotContext(string $siteId): string
    {
        $blocks = [];
        foreach ($this->copilotContextContributors as $contributor) {
            try {
                $block = $contributor($siteId);
            } catch (\Throwable $e) {
                continue;
            }
            if (is_string($block) && trim($block) !== '') {
                $blocks[] = trim($block);
            }
        }

        return implode("\n\n", $blocks);
    }
}
