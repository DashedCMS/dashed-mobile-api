<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi;

use Spatie\LaravelPackageTools\Package;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Dashed\DashedMobileApi\Support\ExpoPushService;
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
                'create_personal_access_tokens_table',
                'create_dashed_device_tokens_table',
            ])
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MobileApiRegistry::class);
    }

    public function bootingPackage(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('mobile.site', EnsureSiteContext::class);
        $router->aliasMiddleware('ability', CheckForAnyAbility::class);
        $router->aliasMiddleware('abilities', CheckAbilities::class);

        /** @var MobileApiRegistry $registry */
        $registry = $this->app->make(MobileApiRegistry::class);
        $registry->registerAbilities(['dashboard.read', 'devices.write']);
        $registry->registerRoleAbilities([
            'eigenaar' => ['dashboard.read'],
            'admin' => ['dashboard.read'],
            'shopbeheerder' => ['dashboard.read'],
            'support-agent' => ['dashboard.read'],
            'read-only' => ['dashboard.read'],
        ]);

        // Push-notificatie bij een nieuwe bestelling (luistert op de classnaam, zodat
        // er geen harde dependency op dashed-ecommerce-core ontstaat).
        Event::listen('Dashed\\DashedEcommerceCore\\Events\\Orders\\OrderCreatedEvent', static function ($event): void {
            $order = $event->order ?? null;
            if (! $order) {
                return;
            }
            $name = trim((string) (($order->first_name ?? '') . ' ' . ($order->last_name ?? ''))) ?: ($order->email ?? 'Onbekend');
            $total = number_format((float) ($order->total ?? 0), 2, ',', '.');

            app(\Dashed\DashedMobileApi\Support\NotificationCenter::class)->push()
                ->title('Nieuwe bestelling')
                ->body("€ {$total} — {$name}")
                ->sound('order')
                ->route("/order/{$order->id}")
                ->data(['type' => 'order', 'id' => $order->id])
                ->toAbility('orders.read')
                ->send();
        });
    }
}
