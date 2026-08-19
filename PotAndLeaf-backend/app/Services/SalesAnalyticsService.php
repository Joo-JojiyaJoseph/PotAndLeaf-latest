<?php

namespace App\Services;

use App\Models\CommissionTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesReturn;
use App\Models\SupervisorCommissionEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Sales comparison, YTD, trends, GST, commission reports, and leaderboards. */
class SalesAnalyticsService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function financialYearStart(int|string $companyId, ?Carbon $asOf = null): Carbon
    {
        $asOf ??= now();
        $startMonth = max(1, min(12, $this->settings->getInt($companyId, 'financial_year_start_month') ?: 4));
        $fyStart = $asOf->copy()->month >= $startMonth
            ? Carbon::create($asOf->year, $startMonth, 1)
            : Carbon::create($asOf->year - 1, $startMonth, 1);

        return $fyStart->startOfDay();
    }

    /** Detailed sales metrics for a date range. */
    public function salesMetrics(int|string $companyId, string $from, string $to, ?string $locationId = null): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $salesQ = Sale::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->whereNotIn('bill_kind', ['proforma'])
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId));

        $eligible = (clone $salesQ)->where('bill_kind', '!=', 'complimentary');

        $gross = round((float) (clone $salesQ)->sum('grand_total'), 2);
        $net = round((float) (clone $eligible)->selectRaw('SUM(subtotal - loyalty_discount) as n')->value('n'), 2);
        $tax = round((float) (clone $salesQ)->sum('tax_total'), 2);
        $loyaltyDiscount = round((float) (clone $eligible)->sum('loyalty_discount'), 2);

        $lineDiscount = round((float) SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->when($companyId !== null, fn ($sq) => $sq->forCompany($companyId))
                ->where('status', 'confirmed')->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
                ->when($locationId, fn ($qq) => $qq->where('location_id', $locationId)))
            ->sum('discount'), 2);

        $returns = round((float) SalesReturn::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->whereDate('return_date', '>=', $from)
            ->whereDate('return_date', '<=', $to)
            ->when($locationId, fn ($q) => $q->whereHas('sale', fn ($sq) => $sq->where('location_id', $locationId)))
            ->sum('subtotal'), 2);

        $qty = round((float) SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->when($companyId !== null, fn ($sq) => $sq->forCompany($companyId))
                ->where('status', 'confirmed')->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
                ->when($locationId, fn ($qq) => $qq->where('location_id', $locationId)))
            ->sum('qty'), 3);

        return [
            'from'              => $from,
            'to'                => $to,
            'location_id'       => $locationId,
            'gross_sales'       => $gross,
            'net_sales'         => max(0, round($net - $returns, 2)),
            'subtotal_net'      => $net,
            'returns'           => $returns,
            'discounts'         => round($loyaltyDiscount + $lineDiscount, 2),
            'loyalty_discount'  => $loyaltyDiscount,
            'line_discount'     => $lineDiscount,
            'tax'               => $tax,
            'invoice_count'     => (clone $salesQ)->count(),
            'customer_count'    => (clone $salesQ)->whereNotNull('customer_id')->distinct('customer_id')->count('customer_id'),
            'quantity_sold'     => $qty,
        ];
    }

    /** Current calendar month vs previous calendar month. */
    public function monthComparison(int|string $companyId, ?string $asOf = null, ?string $locationId = null): array
    {
        $asOf = Carbon::parse($asOf ?? now());
        $curFrom = $asOf->copy()->startOfMonth()->toDateString();
        $curTo = $asOf->toDateString();
        $prevFrom = $asOf->copy()->subMonth()->startOfMonth()->toDateString();
        $prevTo = $asOf->copy()->subMonth()->endOfMonth()->toDateString();

        $current = $this->salesMetrics($companyId, $curFrom, $curTo, $locationId);
        $previous = $this->salesMetrics($companyId, $prevFrom, $prevTo, $locationId);

        return $this->comparisonPayload('month', $current, $previous, [
            'current_period'  => ['from' => $curFrom, 'to' => $curTo],
            'previous_period' => ['from' => $prevFrom, 'to' => $prevTo],
        ]);
    }

    /** Same period year-over-year (defaults to current calendar month). */
    public function yearOnYear(int|string $companyId, ?string $month = null, ?string $locationId = null): array
    {
        $ref = $month ? Carbon::parse($month.'-01') : now();
        $curFrom = $ref->copy()->startOfMonth()->toDateString();
        $curTo = $ref->copy()->endOfMonth()->toDateString();
        $prevFrom = $ref->copy()->subYear()->startOfMonth()->toDateString();
        $prevTo = $ref->copy()->subYear()->endOfMonth()->toDateString();

        $current = $this->salesMetrics($companyId, $curFrom, $curTo, $locationId);
        $previous = $this->salesMetrics($companyId, $prevFrom, $prevTo, $locationId);

        return $this->comparisonPayload('yoy', $current, $previous, [
            'current_label'  => $ref->format('M Y'),
            'previous_label' => $ref->copy()->subYear()->format('M Y'),
        ]);
    }

    /** Financial year-to-date with prior-year YTD comparison. */
    public function yearToDate(int|string $companyId, ?string $asOf = null, ?string $locationId = null): array
    {
        $asOf = Carbon::parse($asOf ?? now());
        $fyStart = $this->financialYearStart($companyId, $asOf);
        $from = $fyStart->toDateString();
        $to = $asOf->toDateString();

        $daysElapsed = $fyStart->diffInDays($asOf) + 1;
        $priorFyStart = $fyStart->copy()->subYear();
        $priorTo = $priorFyStart->copy()->addDays($daysElapsed - 1);

        $current = $this->salesMetrics($companyId, $from, $to, $locationId);
        $previous = $this->salesMetrics($companyId, $priorFyStart->toDateString(), $priorTo->toDateString(), $locationId);

        $monthly = [];
        $cursor = $fyStart->copy()->startOfMonth();
        while ($cursor->lte($asOf)) {
            $mFrom = $cursor->copy()->startOfMonth()->toDateString();
            $mTo = $cursor->copy()->endOfMonth()->min($asOf)->toDateString();
            $monthly[] = array_merge(
                ['month' => $cursor->format('Y-m'), 'label' => $cursor->format('M Y')],
                $this->salesMetrics($companyId, $mFrom, $mTo, $locationId),
            );
            $cursor->addMonth();
        }

        return array_merge($this->comparisonPayload('ytd', $current, $previous, [
            'financial_year_start' => $from,
            'as_of'                => $to,
            'fy_start_month'       => (int) ($this->settings->getInt($companyId, 'financial_year_start_month') ?: 4),
        ]), ['monthly_breakdown' => $monthly]);
    }

    /** Rolling 12 calendar months ending on reference date. */
    public function twelveMonthTrend(int|string $companyId, ?string $asOf = null, ?string $locationId = null): array
    {
        $asOf = Carbon::parse($asOf ?? now())->startOfMonth();
        $months = [];

        $prevNet = null;
        for ($i = 11; $i >= 0; $i--) {
            $m = $asOf->copy()->subMonths($i);
            $mFrom = $m->copy()->startOfMonth()->toDateString();
            $mTo = $m->copy()->endOfMonth()->toDateString();
            $metrics = $this->salesMetrics($companyId, $mFrom, $mTo, $locationId);
            $growthPct = ($prevNet !== null && $prevNet > 0)
                ? round(($metrics['net_sales'] - $prevNet) / $prevNet * 100, 2)
                : null;
            $prevNet = $metrics['net_sales'];

            $months[] = array_merge($metrics, [
                'month'      => $m->format('Y-m'),
                'label'      => $m->format('M Y'),
                'growth_pct' => $growthPct,
            ]);
        }

        return ['months' => $months, 'location_id' => $locationId];
    }

    /** Output vs input GST summary with drill-down lines. */
    public function gstReconciliation(int|string $companyId, string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $sales = Sale::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->whereNotIn('bill_kind', ['proforma', 'complimentary'])
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->get(['id', 'sale_no', 'sale_date', 'subtotal', 'tax_total', 'is_interstate']);

        $outputTax = round((float) $sales->sum('tax_total'), 2);
        $outputTaxable = round((float) $sales->sum('subtotal'), 2);

        $itemTax = SaleItem::query()
            ->whereIn('sale_id', $sales->pluck('id'))
            ->selectRaw('SUM(cgst_amount) as cgst, SUM(sgst_amount) as sgst, SUM(igst_amount) as igst, SUM(taxable_value) as taxable')
            ->first();

        $returnTax = round((float) SalesReturn::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->whereDate('return_date', '>=', $from)
            ->whereDate('return_date', '<=', $to)
            ->sum('tax_total'), 2);

        $purchases = Purchase::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->whereDate('purchase_date', '>=', $from)
            ->whereDate('purchase_date', '<=', $to)
            ->get(['id', 'purchase_no', 'purchase_date', 'subtotal', 'tax_total']);

        $inputTax = round((float) $purchases->sum('tax_total'), 2);
        $inputTaxable = round((float) $purchases->sum('subtotal'), 2);

        $purchaseItemTax = PurchaseItem::query()
            ->whereIn('purchase_id', $purchases->pluck('id'))
            ->selectRaw('SUM(cgst_amount) as cgst, SUM(sgst_amount) as sgst, SUM(igst_amount) as igst')
            ->first();

        $netPayable = round($outputTax - $returnTax - $inputTax, 2);

        return [
            'from' => $from,
            'to'   => $to,
            'output' => [
                'taxable'    => $outputTaxable,
                'tax_total'  => $outputTax,
                'cgst'       => round((float) ($itemTax->cgst ?? 0), 2),
                'sgst'       => round((float) ($itemTax->sgst ?? 0), 2),
                'igst'       => round((float) ($itemTax->igst ?? 0), 2),
                'invoice_count' => $sales->count(),
            ],
            'sales_returns' => ['tax' => $returnTax],
            'input' => [
                'taxable'   => $inputTaxable,
                'tax_total' => $inputTax,
                'cgst'      => round((float) ($purchaseItemTax->cgst ?? 0), 2),
                'sgst'      => round((float) ($purchaseItemTax->sgst ?? 0), 2),
                'igst'      => round((float) ($purchaseItemTax->igst ?? 0), 2),
                'bill_count' => $purchases->count(),
            ],
            'net_gst_payable' => $netPayable,
            'sales_lines' => $sales->take(100)->map(fn ($s) => [
                'id' => $s->id, 'no' => $s->sale_no, 'date' => $s->sale_date->toDateString(),
                'taxable' => (float) $s->subtotal, 'tax' => (float) $s->tax_total,
            ])->values()->all(),
            'purchase_lines' => $purchases->take(100)->map(fn ($p) => [
                'id' => $p->id, 'no' => $p->purchase_no, 'date' => $p->purchase_date->toDateString(),
                'taxable' => (float) $p->subtotal, 'tax' => (float) $p->tax_total,
            ])->values()->all(),
        ];
    }

    /** Commission & incentive report for all staff types. */
    public function commissionReport(int|string $companyId, string $from, string $to, ?int $userId = null): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $txQ = CommissionTransaction::forCompany($companyId)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->with('user:id,name');

        $byUser = $txQ->get()->groupBy('user_id')->map(function ($rows, $uid) use ($companyId, $from, $to) {
            $user = $rows->first()->user;
            $salesForUser = (float) Sale::forCompany($companyId)
                ->where('created_by', $uid)->where('status', 'confirmed')
                ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
                ->selectRaw('SUM(subtotal - loyalty_discount) as n')->value('n');

            return [
                'user_id'           => (int) $uid,
                'user_name'         => $user?->name,
                'sales_net'         => round($salesForUser, 2),
                'salesman_tier'     => round((float) $rows->where('commission_type', 'salesman_tier')->where('status', 'accrued')->sum('amount'), 2),
                'daily_target'      => round((float) $rows->where('commission_type', 'daily_target')->where('status', 'accrued')->sum('amount'), 2),
                'promotion'         => round((float) $rows->where('commission_type', 'promotion')->where('status', 'accrued')->sum('amount'), 2),
                'manager'           => round((float) $rows->where('commission_type', 'manager')->where('status', 'accrued')->sum('amount'), 2),
                'reversals'         => round((float) $rows->where('commission_type', 'reversal')->sum('amount'), 2),
                'total_ledger'      => round((float) $rows->where('status', 'accrued')->sum('amount'), 2),
            ];
        })->values();

        $supervisor = SupervisorCommissionEntry::forCompany($companyId)
            ->whereDate('accrued_date', '>=', $from)
            ->whereDate('accrued_date', '<=', $to)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->with('user:id,name')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows, $uid) => [
                'user_id'   => (int) $uid,
                'user_name' => $rows->first()->user?->name,
                'supervisor_commission' => round((float) $rows->where('status', 'accrued')->sum('amount'), 2),
            ])->values();

        return [
            'from'       => $from,
            'to'         => $to,
            'staff'      => $byUser,
            'supervisor' => $supervisor,
            'totals'     => [
                'commission'  => round((float) $byUser->sum('total_ledger'), 2),
                'supervisor'  => round((float) $supervisor->sum('supervisor_commission'), 2),
            ],
        ];
    }

    /** Monthly or yearly staff leaderboard ranked by net sales. */
    public function leaderboard(int|string $companyId, string $period = 'month', ?string $asOf = null, ?string $locationId = null): array
    {
        $asOf = Carbon::parse($asOf ?? now());

        if ($period === 'year') {
            $from = $this->financialYearStart($companyId, $asOf)->toDateString();
            $to = $asOf->toDateString();
            $label = 'FY '.$this->financialYearStart($companyId, $asOf)->format('Y').'–'.$asOf->format('Y');
        } else {
            $from = $asOf->copy()->startOfMonth()->toDateString();
            $to = $asOf->copy()->endOfMonth()->toDateString();
            $label = $asOf->format('M Y');
        }

        $rows = Sale::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->whereNotIn('bill_kind', ['proforma', 'complimentary'])
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereNotNull('created_by')
            ->selectRaw('created_by as user_id, SUM(subtotal - loyalty_discount) as net_sales, COUNT(*) as invoices, SUM(1) as dummy')
            ->groupBy('created_by')
            ->orderByDesc('net_sales')
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name'])->keyBy('id');

        $ranked = $rows->values()->map(function ($r, $idx) use ($users, $companyId, $from, $to) {
            $net = round((float) $r->net_sales, 2);
            $commission = round((float) CommissionTransaction::forCompany($companyId)
                ->where('user_id', $r->user_id)
                ->whereDate('transaction_date', '>=', $from)
                ->whereDate('transaction_date', '<=', $to)
                ->where('status', 'accrued')
                ->sum('amount'), 2);

            return [
                'rank'        => $idx + 1,
                'user_id'     => (int) $r->user_id,
                'user_name'   => $users[$r->user_id]->name ?? 'Staff #'.$r->user_id,
                'net_sales'   => $net,
                'invoices'    => (int) $r->invoices,
                'incentives'  => $commission,
                'score'       => $net,
            ];
        });

        // Tie-break: higher net sales first (already sorted); equal scores share rank visually
        $prev = null;
        $rank = 0;
        $display = $ranked->map(function ($row) use (&$prev, &$rank) {
            if ($prev === null || abs($row['score'] - $prev) > 0.01) {
                $rank++;
            }
            $row['rank'] = $rank;
            $prev = $row['score'];

            return $row;
        });

        return [
            'period'      => $period,
            'label'       => $label,
            'from'        => $from,
            'to'          => $to,
            'location_id' => $locationId,
            'rankings'    => $display->values()->all(),
        ];
    }

    private function comparisonPayload(string $type, array $current, array $previous, array $meta = []): array
    {
        $curNet = (float) ($current['net_sales'] ?? 0);
        $prevNet = (float) ($previous['net_sales'] ?? 0);
        $diff = round($curNet - $prevNet, 2);
        $pct = $prevNet > 0 ? round($diff / $prevNet * 100, 2) : ($curNet > 0 ? 100.0 : 0.0);

        return array_merge($meta, [
            'comparison_type' => $type,
            'current'         => $current,
            'previous'        => $previous,
            'difference'      => [
                'net_sales' => $diff,
                'growth_pct'=> $pct,
                'invoices'  => ($current['invoice_count'] ?? 0) - ($previous['invoice_count'] ?? 0),
                'customers' => ($current['customer_count'] ?? 0) - ($previous['customer_count'] ?? 0),
            ],
        ]);
    }
}
