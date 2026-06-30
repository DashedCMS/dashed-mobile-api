<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Illuminate\Mail\Mailable;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedCore\Notifications\DTOs\TelegramSummary;

/**
 * Spiegelt de Telegram-meldingen (alle Mailables die SendsToTelegram
 * implementeren) naar app-push-notificaties. Zo kun je in de app exact dezelfde
 * meldingen aanzetten als op Telegram. De AdminNotifier roept push() aan voor
 * elke verstuurde admin-mailable; staat de klasse in de catalogus, dan gaat er
 * ook een app-notificatie uit (van het bijbehorende, instelbare type).
 *
 * Mailables die al een eigen app-notificatietype hebben (order betaald/
 * geannuleerd, formulier-inzending) staan bewust NIET in de catalogus, zodat
 * je daar geen dubbele melding van krijgt.
 */
class AdminNotificationCatalog
{
    /**
     * mailable-FQN => [key, label|null, description|null, group|null, ability|null, default|null]
     * Een null-label betekent: hergebruik een bestaand type (niet opnieuw registreren).
     *
     * @return array<string, array{0:string,1:?string,2:?string,3:?string,4:?string,5:?bool}>
     */
    public static function map(): array
    {
        return [
            // Popups
            'Dashed\\DashedPopups\\Mail\\PopupConversionMail' => ['popup.conversion', 'Popup-inzending', 'Iemand heeft een popup ingevuld.', 'Popups', 'dashboard.read', true],

            // Producten / voorraad — hergebruikt het bestaande 'stock.low'-type.
            'Dashed\\DashedEcommerceCore\\Mail\\ProductOnLowStockEmail' => ['stock.low', null, null, null, null, null],
            'Dashed\\DashedEcommerceCore\\Mail\\ProductsWithPastDuePreOrderDateMail' => ['product.preorder_due', 'Pre-order datum verstreken', 'Een pre-order-leverdatum is verstreken.', 'Producten', 'products.read', false],

            // Bestellingen (aanvullend op de event-types order.paid/cancelled/…)
            'Dashed\\DashedEcommerceCore\\Mail\\AdminPaymentStartFailedMail' => ['order.payment_failed', 'Betaling mislukt', 'Een betaling kon niet gestart worden.', 'Bestellingen', 'orders.read', false],
            'Dashed\\DashedEcommerceCore\\Mail\\AdminPreOrderConfirmationMail' => ['order.preorder', 'Pre-order geplaatst', 'Er is een pre-order geplaatst.', 'Bestellingen', 'orders.read', false],

            // Financieel
            'Dashed\\DashedEcommerceCore\\Mail\\FinanceReportMail' => ['finance.report', 'Financieel rapport', 'Een financieel rapport staat klaar.', 'Financieel', 'dashboard.read', false],

            // Exports (één type voor alle export-klaar-meldingen)
            'Dashed\\DashedEcommerceCore\\Mail\\FinanceExportMail' => ['export.ready', 'Export klaar', 'Een export staat klaar om te downloaden.', 'Exports', 'dashboard.read', false],
            'Dashed\\DashedEcommerceCore\\Mail\\OrderListExportMail' => ['export.ready', null, null, null, null, null],
            'Dashed\\DashedEcommerceCore\\Mail\\ProductListExportMail' => ['export.ready', null, null, null, null, null],
            'Dashed\\DashedForms\\Mail\\FormInputsExportMail' => ['export.ready', null, null, null, null, null],

            // Systeem
            'Dashed\\DashedCore\\Mail\\JobFailedMail' => ['system.job_failed', 'Taak mislukt', 'Een achtergrondtaak is mislukt.', 'Systeem', 'dashboard.read', false],
            'Dashed\\DashedCore\\Mail\\NewAdminAccountMail' => ['system.new_admin', 'Nieuw beheerder', 'Er is een nieuw beheerdersaccount aangemaakt.', 'Systeem', 'dashboard.read', false],

            // Marketing / content (één type voor de herinneringen)
            'Dashed\\DashedMarketing\\Mail\\HolidayReminderMail' => ['marketing.reminder', 'Marketing-herinnering', 'Een content-/marketingherinnering.', 'Marketing', 'dashboard.read', false],
            'Dashed\\DashedMarketing\\Mail\\PostMissedMail' => ['marketing.reminder', null, null, null, null, null],
            'Dashed\\DashedMarketing\\Mail\\PostsDueTodayMail' => ['marketing.reminder', null, null, null, null, null],
            'Dashed\\DashedMarketing\\Mail\\WeeklyGapsMail' => ['marketing.reminder', null, null, null, null, null],
        ];
    }

    /** Registreer alle (unieke, nieuw te tonen) types in de app-instellingen. */
    public static function registerTypes(MobileApiRegistry $registry): void
    {
        if (! method_exists($registry, 'registerNotificationTypes')) {
            return;
        }

        $seen = [];
        foreach (self::map() as [$key, $label, $description, $group, $ability, $default]) {
            if ($label === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $registry->registerNotificationTypes([[
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'group' => $group,
                'sound' => 'default',
                'ability' => $ability,
                'default' => (bool) $default,
            ]]);
        }
    }

    /** Stuur — indien gemapt — een app-push voor deze admin-mailable. */
    public static function push(Mailable $mailable, TelegramSummary $summary): void
    {
        $entry = self::map()[$mailable::class] ?? null;
        if (! $entry) {
            return;
        }

        $key = $entry[0];

        // Korte body uit de samenvatting: de eerste paar veldwaarden. Sla velden
        // over die gelijk zijn aan de titel — PushNotification zet er al
        // "{titel} — {body}" voor, anders staat de naam er dubbel in
        // (bv. popup "Welkom" => "Welkom — Welkom · e-mail").
        $details = [];
        $titleNorm = trim((string) $summary->title);
        foreach ($summary->fields as $value) {
            if (is_string($value) && trim($value) !== '' && trim($value) !== '-' && trim($value) !== $titleNorm) {
                $details[] = trim($value);
            }
            if (count($details) >= 2) {
                break;
            }
        }
        $body = $details ? implode(' · ', $details) : $titleNorm;

        app(NotificationCenter::class)->push()
            ->type($key)
            ->title($summary->title)
            ->body($body)
            ->route('/notifications')
            ->data(['type' => 'admin', 'key' => $key])
            ->send();
    }
}
