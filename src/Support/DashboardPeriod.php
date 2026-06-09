<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Carbon\CarbonImmutable;

/**
 * Bepaalt het tijdsbereik en de bucket-indeling voor een dashboard-periode.
 * Ondersteunt zowel de huidige kalenderperiode (vandaag/deze week/…) als een
 * specifieke periode via een `anchor`-datum, zodat de app per dag/week/maand/
 * jaar kan terug- en vooruitbladeren en met de vorige periode kan vergelijken.
 */
class DashboardPeriod
{
    /** @var array<int, string> */
    public const KEYS = ['today', 'week', 'month', 'year'];

    private function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $granularity, // 'hour' | 'day' | 'month'
        public readonly string $label = '',
        public readonly bool $isCurrent = true,
        public readonly ?string $anchor = null,
        public readonly ?string $prevAnchor = null,
        public readonly ?string $nextAnchor = null,
        // Volledige periode-einde voor de grafiek-buckets. `end` loopt voor de
        // lopende periode tot "nu" (voor de totalen); de grafiek toont de hele
        // periode (bv. alle 7 weekdagen), waar toekomstige buckets simpelweg 0
        // zijn. Zonder dit had "deze week" op dag 1 maar één bucket → geen lijn.
        public readonly ?CarbonImmutable $bucketEnd = null,
    ) {
    }

    public static function fromRequest(?string $key, ?string $anchorStr = null): self
    {
        $key = in_array($key, self::KEYS, true) ? $key : 'today';
        $now = CarbonImmutable::now();

        try {
            $anchor = $anchorStr ? CarbonImmutable::parse($anchorStr) : $now;
        } catch (\Throwable) {
            $anchor = $now;
        }
        // Nooit een periode in de toekomst tonen.
        if ($anchor->greaterThan($now)) {
            $anchor = $now;
        }

        $granularity = match ($key) {
            'today' => 'hour',
            'year' => 'month',
            default => 'day',
        };

        $start = match ($key) {
            'week' => $anchor->startOfWeek(),
            'month' => $anchor->startOfMonth(),
            'year' => $anchor->startOfYear(),
            default => $anchor->startOfDay(),
        };
        $periodEnd = match ($key) {
            'week' => $anchor->endOfWeek(),
            'month' => $anchor->endOfMonth(),
            'year' => $anchor->endOfYear(),
            default => $anchor->endOfDay(),
        };
        $nextStart = match ($key) {
            'week' => $start->addWeek(),
            'month' => $start->addMonth(),
            'year' => $start->addYear(),
            default => $start->addDay(),
        };

        // Bevat deze periode "nu"? Dan is het de lopende periode (geen volgende).
        $isCurrent = $now->betweenIncluded($start, $periodEnd);
        // Voor de lopende periode loopt het bereik tot nu, niet tot het einde.
        $end = $periodEnd->greaterThan($now) ? $now : $periodEnd;

        $label = match ($key) {
            'week' => $isCurrent ? 'Deze week' : ('Week ' . $start->isoWeek() . ' · ' . $start->isoFormat('MMM YYYY')),
            'month' => $isCurrent ? 'Deze maand' : ucfirst($start->isoFormat('MMMM YYYY')),
            'year' => $isCurrent ? 'Dit jaar' : $start->format('Y'),
            default => $isCurrent ? 'Vandaag' : ucfirst($start->isoFormat('dd D MMM YYYY')),
        };

        return new self(
            $key,
            $start,
            $end,
            $granularity,
            $label,
            $isCurrent,
            $start->toDateString(),
            self::prevStart($key, $start)->toDateString(),
            $isCurrent ? null : $nextStart->toDateString(),
            $periodEnd,
        );
    }

    /**
     * Het vergelijkingsvenster: dezelfde duur, één periode eerder. Voor de
     * lopende periode is dat "tot nu toe" t.o.v. dezelfde tijd vorige periode.
     */
    public function previous(): self
    {
        $prevStart = self::prevStart($this->key, $this->start);
        $prevEnd = $prevStart->addSeconds((int) $this->start->diffInSeconds($this->end));

        return new self($this->key, $prevStart, $prevEnd, $this->granularity, '', false, $prevStart->toDateString());
    }

    private static function prevStart(string $key, CarbonImmutable $start): CarbonImmutable
    {
        return match ($key) {
            'week' => $start->subWeek(),
            'month' => $start->subMonth(),
            'year' => $start->subYear(),
            default => $start->subDay(),
        };
    }

    /**
     * Buckets voor de tijdreeks (omzetgrafiek).
     *
     * @return array<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function buckets(): array
    {
        $buckets = [];
        $cursor = $this->start;
        $until = $this->bucketEnd ?? $this->end;

        while ($cursor < $until) {
            $next = match ($this->granularity) {
                'hour' => $cursor->addHour(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };

            $label = match ($this->granularity) {
                'hour' => $cursor->format('H'),
                'month' => $cursor->isoFormat('MMM'),
                default => $cursor->format('d'),
            };

            $buckets[] = ['label' => $label, 'start' => $cursor, 'end' => $next];
            $cursor = $next;
        }

        return $buckets;
    }
}
