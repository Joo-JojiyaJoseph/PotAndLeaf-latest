<?php

namespace App\Support\Purchasing;

/**
 * The single source of truth for purchase arithmetic — GST split, landed-cost
 * allocation, and header totals. Kept free of Eloquent so it's trivially
 * testable, and mirrored line-for-line by the SPA so the on-screen preview
 * matches what the server persists.
 *
 * GST model (India): intra-state supply splits the rate into CGST + SGST
 * (half each); inter-state supply charges the full rate as IGST.
 * Landed cost (freight, loading, damage) is NOT part of the supplier payable —
 * it's distributed across lines to derive the true landed unit cost for stock.
 */
class PurchaseCalculator
{
    /**
     * @param array<int, array{qty:mixed, rate:mixed, discount?:mixed, gst_rate?:mixed}> $items
     * @return array{items: array<int, array<string, float>>, totals: array<string, float>}
     */
    public function compute(array $items, bool $isInterstate, float $landedCostTotal = 0.0): array
    {
        $lines = [];
        $subtotal = 0.0;
        $discountTotal = 0.0;

        // Pass 1: taxable values (needed before we can allocate landed cost).
        foreach ($items as $item) {
            $qty = max(0.0, (float) ($item['qty'] ?? 0));
            $rate = max(0.0, (float) ($item['rate'] ?? 0));
            $discount = max(0.0, (float) ($item['discount'] ?? 0));
            $taxable = round(max(0.0, $qty * $rate - $discount), 2);

            $lines[] = [
                'qty' => $qty,
                'rate' => $rate,
                'discount' => $discount,
                'taxable_value' => $taxable,
                'gst_rate' => max(0.0, (float) ($item['gst_rate'] ?? 0)),
            ];

            $subtotal += $taxable;
            $discountTotal += $discount;
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = 0.0;
        $allocatedSoFar = 0.0;
        $count = count($lines);

        foreach ($lines as $i => &$line) {
            // GST split
            $gst = round($line['taxable_value'] * $line['gst_rate'] / 100, 2);
            if ($isInterstate) {
                $line['igst_amount'] = $gst;
                $line['cgst_amount'] = 0.0;
                $line['sgst_amount'] = 0.0;
            } else {
                $cgst = round($gst / 2, 2);
                $line['cgst_amount'] = $cgst;
                $line['sgst_amount'] = round($gst - $cgst, 2); // keep halves summing exactly
                $line['igst_amount'] = 0.0;
            }
            $line['line_total'] = round($line['taxable_value'] + $gst, 2);
            $taxTotal += $gst;

            // Landed-cost allocation proportional to taxable value.
            // Give the last line the remainder so allocations sum exactly.
            if ($landedCostTotal > 0 && $subtotal > 0) {
                if ($i === $count - 1) {
                    $alloc = round($landedCostTotal - $allocatedSoFar, 2);
                } else {
                    $alloc = round($landedCostTotal * ($line['taxable_value'] / $subtotal), 2);
                    $allocatedSoFar += $alloc;
                }
            } else {
                $alloc = 0.0;
            }
            $line['landed_alloc'] = $alloc;
            $line['landed_unit_cost'] = $line['qty'] > 0
                ? round(($line['taxable_value'] + $alloc) / $line['qty'], 4)
                : 0.0;
        }
        unset($line);

        $taxTotal = round($taxTotal, 2);

        return [
            'items' => $lines,
            'totals' => [
                'subtotal' => $subtotal,
                'discount_total' => round($discountTotal, 2),
                'tax_total' => $taxTotal,
                'landed_cost_total' => round($landedCostTotal, 2),
                'grand_total' => round($subtotal + $taxTotal, 2),
            ],
        ];
    }
}
