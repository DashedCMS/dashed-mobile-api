<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Carbon\CarbonImmutable;
use Dashed\DashedCore\Models\User;
use Dashed\DashedMobileApi\Models\UserQuietHours;

/**
 * Stille uren per gebruiker: een lokaal tijdvenster (met tijdzone) waarin geen
 * automatische push verstuurd wordt. Een testnotificatie die de gebruiker zelf
 * aanzet gaat er bewust wél doorheen, want die loopt niet via notifyAbility().
 */
class QuietHours
{
    private const DEFAULT_TZ = 'Europe/Amsterdam';

    /** @var array<int, bool> Memo per user-id binnen één push-batch. */
    private array $cache = [];

    /**
     * @return array{enabled: bool, start: string, end: string, timezone: string}
     */
    public function forUser(User $user): array
    {
        $row = UserQuietHours::query()->where('user_id', $user->id)->first();

        return [
            'enabled' => (bool) ($row->enabled ?? false),
            'start' => (string) ($row->start_time ?? '22:00'),
            'end' => (string) ($row->end_time ?? '07:00'),
            'timezone' => (string) ($row->timezone ?? self::DEFAULT_TZ),
        ];
    }

    public function set(User $user, bool $enabled, string $start, string $end, string $timezone): void
    {
        UserQuietHours::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['enabled' => $enabled, 'start_time' => $start, 'end_time' => $end, 'timezone' => $timezone],
        );

        unset($this->cache[$user->id]);
    }

    /**
     * Zit de gebruiker nu in zijn stille uren? Geen rij of uitgeschakeld → nee.
     */
    public function isQuiet(User $user): bool
    {
        if (array_key_exists($user->id, $this->cache)) {
            return $this->cache[$user->id];
        }

        return $this->cache[$user->id] = $this->compute($user);
    }

    private function compute(User $user): bool
    {
        $row = UserQuietHours::query()->where('user_id', $user->id)->first();

        if (! $row || ! $row->enabled) {
            return false;
        }

        $start = self::minutes((string) $row->start_time);
        $end = self::minutes((string) $row->end_time);
        if ($start === null || $end === null) {
            return false;
        }

        $tz = (string) ($row->timezone ?: self::DEFAULT_TZ);
        try {
            $now = CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $now = CarbonImmutable::now(self::DEFAULT_TZ);
        }

        return self::windowContains($now->hour * 60 + $now->minute, $start, $end);
    }

    /**
     * Valt `$cur` (minuten sinds middernacht) binnen [start, end)? Een venster
     * met eind <= begin loopt over middernacht heen (bv. 22:00–07:00).
     */
    public static function windowContains(int $cur, int $start, int $end): bool
    {
        if ($start === $end) {
            return false;
        }

        if ($start < $end) {
            return $cur >= $start && $cur < $end;
        }

        return $cur >= $start || $cur < $end;
    }

    public static function minutes(string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }

        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return $h * 60 + $min;
    }
}
