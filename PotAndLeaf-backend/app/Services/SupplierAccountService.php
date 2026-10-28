<?php

namespace App\Services;

use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;

/**
 * A supplier's money in two plain parts, never mixed:
 *  - payments: money actually paid to the supplier, and which bills it paid;
 *  - advance:  money held with the supplier — where it came from (advance
 *              paid, extra paid on a bill, return credit) and which bills it
 *              was used on — with a running balance that ends at the
 *              supplier's advance balance.
 */
class SupplierAccountService
{
    public function account(Supplier $supplier): array
    {
        $all = SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->with(['allocations.purchase:id,purchase_no', 'purchase:id,purchase_no'])
            ->orderBy('payment_date')->orderBy('created_at')
            ->get();

        $payments = [];
        $moves = [];

        foreach ($all as $p) {
            $amount = round((float) $p->amount, 2);

            if ($p->applied_from_advance) {
                $moves[] = $this->move($p->payment_date, $p->created_at, 'used',
                    'Used on bill '.($p->purchase?->purchase_no ?? '—'), $p->payment_no, 0, $amount, $p->purchase_id);
                continue;
            }

            if ($p->is_advance) {
                $moves[] = $this->move($p->payment_date, $p->created_at, 'advance', 'Advance paid', $p->payment_no, $amount, 0);
                $payments[] = $this->paymentRow($p, [], 0, $amount);
                continue;
            }

            $bills = $p->allocations->map(fn ($a) => ['purchase_id' => $a->purchase_id, 'purchase_no' => $a->purchase?->purchase_no, 'amount' => round((float) $a->amount, 2)])->values()->all();
            if (! $bills && $p->purchase_id) {
                $bills = [['purchase_id' => $p->purchase_id, 'purchase_no' => $p->purchase?->purchase_no, 'amount' => $amount]];
            }
            // Bills linked automatically (money paid "on account"): the rest of the
            // payment went to old dues / the opening balance, not to advance.
            $onlyAuto = $p->allocations->isNotEmpty() && $p->allocations->every(fn ($a) => (bool) $a->auto_applied);
            $extra = ($bills && ! $onlyAuto) ? round($amount - collect($bills)->sum('amount'), 2) : 0.0;
            if ($extra > 0.005) {
                $moves[] = $this->move($p->payment_date, $p->created_at, 'extra', 'Extra paid, kept as advance', $p->payment_no, $extra, 0);
            }
            $row = $this->paymentRow($p, $bills, max(0, $extra), 0);
            $row['to_old_dues'] = $onlyAuto || ! $bills ? max(0, round($amount - collect($bills)->sum('amount'), 2)) : 0.0;
            $payments[] = $row;
        }

        foreach (PurchaseReturn::query()->where('supplier_id', $supplier->id)->where('status', 'confirmed')
            ->where('advance_credit', '>', 0)->with('purchase:id,purchase_no')->get() as $r) {
            $moves[] = $this->move($r->return_date, $r->created_at, 'return', 'Goods returned — credit beyond what you owed', $r->return_no, round((float) $r->advance_credit, 2), 0);
        }

        usort($moves, fn ($a, $b) => [$a['date'], $a['_at']] <=> [$b['date'], $b['_at']]);

        // Advance that cannot be traced to a record (e.g. from returns made before
        // this was recorded) is shown as one "earlier" line, so the total always
        // matches the supplier's advance balance.
        $traced = round(collect($moves)->sum('in') - collect($moves)->sum('out'), 2);
        $actual = round((float) $supplier->advance_balance, 2);
        if (abs($actual - $traced) > 0.005) {
            array_unshift($moves, $this->move(null, null, 'earlier', 'Earlier advance (returns or adjustments before this list)', null,
                max(0, $actual - $traced), max(0, $traced - $actual)));
        }

        $balance = 0.0;
        foreach ($moves as &$m) {
            $balance = round($balance + $m['in'] - $m['out'], 2);
            $m['balance'] = $balance;
            unset($m['_at']);
        }
        unset($m);

        return [
            'summary' => [
                'you_owe'          => round((float) $supplier->outstanding, 2),
                'advance_available'=> $actual,
                'paid_total'       => round(collect($payments)->sum('amount'), 2),
                'payment_count'    => count($payments),
            ],
            'payments' => array_reverse($payments),
            'advance'  => array_reverse($moves),
        ];
    }

    private function paymentRow(SupplierPayment $p, array $bills, float $extra, float $advance): array
    {
        return [
            'id'          => $p->id,
            'date'        => optional($p->payment_date)->toDateString(),
            'payment_no'  => $p->payment_no,
            'mode'        => $p->mode,
            'reference'   => $p->reference,
            'amount'      => round((float) $p->amount, 2),
            'bills'       => $bills,
            'to_advance'  => round($extra + $advance, 2),
            'is_advance'  => (bool) $p->is_advance,
        ];
    }

    private function move($date, $at, string $kind, string $label, ?string $ref, float $in, float $out, ?string $purchaseId = null): array
    {
        return [
            'date'        => $date ? (is_string($date) ? substr($date, 0, 10) : $date->toDateString()) : null,
            '_at'         => (string) $at,
            'kind'        => $kind,
            'label'       => $label,
            'reference'   => $ref,
            'purchase_id' => $purchaseId,
            'in'          => round($in, 2),
            'out'         => round($out, 2),
        ];
    }
}
