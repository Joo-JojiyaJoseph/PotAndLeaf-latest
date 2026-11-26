<?php

namespace App\Services;

use App\Models\CustomerReceipt;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Detailed reports for the money documents: one row per document (invoice-wise),
 * or the same documents added up by day, week, month or year.
 */
class DetailedReportService
{
    public const VIEWS = ['invoice', 'day', 'week', 'month', 'year'];

    public const TYPES = ['sales', 'purchases', 'sales_returns', 'purchase_returns', 'receipts', 'payments'];

    /** Most documents read for one report, so a very wide range cannot overload the server. */
    private const ROW_CAP = 5000;

    /** @return array<string, mixed> */
    public function build(string $type, int|string|null $companyId, string $from, string $to, string $view, ?string $search = null, ?string $mode = null): array
    {
        $view = in_array($view, self::VIEWS, true) ? $view : 'day';
        [$rows, $truncated] = $this->rows($type, $companyId, $from, $to, $search, $mode);
        $metrics = $this->metricKeys($type);

        $totals = $this->sum($rows, $metrics);
        $totals['count'] = count($rows);

        $out = [
            'type'      => $type,
            'view'      => $view,
            'from'      => $from,
            'to'        => $to,
            'metrics'   => $metrics,
            'totals'    => $totals,
            'truncated' => $truncated,
        ];

        if ($view === 'invoice') {
            $out['rows'] = $rows;

            return $out;
        }

        $groups = [];
        foreach ($rows as $row) {
            [$key, $label] = $this->periodOf($row['date'], $view);
            if (! isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'label' => $label, 'rows' => []];
            }
            $groups[$key]['rows'][] = $row;
        }
        ksort($groups);

        $out['rows'] = array_values(array_map(function (array $g) use ($metrics) {
            $parties = [];
            foreach ($g['rows'] as $r) {
                $parties[$r['party'] ?? ''] = true;
            }

            return [
                'key'     => $g['key'],
                'label'   => $g['label'],
                'count'   => count($g['rows']),
                'parties' => count($parties),
            ] + $this->sum($g['rows'], $metrics);
        }, $groups));

        return $out;
    }

    /** @return array<int, string> */
    public function metricKeys(string $type): array
    {
        return match ($type) {
            'sales'            => ['taxable', 'tax', 'round_off', 'points', 'total', 'paid', 'due'],
            'purchases'        => ['subtotal', 'discount', 'tax', 'total', 'paid', 'due'],
            'sales_returns', 'purchase_returns' => ['taxable', 'tax', 'total'],
            default            => ['amount'],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $metrics
     * @return array<string, float>
     */
    private function sum(array $rows, array $metrics): array
    {
        $out = [];
        foreach ($metrics as $m) {
            $out[$m] = round((float) array_sum(array_map(fn ($r) => (float) ($r[$m] ?? 0), $rows)), 2);
        }

        return $out;
    }

    /** @return array{0: string, 1: string} sortable key, readable label */
    private function periodOf(string $date, string $view): array
    {
        $d = Carbon::parse($date);

        return match ($view) {
            'week'  => [
                $d->copy()->startOfWeek()->toDateString(),
                $d->copy()->startOfWeek()->format('d M').' – '.$d->copy()->endOfWeek()->format('d M Y'),
            ],
            'month' => [$d->format('Y-m'), $d->format('F Y')],
            'year'  => [$d->format('Y'), $d->format('Y')],
            default => [$d->toDateString(), $d->format('d M Y (D)')],
        };
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function rows(string $type, int|string|null $companyId, string $from, string $to, ?string $search, ?string $mode): array
    {
        $scope = fn (Builder $q) => $q->when($companyId !== null, fn ($b) => $b->where('company_id', $companyId));

        switch ($type) {
            case 'sales':
                $q = $scope(Sale::query())->with(['customer:id,name', 'createdBy:id,name'])
                    ->where('status', 'confirmed')->whereIn('bill_kind', ['tax_invoice', 'complimentary'])
                    ->whereBetween('sale_date', [$from, $to])
                    ->when(filled($mode), fn ($b) => $b->where('payment_mode', $mode))
                    ->orderBy('sale_date')->orderBy('sale_no');
                $map = function (Sale $s): array {
                    $net = round((float) $s->grand_total - (float) $s->loyalty_discount, 2);

                    return [
                        'id' => $s->id, 'company_id' => $s->company_id, 'no' => $s->sale_no, 'date' => $s->sale_date->toDateString(),
                        'party' => $s->customer?->name ?? ($s->customer_name ?: 'Walk-in'), 'ref' => null,
                        'mode' => $s->payment_mode, 'kind' => $s->bill_kind === 'complimentary' ? 'Free' : 'Tax invoice',
                        'staff' => $s->createdBy?->name, 'note' => null,
                        'taxable' => (float) $s->subtotal, 'tax' => (float) $s->tax_total, 'round_off' => (float) $s->round_off,
                        'points' => (float) $s->loyalty_discount, 'total' => $net, 'paid' => (float) $s->amount_paid,
                        'due' => max(0.0, round($net - (float) $s->amount_paid, 2)),
                    ];
                };
                break;

            case 'purchases':
                $q = $scope(Purchase::query())->with('supplier:id,name')
                    ->where('status', 'confirmed')
                    ->whereBetween('purchase_date', [$from, $to])
                    ->orderBy('purchase_date')->orderBy('purchase_no');
                $map = function (Purchase $p): array {
                    return [
                        'id' => $p->id, 'company_id' => $p->company_id, 'no' => $p->purchase_no, 'date' => $p->purchase_date->toDateString(),
                        'party' => $p->supplier?->name, 'ref' => $p->invoice_no, 'mode' => null, 'kind' => null, 'staff' => null, 'note' => null,
                        'subtotal' => (float) $p->subtotal, 'discount' => (float) $p->discount_total, 'tax' => (float) $p->tax_total,
                        'total' => (float) $p->grand_total, 'paid' => (float) $p->amount_paid,
                        'due' => max(0.0, round($p->supplierPayable() - (float) $p->amount_paid, 2)),
                    ];
                };
                break;

            case 'sales_returns':
                $q = $scope(SalesReturn::query())->with(['customer:id,name', 'sale:id,sale_no'])
                    ->where('status', 'confirmed')
                    ->whereBetween('return_date', [$from, $to])
                    ->orderBy('return_date')->orderBy('return_no');
                $map = function (SalesReturn $r): array {
                    return [
                        'id' => $r->id, 'company_id' => $r->company_id, 'no' => $r->return_no, 'date' => $r->return_date->toDateString(),
                        'party' => $r->customer?->name ?? 'Walk-in', 'ref' => $r->sale?->sale_no, 'mode' => null, 'kind' => null, 'staff' => null,
                        'note' => $r->reason,
                        'taxable' => (float) $r->subtotal, 'tax' => (float) $r->tax_total, 'total' => (float) $r->grand_total,
                    ];
                };
                break;

            case 'purchase_returns':
                $q = $scope(PurchaseReturn::query())->with(['supplier:id,name', 'purchase:id,purchase_no'])
                    ->where('status', 'confirmed')
                    ->whereBetween('return_date', [$from, $to])
                    ->orderBy('return_date')->orderBy('return_no');
                $map = function (PurchaseReturn $r): array {
                    return [
                        'id' => $r->id, 'company_id' => $r->company_id, 'no' => $r->return_no, 'date' => $r->return_date->toDateString(),
                        'party' => $r->supplier?->name, 'ref' => $r->purchase?->purchase_no, 'mode' => null, 'kind' => null, 'staff' => null,
                        'note' => $r->reason,
                        'taxable' => (float) $r->subtotal, 'tax' => (float) $r->tax_total, 'total' => (float) $r->grand_total,
                    ];
                };
                break;

            case 'receipts':
                // Advance already received and later used on a bill is not new money, so it is left out.
                $q = $scope(CustomerReceipt::query())->with('customer:id,name')
                    ->where('applied_from_advance', false)
                    ->whereBetween('receipt_date', [$from, $to])
                    ->when(filled($mode), fn ($b) => $b->where('mode', $mode))
                    ->orderBy('receipt_date')->orderBy('receipt_no');
                $map = function (CustomerReceipt $r): array {
                    return [
                        'id' => $r->id, 'company_id' => $r->company_id, 'no' => $r->receipt_no, 'date' => $r->receipt_date->toDateString(),
                        'party' => $r->customer?->name, 'ref' => $r->reference, 'mode' => $r->mode,
                        'kind' => $r->is_advance ? 'Advance' : ($r->sale_id ? 'Bill' : 'On account'), 'staff' => null, 'note' => $r->notes,
                        'amount' => (float) $r->amount,
                    ];
                };
                break;

            case 'payments':
                $q = $scope(SupplierPayment::query())->with('supplier:id,name')
                    ->where('applied_from_advance', false)
                    ->whereBetween('payment_date', [$from, $to])
                    ->when(filled($mode), fn ($b) => $b->where('mode', $mode))
                    ->orderBy('payment_date')->orderBy('payment_no');
                $map = function (SupplierPayment $p): array {
                    $for = $p->payment_for ?? ($p->is_advance ? 'advance' : ($p->purchase_id ? 'bill' : 'outstanding'));

                    return [
                        'id' => $p->id, 'company_id' => $p->company_id, 'no' => $p->payment_no, 'date' => $p->payment_date->toDateString(),
                        'party' => $p->supplier?->name, 'ref' => $p->reference, 'mode' => $p->mode,
                        'kind' => ['bill' => 'Bill', 'outstanding' => 'Outstanding', 'advance' => 'Advance'][$for] ?? $for,
                        'staff' => null, 'note' => $p->notes,
                        'amount' => (float) $p->amount,
                    ];
                };
                break;

            default:
                return [[], false];
        }

        $models = $q->limit(self::ROW_CAP + 1)->get();
        $truncated = $models->count() > self::ROW_CAP;
        $rows = $models->take(self::ROW_CAP)->map($map)->all();

        if (filled($search)) {
            $needle = mb_strtolower(trim((string) $search));
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower(
                ($r['no'] ?? '').' '.($r['party'] ?? '').' '.($r['ref'] ?? '')
            ), $needle)));
        }

        return [$rows, $truncated];
    }
}
