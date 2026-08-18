<?php

namespace App\Support\Purchasing;

/**
 * Redistributes a bulk product's total cost across split outputs, proportional
 * to qty × weight (weight = relative size/value; default 1 makes it purely
 * qty-proportional). The last line absorbs the rounding remainder so the parts
 * sum exactly to the total cost.
 */
class BulkSplitCalculator
{
    /**
     * @param array<int, array{qty:mixed, weight?:mixed}> $outputs
     * @return array<int, array{qty:float, weight:float, cost_alloc:float, unit_cost:float}>
     */
    public function allocate(float $totalCost, array $outputs): array
    {
        $rows = [];
        $totalWeight = 0.0;
        foreach ($outputs as $o) {
            $qty = max(0.0, (float) ($o['qty'] ?? 0));
            $weight = max(0.0, (float) ($o['weight'] ?? 1)) ?: 1.0;
            $rows[] = ['qty' => $qty, 'weight' => $weight];
            $totalWeight += $qty * $weight;
        }

        $count = count($rows);
        $allocated = 0.0;
        foreach ($rows as $i => &$row) {
            if ($totalWeight <= 0) {
                $alloc = 0.0;
            } elseif ($i === $count - 1) {
                $alloc = round($totalCost - $allocated, 2);
            } else {
                $alloc = round($totalCost * ($row['qty'] * $row['weight']) / $totalWeight, 2);
                $allocated += $alloc;
            }
            $row['cost_alloc'] = $alloc;
            $row['unit_cost'] = $row['qty'] > 0 ? round($alloc / $row['qty'], 4) : 0.0;
        }
        unset($row);

        return $rows;
    }
}
