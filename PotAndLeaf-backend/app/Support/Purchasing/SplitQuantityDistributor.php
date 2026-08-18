<?php

namespace App\Support\Purchasing;

/**
 * Distributes a bulk quantity into split rows for qty-per-split or num-splits modes.
 */
class SplitQuantityDistributor
{
    /** @return list<float> */
    public static function byQtyPerSplit(float $total, float $qtyPerSplit): array
    {
        if ($qtyPerSplit <= 0 || $total <= 0) {
            return [];
        }

        $splits = [];
        $remaining = round($total, 3);

        while ($remaining > 0.0001) {
            $qty = min($qtyPerSplit, $remaining);
            $qty = round($qty, 3);
            $splits[] = $qty;
            $remaining = round($remaining - $qty, 3);
        }

        return $splits;
    }

    /** @return list<float> */
    public static function byNumSplits(float $total, int $numSplits): array
    {
        if ($numSplits <= 0 || $total <= 0) {
            return [];
        }

        $totalRounded = round($total, 3);
        $isWhole = abs($totalRounded - round($totalRounded)) < 0.0001;

        if ($isWhole) {
            $totalInt = (int) round($totalRounded);
            $base = intdiv($totalInt, $numSplits);
            $remainder = $totalInt % $numSplits;
            $splits = array_fill(0, $numSplits, (float) $base);
            for ($i = 0; $i < $remainder; $i++) {
                $splits[$i]++;
            }

            return $splits;
        }

        $base = floor(($totalRounded / $numSplits) * 1000) / 1000;
        $splits = array_fill(0, $numSplits, $base);
        $allocated = round($base * $numSplits, 3);
        $diff = round($totalRounded - $allocated, 3);
        $i = 0;
        while (abs($diff) > 0.0001 && $i < $numSplits) {
            $adjust = $diff > 0 ? min(0.001, $diff) : max(-0.001, $diff);
            $splits[$i] = round($splits[$i] + $adjust, 3);
            $diff = round($diff - $adjust, 3);
            $i++;
        }

        return $splits;
    }
}
