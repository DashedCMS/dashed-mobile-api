<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Carbon\CarbonImmutable;

/**
 * Bepaalt het tijdsbereik en de bucket-indeling voor een dashboard-periode.
 * Rolling windows die op "nu" eindigen.
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
    ) {
    }

    public static function fromRequest(?string $key): self
    {
        $key = in_array($key, self::KEYS, true) ? $key : 'today';
        $now = CarbonImmutable::now();

        return match ($key) {
            'week' => new self('week', $now->subDays(6)->startOfDay(), $now, 'day'),
            'month' => new self('month', $now->subDays(29)->startOfDay(), $now, 'day'),
            'year' => new self('year', $now->subMonths(11)->startOfMonth(), $now, 'month'),
            default => new self('today', $now->startOfDay(), $now, 'hour'),
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

        while ($cursor < $this->end) {
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
