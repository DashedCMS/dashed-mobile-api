<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi;

class MobileApiRegistry
{
    /**
     * Plaatshouder in een permissielijst: "elke beheerder" (superadmin of
     * minstens één rol, User::hasPanelAccess()). Voor app-rechten waar het CMS
     * geen eigen permissie voor heeft, zoals het dashboard.
     */
    public const PANEL_ACCESS = '@panel';

    /** @var array<string, array<string, mixed>> */
    private array $capabilities = [];

    /** @var array<int, string> */
    private array $abilities = [];

    /** @var array<string, array<int, string|\Closure>> App-ability => CMS-permissies die hem geven (any-of). */
    private array $abilityPermissions = [];

    /** @var array<string, array<int, string|\Closure>> Sleutel van een dagoverzicht-sectie => CMS-permissies (any-of). */
    private array $summarySectionPermissions = [];

    /** @var array<int, callable> */
    private array $dashboardContributors = [];

    /** @var array<int, callable> */
    private array $copilotContextContributors = [];

    /** @var array<int, array<int, string|\Closure>|null> Permissies per copilot-bijdrager, zelfde index. */
    private array $copilotContextPermissions = [];

    /** @var array<int, callable> */
    private array $capabilityContextContributors = [];

    /** @var array<int, array{provider: callable, ability: string|null}> Globaal-zoeken-providers (orders/producten/klanten/gesprekken). */
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

    /**
     * Koppel app-abilities aan de CMS-permissie(s) die ze geven, zodat iemand in
     * de app precies mag wat hij in het CMS mag. Elke ability krijgt één
     * permissie of een lijst; de lijst is any-of (één ervan volstaat).
     *
     *   $registry->registerAbilityPermissions([
     *       'orders.read' => 'view_order',
     *       'orders.write' => ['edit_order'],
     *       'dashboard.read' => MobileApiRegistry::PANEL_ACCESS,
     *       'chat.read' => fn (User $user): bool => ChatAccess::isAgentAnywhere($user),
     *   ]);
     *
     * Een element is een CMS-permissie (getoetst met $user->can(), dus via
     * Gate::before en de extra_permissions van de rollen), de plaatshouder
     * PANEL_ACCESS, of een Closure die de gebruiker krijgt en een bool geeft.
     * Een superadmin krijgt elke ability, een klant (geen rol) alleen
     * devices.write. De abilities worden meteen ook aangemeld
     * (registerAbilities), en een tweede registratie voor dezelfde ability
     * voegt permissies toe in plaats van te vervangen.
     *
     * Aanroepen vanuit een ander pakket met een guard, want mobile-api kan
     * ontbreken of ouder zijn:
     *   if (method_exists($registry, 'registerAbilityPermissions')) { ... }
     *
     * Een ability zonder koppeling geeft alleen nog toegang als een rol de
     * ability-naam letterlijk in zijn extra_permissions heeft; de guard-test
     * AppAbilityPermissionGuardTest eist een koppeling of een uitzondering.
     *
     * @param array<string, string|\Closure|array<int, string|\Closure>> $map
     */
    public function registerAbilityPermissions(array $map): void
    {
        foreach ($map as $ability => $permissions) {
            $this->abilityPermissions[$ability] = self::mergePermissions($this->abilityPermissions[$ability] ?? [], $permissions);
        }

        $this->registerAbilities(array_keys($map));
    }

    /**
     * Voeg permissies samen; strings ontdubbeld, closures altijd erbij (twee
     * verschillende closures zijn voor array_unique gelijk).
     *
     * @param array<int, string|\Closure> $current
     * @param string|\Closure|array<int, string|\Closure> $add
     * @return array<int, string|\Closure>
     */
    private static function mergePermissions(array $current, string|\Closure|array $add): array
    {
        foreach (is_array($add) ? $add : [$add] as $permission) {
            if ($permission instanceof \Closure || ! in_array($permission, $current, true)) {
                $current[] = $permission;
            }
        }

        return $current;
    }

    /** @return array<string, array<int, string|\Closure>> */
    public function abilityPermissions(): array
    {
        return $this->abilityPermissions;
    }

    /**
     * De CMS-permissies achter een app-ability, of null als er geen koppeling is.
     *
     * @return array<int, string|\Closure>|null
     */
    public function permissionsForAbility(string $ability): ?array
    {
        return $this->abilityPermissions[$ability] ?? null;
    }

    /**
     * @deprecated Rechten in de app komen uit de CMS-permissies van de rollen
     *             (registerAbilityPermissions), niet meer uit de rolnaam. Blijft
     *             bestaan zodat oudere pakketten die dit aanroepen niet breken,
     *             maar doet niets.
     *
     * @param array<string, array<int, string>> $bySlug
     */
    public function registerRoleAbilities(array $bySlug): void
    {
    }

    /**
     * Koppel secties van het dagoverzicht (sleutel van de summary-contributor)
     * aan de CMS-permissie(s) die nodig zijn om ze in de app te zien, any-of,
     * met dezelfde vorm als registerAbilityPermissions(). Een sectie zonder
     * koppeling ziet elke beheerder met dashboard.read.
     *
     * @param array<string, string|\Closure|array<int, string|\Closure>> $map
     */
    public function registerSummarySectionPermissions(array $map): void
    {
        foreach ($map as $key => $permissions) {
            $this->summarySectionPermissions[$key] = self::mergePermissions($this->summarySectionPermissions[$key] ?? [], $permissions);
        }
    }

    /** @return array<int, string|\Closure>|null */
    public function permissionsForSummarySection(string $key): ?array
    {
        return $this->summarySectionPermissions[$key] ?? null;
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
        $this->appPages[$key] = array_merge($this->appPages[$key] ?? [], $meta);
        // De ability moet bij de AbilityResolver bekend zijn, anders is de
        // pagina voor niemand zichtbaar (ook admins niet) — automatisch aanmelden.
        $this->registerAbilities([(string) ($meta['ability'] ?? 'dashboard.read')]);
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

    /**
     * @deprecated Zie registerRoleAbilities(); altijd leeg.
     *
     * @return array<string, array<int, string>>
     */
    public function roleAbilities(): array
    {
        return [];
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
     * /customer/<email>, /conversation/7). Providers respecteren de actieve site.
     *
     * Rechten: met `$ability` wordt de provider alleen aangeroepen voor een
     * gebruiker met dat recht. Zonder `$ability` filtert de /search-endpoint elk
     * item op zijn `type` (zie SearchAccess::TYPE_ABILITIES); een onbekend type
     * vraagt dan `dashboard.read`.
     */
    public function registerSearchProvider(callable $provider, ?string $ability = null): void
    {
        $this->searchProviders[] = ['provider' => $provider, 'ability' => $ability];
    }

    /** @return array<int, callable> */
    public function searchProviders(): array
    {
        return array_column($this->searchProviders, 'provider');
    }

    /** @return array<int, array{provider: callable, ability: string|null}> */
    public function searchProviderEntries(): array
    {
        return $this->searchProviders;
    }

    /**
     * AI-copilot context: elke bijdrager geeft per site een leesbaar tekstblok
     * met actuele cijfers/feiten waarop de assistent zijn antwoorden baseert.
     *
     * Met $permissions (zelfde vorm als bij registerAbilityPermissions(),
     * any-of) krijgt alleen een gebruiker met dat recht dit blok in zijn
     * context; zonder elke beheerder.
     *
     * @param string|\Closure|array<int, string|\Closure>|null $permissions
     */
    public function registerCopilotContext(callable $contributor, string|\Closure|array|null $permissions = null): void
    {
        $this->copilotContextContributors[] = $contributor;
        $this->copilotContextPermissions[] = $permissions === null
            ? null
            : (is_array($permissions) ? array_values($permissions) : [$permissions]);
    }

    /** @return array<int, callable> */
    public function copilotContextContributors(): array
    {
        return $this->copilotContextContributors;
    }

    /**
     * Bouw de volledige copilot-context voor een site (alle bijdragers
     * samengevoegd). Met $user alleen de blokken waar die gebruiker recht op
     * heeft (zie registerCopilotContext()).
     */
    public function copilotContext(string $siteId, ?\Dashed\DashedCore\Models\User $user = null): string
    {
        $blocks = [];
        foreach ($this->copilotContextContributors as $index => $contributor) {
            $permissions = $this->copilotContextPermissions[$index] ?? null;
            if ($user !== null && $permissions !== null && ! \Dashed\DashedMobileApi\Support\PermissionCheck::any($user, $permissions)) {
                continue;
            }

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
