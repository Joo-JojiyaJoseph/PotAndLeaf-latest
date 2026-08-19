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
        return CommissionRule::updateOrCreate(
            ['company_id' => $companyId, 'user_id' => $data['user_id']],
            [
                'rate_type'           => $data['rate_type'] ?? 'percent',
                'base_percent'        => $data['base_percent'] ?? 0,
                'per_unit_amount'     => $data['per_unit_amount'] ?? 0,
                'monthly_target'      => $data['monthly_target'] ?? 0,
                'target_bonus'        => $data['target_bonus'] ?? 0,
                'notes'               => $data['notes'] ?? null,
                'is_active'           => $data['is_active'] ?? true,
                'is_supervisor'       => $data['is_supervisor'] ?? false,
                'location_id'         => $data['location_id'] ?? null,
                'effective_from'      => $data['effective_from'] ?? null,
                'effective_to'        => $data['effective_to'] ?? null,
                'max_commission'      => $data['max_commission'] ?? null,
                'eligible_bill_kinds' => $data['eligible_bill_kinds'] ?? null,
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
            ->accrued()
            ->get();

        $ledgerSales = round((float) $ledger->where('commission_type', 'salesman_tier')->sum('amount'), 2);
        $ledgerDaily = round((float) $ledger->where('commission_type', 'daily_target')->sum('amount'), 2);
        $ledgerPromo = round((float) $ledger->where('commission_type', 'promotion')->sum('amount'), 2);
        $ledgerManager = round((float) $ledger->where('commission_type', 'manager')->sum('amount'), 2);
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
            ->where('status', 'accrued')
            ->get();

        $supervisorTotal = round((float) $supervisorEntries->sum('amount'), 2);
        $fromSales = round((float) $supervisorEntries->where('trigger_event', 'sale')->sum('amount'), 2);
        $fromTransfers = round((float) $supervisorEntries->where('trigger_event', 'transfer')->sum('amount'), 2);

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
            'promotion_bonus'       => $promotionBonus,
            'manager_commission'    => $ledgerManager,
            'ledger_reversals'      => $ledgerReversals,
            'supervisor_commission' => $supervisorTotal,
            'supervisor_from_sales' => $fromSales,
            'supervisor_from_transfers' => $fromTransfers,
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
        return CommissionPayout::updateOrCreate(
            ['company_id' => $companyId, 'user_id' => $data['user_id'], 'period' => $data['period']],
            [
                'sales_total'  => $data['sales_total'] ?? 0,
                'amount'       => $data['amount'],
                'mode'         => $data['mode'] ?? 'cash',
                'payment_date' => $data['payment_date'] ?? null,
                'reference'    => $data['reference'] ?? null,
                'notes'        => $data['notes'] ?? null,
                'status'       => $data['status'] ?? 'paid',
            ],
        )->load('user:id,name');
    }

    public function deletePayout(CommissionPayout $payout): void
    {
        $payout->delete();
    }
}
