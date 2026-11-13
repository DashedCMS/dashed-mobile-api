<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Commands;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedMobileApi\Support\NotificationCenter;
use Dashed\DashedCore\Services\Summary\DailySummaryBuilder;

/**
 * Stuurt per site één digest-push ("Je dagoverzicht staat klaar") naar de
 * app-gebruikers die het notificatietype `digest.daily` aan hebben staan.
 * Draait dagelijks via de scheduler. Hergebruikt DailySummaryBuilder (dezelfde
 * data als de samenvatting-mail en het dagoverzicht-scherm). De ontvanger-
 * filtering — recht, per-site voorkeur én stille uren — loopt automatisch via
 * de push-pipeline (NotificationCenter → ExpoPushService::notifyAbility).
 */
class DispatchDigestPushCommand extends Command
{
    protected $signature = 'dashed:dispatch-digest-push {--date= : Overzichtsdag (Y-m-d), standaard gisteren}';

    protected $description = 'Stuurt de dagelijkse dagoverzicht-push per site naar de app.';

    public function handle(): int
    {
        if (! class_exists(DailySummaryBuilder::class)) {
            $this->warn('DailySummaryBuilder ontbreekt; digest-push overgeslagen.');

            return self::SUCCESS;
        }

        $input = trim((string) $this->option('date'));
        try {
            $date = $input !== '' ? Carbon::parse($input) : Carbon::yesterday();
        } catch (\Throwable) {
            $this->error('Ongeldige datum.');

            return self::INVALID;
        }

        $sites = Sites::getSites();
        if (! $sites) {
            $this->info('Geen sites geconfigureerd.');

            return self::SUCCESS;
        }

        // Onthoud de actieve site en herstel 'm na afloop, zodat deze command
        // geen site-context achterlaat voor de rest van het proces.
        $previousSite = config('dashed-core.dashed_site_id');
        $sent = 0;

        foreach ($sites as $site) {
            $siteId = (string) ($site['id'] ?? '');
            if ($siteId === '') {
                continue;
            }

            // Scope alle thisSite()-queries in de builder op deze site.
            config(['dashed-core.dashed_site_id' => $siteId]);

            try {
                $summary = DailySummaryBuilder::buildForDate($date);
            } catch (\Throwable $e) {
                report($e);

                continue;
            }

            if (($summary['sections'] ?? []) === []) {
                continue;
            }

            app(NotificationCenter::class)->push()
                ->type('digest.daily')
                ->site($siteId)
                ->title(__('Dagoverzicht'))
                ->body($this->teaser($summary))
                ->route('/dagoverzicht')
                ->data(['type' => 'digest', 'date' => $summary['date'] ?? $date->toDateString()])
                ->send();

            $sent++;
        }

        config(['dashed-core.dashed_site_id' => $previousSite]);

        $this->info("Digest-push verstuurd voor {$sent} site(s).");

        return self::SUCCESS;
    }

    /**
     * Korte teaser voor de push-body: de AI-briefingtekst als die er is, anders
     * een nette terugval op het periodelabel. Gebruikt alleen de gedocumenteerde
     * block-vorm ({type:'text', data:{content}}), niet de interne cijfer-blocks
     * (die verschillen per bijdragend package).
     *
     * @param array{date?:string,label?:string,sections?:array<int,array{blocks?:array<int,array<string,mixed>>}>} $summary
     */
    private function teaser(array $summary): string
    {
        foreach (($summary['sections'] ?? []) as $section) {
            foreach (($section['blocks'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content = trim((string) ($block['data']['content'] ?? ''));
                    if ($content !== '') {
                        return Str::limit($content, 140);
                    }
                }
            }
        }

        $label = trim((string) ($summary['label'] ?? ''));

        return $label !== ''
            ? __('Je overzicht van :label staat klaar.', ['label' => $label])
            : __('Je dagoverzicht staat klaar.');
    }
}
