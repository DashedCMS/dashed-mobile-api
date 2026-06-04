<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi;

use Spatie\LaravelPackageTools\Package;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Dashed\DashedMobileApi\Http\Middleware\EnsureSiteContext;

class DashedMobileApiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('dashed-mobile-api')
            ->hasConfigFile()
            ->hasRoutes(['api'])
            ->hasMigrations([
                'create_dashed_device_tokens_table',
            ])
            ->runsMigrations();
    }

    public function bootingPackage(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('mobile.site', EnsureSiteContext::class);
        $router->aliasMiddleware('ability', CheckForAnyAbility::class);
        $router->aliasMiddleware('abilities', CheckAbilities::class);
    }
}
