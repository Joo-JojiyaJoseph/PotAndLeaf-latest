<?php

namespace App\Services;

use App\Models\CommissionPayout;
use App\Models\CommissionRule;
use App\Models\Sale;
use App\Models\SupervisorCommissionEntry;
use Illuminate\Support\Carbon;

class CommissionService
{
    public function __construct(private readonly SupervisorCommissionService $supervisorCommission) {}

    public function rules(int|string $companyId)
    {
        return CommissionRule::forCompany($companyId)->with('user:id,name')->get();
    }

    public function upsertRule(int|string $companyId, array $data): CommissionRule
    {
        return CommissionRule::updateOrCreate(
            ['company_id' => $companyId, 'user_id' => $data['user_id']],
            [
                'rate_type'       => $data['rate_type'] ?? 'percent',
                'base_percent'    => $data['base_percent'] ?? 0,
                'per_unit_amount' => $data['per_unit_amount'] ?? 0,
                'monthly_target'  => $data['monthly_target'] ?? 0,
                'target_bonus'    => $data['target_bonus'] ?? 0,
                'notes'           => $data['notes'] ?? null,
                'is_active'       => $data['is_active'] ?? true,
                'is_supervisor'   => $data['is_supervisor'] ?? false,
            ],
        )->load('user:id,name');
    }

    /** Compute commission for a staff member in a YYYY-MM period from confirmed sales they billed. */
    public function compute(int|string $companyId, int $userId, string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        $salesTotal = (float) Sale::forCompany($companyId)
            ->where('created_by', $userId)
            ->where('status', 'confirmed')
            ->whereYear('sale_date', $year)
            ->whereMonth('sale_date', $month)
            ->sum('grand_total');

        $rule = CommissionRule::forCompany($companyId)->where('user_id', $userId)->first();
        $basePercent = (float) ($rule->base_percent ?? 0);
        $target = (float) ($rule->monthly_target ?? 0);
        $bonusRule = (float) ($rule->target_bonus ?? 0);

        $base = round($salesTotal * $basePercent / 100, 2);
        $targetMet = $target > 0 && $salesTotal >= $target;
        $bonus = $targetMet ? $bonusRule : 0.0;

        $supervisorEntries = SupervisorCommissionEntry::forCompany($companyId)
            ->where('user_id', $userId)
            ->whereYear('accrued_date', $year)
            ->whereMonth('accrued_date', $month)
            ->get();

        $supervisorTotal = round((float) $supervisorEntries->sum('amount'), 2);
        $fromSales = round((float) $supervisorEntries->where('trigger_event', 'sale')->sum('amount'), 2);
        $fromTransfers = round((float) $supervisorEntries->where('trigger_event', 'transfer')->sum('amount'), 2);

        return [
            'user_id'               => $userId,
            'period'                => $period,
            'sales_total'           => round($salesTotal, 2),
            'base_percent'          => $basePercent,
            'base_amount'           => $base,
            'target'                => $target,
            'target_met'            => $targetMet,
            'bonus'                 => $bonus,
            'supervisor_commission' => $supervisorTotal,
            'supervisor_from_sales' => $fromSales,
            'supervisor_from_transfers' => $fromTransfers,
            'commission'            => round($base + $bonus + $supervisorTotal, 2),
            'has_rule'              => (bool) $rule,
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

    public function supervisorEntries(int|string $companyId, array $filters)
    {
        return $this->supervisorCommission->entries($companyId, $filters);
    }

    public function payouts(int|string $companyId)
    {
        return CommissionPayout::forCompany($companyId)
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
