<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi;

use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Spatie\LaravelPackageTools\PackageServiceProvider;
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
                'ensure_site_id_on_user_notification_preferences_table',
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

        // Nieuwsbriefmeldingen. Zelfde vorm als de order-pushes: luisteren op
        // classnaam, zodat er geen harde dependency op dashed-newsletter komt.
        // De twee schakelaars op een lijst bepalen of er iets uitgaat; die stonden
        // er al in het scherm maar deden tot nu toe niets.
        if (method_exists($registry, 'registerNotificationTypes')) {
            $registry->registerNotificationTypes([
                ['key' => 'newsletter.subscribed', 'label' => 'Nieuwe aanmelding', 'description' => 'Iemand heeft zich aangemeld voor een nieuwsbrieflijst.', 'group' => 'Nieuwsbrief', 'sound' => 'default', 'ability' => 'dashboard.read', 'default' => false],
                ['key' => 'newsletter.unsubscribed', 'label' => 'Afmelding', 'description' => 'Iemand heeft zich afgemeld voor een nieuwsbrieflijst.', 'group' => 'Nieuwsbrief', 'sound' => 'default', 'ability' => 'dashboard.read', 'default' => false],
            ]);
        }

        $newsletterPush = static function ($event, string $type, string $heading, string $toggle): void {
            $subscriber = $event->subscriber ?? null;
            $list = $subscriber?->list;

            // Geen lijst of de schakelaar staat uit: dan hoort er niets uit te
            // gaan. Bij een drukke lijst is elke aanmelding anders een melding.
            if (! $subscriber || ! $list || ! $list->{$toggle}) {
                return;
            }

            app(\Dashed\DashedMobileApi\Support\NotificationCenter::class)->push()
                ->type($type)
                ->title($heading)
                ->body($subscriber->email . ' — ' . $list->name)
                ->route("/newsletter/subscriber/{$subscriber->id}")
                ->data(['type' => 'newsletter_subscriber', 'id' => $subscriber->id, 'list_id' => $list->id])
                ->send();
        };

        // Sitescan. Zelfde vorm: luisteren op classnaam, dus geen harde
        // dependency op dashed-seo. De frequentie staat per site in het CMS, of
        // de melding aankomt bepaalt de gebruiker in de app.
        if (method_exists($registry, 'registerNotificationTypes')) {
            $registry->registerNotificationTypes([
                // Standaard aan: een scan die je zelf hebt ingepland hoort ook
                // vanzelf iets van zich te laten horen. Uitzetten kan per
                // gebruiker in de app.
                ['key' => 'seo.audit_completed', 'label' => 'Sitescan afgerond', 'description' => 'Een technische sitescan is klaar, met de score en wat er gevonden is.', 'group' => 'SEO', 'sound' => 'default', 'ability' => 'dashboard.read', 'default' => true],
            ]);
        }

        Event::listen('Dashed\\DashedSeo\\Events\\SiteAuditCompleted', static function ($event): void {
            $audit = $event->audit ?? null;

            if (! $audit) {
                return;
            }

            $score = $audit->health_score;
            $previous = method_exists($audit, 'previous') ? $audit->previous() : null;
            $delta = ($previous && $previous->health_score !== null && $score !== null)
                ? (int) $score - (int) $previous->health_score
                : null;

            // Het verschil met de vorige scan is het hele punt van een
            // periodieke melding: een score van 82 zegt weinig, 82 na 91 wel.
            $verschil = $delta === null ? '' : sprintf(' (%s%d)', $delta >= 0 ? '+' : '', $delta);

            app(\Dashed\DashedMobileApi\Support\NotificationCenter::class)->push()
                ->type('seo.audit_completed')
                ->title(__('Sitescan afgerond'))
                ->body(__('Score :score:verschil — :fouten fouten, :waarschuwingen waarschuwingen op :paginas pagina\'s.', [
                    'score' => $score ?? '-',
                    'verschil' => $verschil,
                    'fouten' => (int) $audit->error_count,
                    'waarschuwingen' => (int) $audit->warning_count,
                    'paginas' => (int) $audit->pages_crawled,
                ]))
                ->site($audit->site_id)
                ->route('/notifications')
                ->data(['type' => 'site_audit', 'id' => $audit->id])
                ->send();
        });

        Event::listen('Dashed\\DashedNewsletter\\Events\\NewsletterSubscribedEvent', static function ($event) use ($newsletterPush): void {
            $newsletterPush($event, 'newsletter.subscribed', 'Nieuwe aanmelding', 'notify_on_subscribe');
        });
        Event::listen('Dashed\\DashedNewsletter\\Events\\NewsletterUnsubscribedEvent', static function ($event) use ($newsletterPush): void {
            $newsletterPush($event, 'newsletter.unsubscribed', 'Afmelding', 'notify_on_unsubscribe');
        });

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
