<?php

namespace App\Support\Commission;

/**
 * Pure slab math for salesman commission.
 *
 * marginal: each slice of the period total is paid at that slab's rate.
 * flat: the whole period total is paid at the single slab it falls in.
 * The value returned is the incremental amount for the movement from $before to $after,
 * so earlier ledger rows are left untouched when a later sale crosses a slab.
 */
class TierCalculator
{
    /**
     * @param  array<int, array{min_amount: float|int|string, max_amount: float|int|string|null, percent: float|int|string}>  $tiers
     */
    public static function amount(float $before, float $after, array $tiers, string $mode = 'marginal'): float
    {
        if ($tiers === [] || $after == $before) {
            return 0.0;
        }

        $total = $mode === 'flat'
            ? self::flatTotal($after, $tiers) - self::flatTotal($before, $tiers)
            : self::marginalTotal($after, $tiers) - self::marginalTotal($before, $tiers);

        return round($total, 2);
    }

    /**
     * @param  array<int, array{min_amount: float|int|string, max_amount: float|int|string|null, percent: float|int|string}>  $tiers
     */
    public static function marginalTotal(float $total, array $tiers): float
    {
        $sum = 0.0;
        foreach (self::sorted($tiers) as $tier) {
            $min = (float) $tier['min_amount'];
            $max = $tier['max_amount'] === null ? INF : (float) $tier['max_amount'];
            if ($total <= $min) {
                break;
            }
            $slice = min($total, $max) - $min;
            if ($slice > 0) {
                $sum += $slice * (float) $tier['percent'] / 100;
            }
        }

        return $sum;
    }

    /**
     * @param  array<int, array{min_amount: float|int|string, max_amount: float|int|string|null, percent: float|int|string}>  $tiers
     */
    public static function flatTotal(float $total, array $tiers): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        $matched = null;
        foreach (self::sorted($tiers) as $tier) {
            $min = (float) $tier['min_amount'];
            $max = $tier['max_amount'] === null ? INF : (float) $tier['max_amount'];
            if ($total > $min && $total <= $max) {
                $matched = $tier;
                break;
            }
        }

        if ($matched === null) {
            return 0.0;
        }

        return $total * (float) $matched['percent'] / 100;
    }

    /**
     * @param  array<int, array{min_amount: float|int|string, max_amount: float|int|string|null, percent: float|int|string}>  $tiers
     * @return array<int, array{min_amount: float|int|string, max_amount: float|int|string|null, percent: float|int|string}>
     */
    private static function sorted(array $tiers): array
    {
        usort($tiers, fn ($a, $b) => (float) $a['min_amount'] <=> (float) $b['min_amount']);

        return $tiers;
    }
}
