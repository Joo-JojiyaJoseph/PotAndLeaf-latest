<?php

namespace App\Support\Sales;

/**
 * Computes per-line taxable value, GST split (intra: CGST+SGST, inter: IGST),
 * line totals, and bill totals with a round-off to the nearest rupee.
 */
class SaleCalculator
{
    /**
     * @param array<int, array{qty:mixed, rate:mixed, discount?:mixed, gst_rate?:mixed}> $lines
     * @return array{lines: array<int, array<string,float>>, totals: array<string,float>}
     */
    public function compute(array $lines, bool $isInterstate): array
    {
        $out = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;

        foreach ($lines as $line) {
            $qty = (float) ($line['qty'] ?? 0);
            $rate = (float) ($line['rate'] ?? 0);
            $discount = (float) ($line['discount'] ?? 0);
            $gstRate = (float) ($line['gst_rate'] ?? 0);

            $taxable = max(0.0, round($qty * $rate - $discount, 2));
            $tax = round($taxable * $gstRate / 100, 2);

            $cgst = $sgst = $igst = 0.0;
            if ($isInterstate) {
                $igst = $tax;
            } else {
                $cgst = round($tax / 2, 2);
                $sgst = round($tax - $cgst, 2); // remainder keeps CGST+SGST == tax exactly
            }

            $lineTotal = round($taxable + $tax, 2);
            $subtotal += $taxable;
            $taxTotal += $tax;

            $out[] = [
                'taxable_value' => $taxable,
                'cgst_amount'   => $cgst,
                'sgst_amount'   => $sgst,
                'igst_amount'   => $igst,
                'line_total'    => $lineTotal,
            ];
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $grandRaw = $subtotal + $taxTotal;
        $grand = round($grandRaw);              // nearest rupee
        $roundOff = round($grand - $grandRaw, 2);

        return [
            'lines'  => $out,
            'totals' => [
                'subtotal'    => $subtotal,
                'tax_total'   => $taxTotal,
                'round_off'   => $roundOff,
                'grand_total' => $grand,
            ],
        ];
    }
}
