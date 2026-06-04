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
}
