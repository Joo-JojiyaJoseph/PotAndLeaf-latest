<?php

namespace App\Support\Season;

use Illuminate\Support\Carbon;

/** Nursery seasons used across India: summer, monsoon, and winter. */
class IndianSeason
{
    public const MONTHS = [
        'summer'  => [3, 4, 5],
        'monsoon' => [6, 7, 8, 9],
        'winter'  => [10, 11, 12, 1, 2],
    ];

    public static function forDate(Carbon|string $date): string
    {
        $month = (int) Carbon::parse($date)->format('n');
        foreach (self::MONTHS as $season => $months) {
            if (in_array($month, $months, true)) {
                return $season;
            }
        }

        return 'winter';
    }

    /** First few days of 1 Mar, 1 Jun, and 1 Oct, so a missed cron still sends once. */
    public static function isStartWindow(Carbon|string $date, int $days = 3): bool
    {
        $current = Carbon::parse($date)->startOfDay();
        foreach ([3, 6, 10] as $month) {
            $start = Carbon::create($current->year, $month, 1)->startOfDay();
            if ($current->greaterThanOrEqualTo($start) && $current->lessThan($start->copy()->addDays($days))) {
                return true;
            }
        }

        return false;
    }

    /** Winter (Oct–Feb) shares one year key so January does not look like a new season. */
    public static function yearKey(Carbon|string $date): int
    {
        $current = Carbon::parse($date);
        if (self::forDate($current) === 'winter' && (int) $current->format('n') <= 2) {
            return (int) $current->format('Y') - 1;
        }

        return (int) $current->format('Y');
    }
}
