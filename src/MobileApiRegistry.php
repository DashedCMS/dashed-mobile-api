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

    /** @var array<string, array<string, mixed>> */
    private array $notificationTypes = [];


    /** @var array<int, callable> */
    private array $capabilityContextContributors = [];

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
}
