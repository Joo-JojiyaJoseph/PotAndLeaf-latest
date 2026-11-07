<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\SalesReturn;
use Illuminate\Support\Collection;

/**
 * Every movement of a customer's advance balance, with a
 * running balance — money in (advance received, extra money, sales returns)
 * and money out (used on a bill). Built from the records that change the
 * balance; anything not explained by them (balances from before these
 * records, hand edits) is shown as one "Earlier balance / adjustment" row,
 * so the last running balance always equals the advance shown on the page.
 * (Suppliers have the same list in SupplierAccountService.)
 */
class AdvanceHistoryService
{
    /** @return array{rows: array<int, array<string,mixed>>, balance: float, total_in: float, total_out: float} */
    public function customer(Customer $customer): array
    {
        $rows = collect();

        $receipts = CustomerReceipt::query()
            ->where('customer_id', $customer->id)
            ->with(['sale:id,sale_no,sale_date', 'allocations:id,customer_receipt_id,amount'])
            ->get();
        $bookingOrders = \App\Models\AdvanceOrder::query()
            ->whereIn('id', $receipts->pluck('advance_order_id')->filter()->unique())
            ->pluck('order_no', 'id');

        foreach ($receipts as $r) {
            $amount = round((float) $r->amount, 2);
            if ($r->applied_from_advance) {
                $rows->push($this->row($r->receipt_date, 'used', 'Used on bill', $r->sale?->sale_no ?? $r->receipt_no, 0, $amount, $r->created_at, ['sale_id' => $r->sale_id]));
            } elseif ($r->is_advance) {
                if ($r->advance_order_id) {
                    $rows->push($this->row($r->receipt_date, 'in', 'Booking advance', ($bookingOrders[$r->advance_order_id] ?? 'Advance order').' · '.$r->receipt_no, $amount, 0, $r->created_at));
                    if ($r->sale_id) {
                        $rows->push($this->row($r->sale?->sale_date ?? $r->receipt_date, 'used', 'Booking advance used on bill', $r->sale?->sale_no ?? '—', 0, $amount, $r->updated_at, ['sale_id' => $r->sale_id]));
                    }
                } else {
                    $rows->push($this->row($r->receipt_date, 'in', 'Advance received', $r->receipt_no, $amount, 0, $r->created_at));
                }
            } elseif (! $r->sale_id && ! $r->auto_applied && $r->allocations->isNotEmpty()) {
                // Paid against chosen bills; anything more was kept as advance.
                $extra = round($amount - (float) $r->allocations->sum('amount'), 2);
                if ($extra > 0.005) {
                    $rows->push($this->row($r->receipt_date, 'in', 'Extra money kept as advance', $r->receipt_no, $extra, 0, $r->created_at));
                }
            }
        }

        SalesReturn::query()
            ->where('customer_id', $customer->id)->where('status', 'confirmed')
            ->where('advance_credited', '>', 0)
            ->get(['id', 'return_no', 'return_date', 'advance_credited', 'confirmed_at', 'created_at'])
            ->each(fn ($s) => $rows->push($this->row($s->return_date, 'in', 'Sales return', $s->return_no, (float) $s->advance_credited, 0, $s->confirmed_at ?? $s->created_at, ['sales_return_id' => $s->id])));

        return $this->finish($rows, (float) $customer->advance_balance);
    }

    private function row($date, string $kind, string $label, ?string $ref, float $in, float $out, $at, array $extra = []): array
    {
        return [
            'date'  => $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : null,
            'kind'  => $kind,
            'label' => $label,
            'ref'   => $ref,
            'in'    => round($in, 2),
            'out'   => round($out, 2),
            '_at'   => $at ? (string) $at : '',
        ] + $extra;
    }

    private function finish(Collection $rows, float $current): array
    {
        $rows = $rows->sortBy(fn ($r) => ($r['date'] ?? '').'|'.$r['_at'])->values();
        $net = round($rows->sum('in') - $rows->sum('out'), 2);
        $gap = round($current - $net, 2);
        if (abs($gap) >= 0.01) {
            $rows->prepend([
                'date' => null, 'kind' => 'adjust', 'label' => 'Earlier balance / adjustment', 'ref' => null,
                'in' => $gap > 0 ? $gap : 0.0, 'out' => $gap < 0 ? -$gap : 0.0, '_at' => '',
            ]);
        }

        $running = 0.0;
        $rows = $rows->map(function ($r) use (&$running) {
            $running = round($running + $r['in'] - $r['out'], 2);
            unset($r['_at']);

            return $r + ['balance' => $running];
        });

        return [
            // Newest first for the page.
            'rows'      => $rows->reverse()->values()->all(),
            'balance'   => round($current, 2),
            'total_in'  => round($rows->sum('in'), 2),
            'total_out' => round($rows->sum('out'), 2),
        ];
    }
}
