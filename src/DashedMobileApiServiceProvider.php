<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi;

use Spatie\LaravelPackageTools\Package;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedMobileApi\Support\ExpoPushService;
use Dashed\DashedMobileApi\Http\Middleware\EnsureSiteContext;
use Dashed\DashedMobileApi\Http\Middleware\EnsureCurrentAbility;

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
                'create_user_notification_preferences_table',
                'create_user_order_origin_preferences_table',
                'add_site_id_to_user_notification_preferences_table',
                'create_mobile_notifications_table',
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
        // Autoriseer op de ACTUELE rol-rechten (niet de in het token gebakken
        // abilities), zodat nieuwe rechten meteen werken zonder re-login/refresh.
        $router->aliasMiddleware('ability', EnsureCurrentAbility::class);
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

        // Telegram-pariteit: alle admin-meldingen die naar Telegram gaan, ook als
        // instelbare app-notificatietypes registreren (popups, exports, systeem, …).
        \Dashed\DashedMobileApi\Support\AdminNotificationCatalog::registerTypes($registry);

        // Push-notificaties bij order-events (luisteren op classnaam, zodat er
        // geen harde dependency op dashed-ecommerce-core ontstaat). Elke push is
        // aan een type én de order-origin gekoppeld, zodat de per-gebruiker
        // voorkeuren (type aan/uit + gekozen origins) de ontvangers filteren.
        $orderPush = static function ($event, string $type, string $heading): void {
            $order = $event->order ?? null;
            if (! $order) {
                return;
            }
            $name = trim((string) (($order->first_name ?? '') . ' ' . ($order->last_name ?? ''))) ?: ($order->email ?? 'Onbekend');
            $total = number_format((float) ($order->total ?? 0), 2, ',', '.');

            app(\Dashed\DashedMobileApi\Support\NotificationCenter::class)->push()
                ->type($type)
                ->orderOrigin($order->order_origin ?? 'own')
                ->title($heading)
                ->body("€ {$total} — {$name}")
                ->route("/order/{$order->id}")
                ->data(['type' => 'order', 'id' => $order->id])
                ->send();
        };

        Event::listen('Dashed\\DashedEcommerceCore\\Events\\Orders\\OrderCreatedEvent', static function ($event) use ($orderPush): void {
            $orderPush($event, 'order.payment_started', 'Betaling gestart');
        });
        Event::listen('Dashed\\DashedEcommerceCore\\Events\\Orders\\OrderMarkedAsPaidEvent', static function ($event) use ($orderPush): void {
            $orderPush($event, 'order.paid', 'Bestelling betaald');
        });
        Event::listen('Dashed\\DashedEcommerceCore\\Events\\Orders\\OrderCancelledEvent', static function ($event) use ($orderPush): void {
            $orderPush($event, 'order.cancelled', 'Bestelling geannuleerd');
        });
    }
}
