<?php

namespace App\Services;

use App\Models\CommissionPayout;
use App\Models\CommissionRule;
use App\Models\CommissionTransaction;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\SupervisorCommissionEntry;
use Illuminate\Support\Carbon;

class CommissionService
{
    public function __construct(
        private readonly SupervisorCommissionService $supervisorCommission,
        private readonly CommissionEngine $engine,
    ) {}

    public function rules(int|string|null $companyId)
    {
        return CommissionRule::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with(['user:id,name', 'tiers', 'dailyTargetTiers'])
            ->get();
    }

    public function upsertRule(int|string $companyId, array $data): CommissionRule
    {
        $existing = CommissionRule::query()
            ->where('company_id', $companyId)
            ->where('user_id', $data['user_id'])
            ->first();

        return CommissionRule::updateOrCreate(
            ['company_id' => $companyId, 'user_id' => $data['user_id']],
            [
                'rate_type'           => $data['rate_type'] ?? $existing?->rate_type ?? 'percent',
                'base_percent'        => $data['base_percent'] ?? $existing?->base_percent ?? 0,
                'per_unit_amount'     => $data['per_unit_amount'] ?? $existing?->per_unit_amount ?? 0,
                'production_per_unit' => $data['production_per_unit'] ?? $existing?->production_per_unit ?? 0,
                'monthly_target'      => $data['monthly_target'] ?? $existing?->monthly_target ?? 0,
                'target_bonus'        => $data['target_bonus'] ?? $existing?->target_bonus ?? 0,
                'notes'               => array_key_exists('notes', $data) ? $data['notes'] : $existing?->notes,
                'is_active'           => $data['is_active'] ?? $existing?->is_active ?? true,
                'is_supervisor'       => $data['is_supervisor'] ?? $existing?->is_supervisor ?? false,
                'location_id'         => array_key_exists('location_id', $data) ? $data['location_id'] : $existing?->location_id,
                'effective_from'      => array_key_exists('effective_from', $data) ? $data['effective_from'] : $existing?->effective_from,
                'effective_to'        => array_key_exists('effective_to', $data) ? $data['effective_to'] : $existing?->effective_to,
                'max_commission'      => array_key_exists('max_commission', $data) ? $data['max_commission'] : $existing?->max_commission,
                'eligible_bill_kinds' => array_key_exists('eligible_bill_kinds', $data) ? $data['eligible_bill_kinds'] : $existing?->eligible_bill_kinds,
                'tier_period'         => $data['tier_period'] ?? $existing?->tier_period ?? 'monthly',
                'tier_mode'           => $data['tier_mode'] ?? $existing?->tier_mode ?? 'marginal',
                'target_scope'        => $data['target_scope'] ?? $existing?->target_scope ?? 'salesman',
            ],
        )->load('user:id,name');
    }

    /** Compute commission for a staff member in a YYYY-MM period from confirmed sales they billed. */
    public function compute(int|string $companyId, int $userId, string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));

        $salesTotal = (float) Sale::forCompany($companyId)
            ->where('created_by', $userId)
            ->where('status', 'confirmed')
            ->whereYear('sale_date', $year)
            ->whereMonth('sale_date', $month)
            ->get()
            ->sum(fn (Sale $s) => $this->engine->netSalesBase($s));

        $returnsTotal = (float) SalesReturn::forCompany($companyId)
            ->where('status', 'confirmed')
            ->whereYear('return_date', $year)
            ->whereMonth('return_date', $month)
            ->whereHas('sale', fn ($q) => $q->where('created_by', $userId))
            ->sum('subtotal');

        $netSales = max(0.0, round($salesTotal - $returnsTotal, 2));

        $rule = CommissionRule::forCompany($companyId)->where('user_id', $userId)->first();
        $basePercent = (float) ($rule->base_percent ?? 0);
        $target = (float) ($rule->monthly_target ?? 0);
        $bonusRule = (float) ($rule->target_bonus ?? 0);

        $ledger = CommissionTransaction::forCompany($companyId)
            ->where('user_id', $userId)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->counted()
            ->get();

        // Per-type amounts are gross; reversal rows are summed separately
        // and netted in the total below.
        $ledgerSales = round((float) $ledger->where('commission_type', 'salesman_tier')->sum('amount'), 2);
        $ledgerDaily = round((float) $ledger->where('commission_type', 'daily_target')->sum('amount'), 2);
        $ledgerPromo = round((float) $ledger->where('commission_type', 'promotion')->sum('amount'), 2);
        $ledgerManagerMonthly = round((float) $ledger->where('commission_type', 'manager')->where('source_type', 'branch-period')->sum('amount'), 2);
        $ledgerManagerDaily = round((float) $ledger->where('commission_type', 'manager')->where('source_type', 'branch-day')->sum('amount'), 2);
        $ledgerManager = $ledgerManagerMonthly != 0.0 ? $ledgerManagerMonthly : $ledgerManagerDaily;
        $ledgerReversals = round((float) $ledger->where('commission_type', 'reversal')->sum('amount'), 2);
        $hasLedger = $ledger->whereIn('commission_type', ['salesman_tier', 'daily_target', 'promotion'])->isNotEmpty();

        $base = $hasLedger
            ? $ledgerSales
            : round($netSales * $basePercent / 100, 2);
        $targetMet = $target > 0 && $netSales >= $target;
        $bonus = $hasLedger ? $ledgerDaily : ($targetMet ? $bonusRule : 0.0);
        $promotionBonus = $hasLedger ? $ledgerPromo : 0.0;

        $supervisorEntries = SupervisorCommissionEntry::forCompany($companyId)
            ->where('user_id', $userId)
            ->whereYear('accrued_date', $year)
            ->whereMonth('accrued_date', $month)
            ->counted()
            ->get();

        $supervisorTotal = round((float) $supervisorEntries->sum('amount'), 2);
        $fromSales = round((float) $supervisorEntries->where('trigger_event', 'sale')->sum('amount'), 2);
        $fromTransfers = round((float) $supervisorEntries->where('trigger_event', 'transfer')->sum('amount'), 2);
        $fromProduction = round((float) $supervisorEntries->where('trigger_event', 'production')->sum('amount'), 2);

        $incentiveTotal = round($base + $bonus + $promotionBonus + $ledgerManager + $ledgerReversals + $supervisorTotal, 2);

        return [
            'user_id'               => $userId,
            'period'                => $period,
            'sales_total'           => round($netSales, 2),
            'base_percent'          => $basePercent,
            'base_amount'           => $base,
            'target'                => $target,
            'target_met'            => $targetMet,
            'bonus'                 => $bonus,
            // Display only: which kind of bonus the "bonus" figure above is.
            'daily_target_bonus'    => $hasLedger ? $bonus : 0.0,
            'monthly_target_bonus'  => $hasLedger ? 0.0 : $bonus,
            'promotion_bonus'       => $promotionBonus,
            'manager_commission'    => $ledgerManager,
            'ledger_reversals'      => $ledgerReversals,
            'supervisor_commission' => $supervisorTotal,
            'supervisor_from_sales' => $fromSales,
            'supervisor_from_transfers' => $fromTransfers,
            'supervisor_from_production' => $fromProduction,
            'commission'            => $incentiveTotal,
            'has_rule'              => (bool) $rule,
            'has_ledger'            => $hasLedger,
            'entries'               => $supervisorEntries->map(fn ($e) => [
                'id'            => $e->id,
                'trigger_event' => $e->trigger_event,
                'qty'           => (float) $e->qty,
                'amount'        => (float) $e->amount,
                'accrued_date'  => optional($e->accrued_date)->toDateString(),
                'reference_type'=> $e->reference_type,
            ])->values()->all(),
        ];
    }

    /** Ledger summary and per-day breakdown for an arbitrary date range. */
    public function rangeSummary(int|string $companyId, int $userId, string $from, string $to): array
    {
        $ledger = CommissionTransaction::forCompany($companyId)
            ->where('user_id', $userId)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->orderBy('transaction_date')
            ->get();

        $accrued = $ledger->whereIn('status', CommissionTransaction::COUNTED_STATUSES);
        $hasDailyManager = $accrued->contains(fn ($row) => $row->source_type === 'branch-day');
        $payable = $hasDailyManager
            ? $accrued->reject(fn ($row) => $row->commission_type === 'manager' && $row->source_type === 'branch-period')
            : $accrued;

        $supervisor = round((float) SupervisorCommissionEntry::forCompany($companyId)
            ->where('user_id', $userId)
            ->counted()
            ->whereDate('accrued_date', '>=', $from)
            ->whereDate('accrued_date', '<=', $to)
            ->sum('amount'), 2);

        $sum = fn (string $type) => round((float) $payable->where('commission_type', $type)->sum('amount'), 2);

        return [
            'user_id'               => $userId,
            'from'                  => $from,
            'to'                    => $to,
            'sales_commission'      => $sum('salesman_tier'),
            'daily_target_bonus'    => $sum('daily_target'),
            'promotion_bonus'       => $sum('promotion'),
            'manager_commission'    => $sum('manager'),
            'ledger_reversals'      => $sum('reversal'),
            'supervisor_commission' => $supervisor,
            'total'                 => round((float) $payable->sum('amount') + $supervisor, 2),
            'daily'                 => $ledger->groupBy(fn ($row) => $row->transaction_date->toDateString())
                ->map(fn ($rows, $date) => [
                    'date'   => $date,
                    'amount' => round((float) $rows->whereIn('status', CommissionTransaction::COUNTED_STATUSES)->sum('amount'), 2),
                    'rows'   => $rows->count(),
                ])->values()->all(),
        ];
    }

    public function supervisorEntries(int|string|null $companyId, array $filters)
    {
        return $this->supervisorCommission->entries($companyId, $filters);
    }

    public function payouts(int|string|null $companyId)
    {
        return CommissionPayout::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with('user:id,name')
            ->orderByDesc('period')
            ->orderByDesc('created_at')
            ->paginate(30);
    }

    public function recordPayout(int|string $companyId, array $data): CommissionPayout
    {
        $payout = CommissionPayout::firstOrNew(
            ['company_id' => $companyId, 'user_id' => $data['user_id'], 'period' => $data['period']],
        );
        $payout->fill([
            'sales_total'  => $data['sales_total'] ?? 0,
            'amount'       => $data['amount'],
            'mode'         => $data['mode'] ?? 'cash',
            'payment_date' => $data['payment_date'] ?? null,
            'reference'    => $data['reference'] ?? null,
            'notes'        => $data['notes'] ?? null,
            'status'       => $data['status'] ?? 'paid',
        ])->save();
        $payout->load('user:id,name');

        if ($payout->status === 'paid') {
            app(AccountingEngine::class)->postCommission($payout);
        } else {
            app(AccountingEngine::class)->cancelBySource('commission_payout', $payout->id);
        }

        return $payout;
    }

    public function deletePayout(CommissionPayout $payout): void
    {
        app(AccountingEngine::class)->cancelBySource('commission_payout', $payout->id);
        $payout->delete();
    }
}
