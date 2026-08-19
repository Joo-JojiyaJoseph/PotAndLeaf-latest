<?php

namespace App\Services;

use App\Models\CommissionDailyTargetTier;
use App\Models\CommissionPromotion;
use App\Models\CommissionRule;
use App\Models\CommissionTier;
use App\Models\CommissionTransaction;
use App\Models\ManagerCommissionRule;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\SupervisorCommissionEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Central commission/incentive engine — accrues ledger rows on sale confirm
 * and creates reversal rows on cancel/return. Extends existing CommissionService
 * compute() rather than replacing it.
 */
class CommissionEngine
{
    public function netSalesBase(Sale $sale): float
    {
        if (in_array($sale->bill_kind, ['complimentary', 'proforma'], true)) {
            return 0.0;
        }

        return max(0.0, round((float) $sale->subtotal - (float) $sale->loyalty_discount, 2));
    }

    /** Accrue salesman tier, daily target, and promotion bonuses on confirmed sale. */
    public function accrueOnSaleConfirm(Sale $sale): void
    {
        if ($sale->status !== 'confirmed' || ! $sale->created_by) {
            return;
        }

        $base = $this->netSalesBase($sale);
        if ($base <= 0) {
            return;
        }

        $rule = $this->resolveSalesmanRule($sale);

        if ($rule) {
            $this->accrueTierCommission($sale, $rule, $base);
            $this->accrueDailyTargetBonuses($sale, $rule);
        }

        $this->accruePromotionBonuses($sale);
    }

    public function reverseOnSaleCancel(Sale $sale): void
    {
        CommissionTransaction::forCompany($sale->company_id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('status', 'accrued')
            ->each(fn (CommissionTransaction $tx) => $this->reverseTransaction($tx));

        app(SupervisorCommissionService::class)->reverseForSale($sale);
    }

    /** Pro-rata commission reversal when a confirmed sale is partially or fully returned. */
    public function reverseOnSalesReturn(SalesReturn $return): void
    {
        $return->loadMissing(['items', 'sale']);
        $sale = $return->sale;
        if (! $sale || ! $sale->created_by || $sale->status !== 'confirmed') {
            return;
        }

        $saleBase = $this->netSalesBase($sale);
        $returnBase = max(0.0, round((float) $return->subtotal, 2));
        if ($saleBase <= 0 || $returnBase <= 0) {
            return;
        }

        $ratio = min(1.0, $returnBase / $saleBase);

        CommissionTransaction::forCompany($return->company_id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('commission_type', 'salesman_tier')
            ->where('status', 'accrued')
            ->each(fn (CommissionTransaction $tx) => $this->createPartialReversal($tx, $ratio, 'sales-return', $return->id));

        foreach ($return->items as $item) {
            if (! $item->sale_item_id) {
                continue;
            }

            $orig = $sale->items()->whereKey($item->sale_item_id)->first();
            if (! $orig || (float) $orig->qty <= 0) {
                continue;
            }

            $lineRatio = min(1.0, (float) $item->qty / (float) $orig->qty);
            CommissionTransaction::forCompany($return->company_id)
                ->where('commission_type', 'promotion')
                ->where('source_type', 'sale-line')
                ->where('source_id', 'like', "{$sale->id}:{$item->sale_item_id}:%")
                ->where('status', 'accrued')
                ->each(fn (CommissionTransaction $tx) => $this->createPartialReversal($tx, $lineRatio, 'sales-return', $return->id));
        }

        $rule = $this->resolveSalesmanRule($sale);

        if ($rule) {
            $this->syncDailyTargetBonusesForDay($sale->company_id, $sale->created_by, $sale->sale_date->toDateString(), $rule);
        }

        app(SupervisorCommissionService::class)->reverseForSalesReturn($return);
    }

    /** Daily EOD summary for one employee. */
    public function dailySummary(int|string $companyId, int $userId, string $date): array
    {
        $day = Carbon::parse($date)->toDateString();

        $salesTotal = (float) Sale::forCompany($companyId)
            ->where('created_by', $userId)
            ->where('status', 'confirmed')
            ->whereDate('sale_date', $day)
            ->get()
            ->sum(fn (Sale $s) => $this->netSalesBase($s));

        $tx = CommissionTransaction::forCompany($companyId)
            ->where('user_id', $userId)
            ->whereDate('transaction_date', $day)
            ->accrued()
            ->get();

        $rule = CommissionRule::forCompany($companyId)->where('user_id', $userId)->first();
        $dailyTiers = $rule
            ? CommissionDailyTargetTier::where('commission_rule_id', $rule->id)->orderBy('min_amount')->get()
            : collect();

        $topTarget = (float) ($dailyTiers->max('min_amount') ?? 0);
        $targetPct = $topTarget > 0 ? round($salesTotal / $topTarget * 100, 1) : 0;

        return [
            'date'                  => $day,
            'user_id'               => $userId,
            'sales_total'           => round($salesTotal, 2),
            'sales_commission'      => round((float) $tx->where('commission_type', 'salesman_tier')->sum('amount'), 2),
            'daily_target_bonus'    => round((float) $tx->where('commission_type', 'daily_target')->sum('amount'), 2),
            'promotion_bonus'       => round((float) $tx->where('commission_type', 'promotion')->sum('amount'), 2),
            'supervisor_commission' => round((float) SupervisorCommissionEntry::forCompany($companyId)
                ->where('user_id', $userId)->whereDate('accrued_date', $day)->where('status', 'accrued')->sum('amount'), 2),
            'manager_commission'    => round((float) $tx->where('commission_type', 'manager')->sum('amount'), 2),
            'total_incentive'       => round((float) $tx->sum('amount') + (float) SupervisorCommissionEntry::forCompany($companyId)
                ->where('user_id', $userId)->whereDate('accrued_date', $day)->where('status', 'accrued')->sum('amount'), 2),
            'daily_target'          => $topTarget,
            'target_achievement_pct'=> $targetPct,
        ];
    }

    /** Branch net sales for manager commission (tax-exclusive, net of loyalty discount). */
    public function branchNetSales(int|string $companyId, string $from, string $to, ?int $locationId = null): float
    {
        return round((float) Sale::forCompany($companyId)
            ->where('status', 'confirmed')
            ->whereNotIn('bill_kind', ['complimentary', 'proforma'])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->get()
            ->sum(fn (Sale $s) => $this->netSalesBase($s)), 2);
    }

    public function accrueManagerCommission(int|string $companyId, string $period): Collection
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $created = collect();

        ManagerCommissionRule::forCompany($companyId)->each(function (ManagerCommissionRule $mr) use ($companyId, $period, $from, $to, &$created) {
            if ($mr->effective_from && $mr->effective_from->gt($to)) {
                return;
            }
            if ($mr->effective_to && $mr->effective_to->lt($from)) {
                return;
            }

            $net = $this->branchNetSales($companyId, $from, $to, $mr->location_id);
            $amount = round($net * (float) $mr->percent / 100, 2);
            if ($amount <= 0) {
                return;
            }

            $sourceId = $mr->location_id
                ? "{$companyId}:{$mr->location_id}:{$period}"
                : "{$companyId}:{$period}";

            $tx = $this->recordTransaction([
                'company_id'       => $companyId,
                'user_id'          => $mr->user_id,
                'commission_type'  => 'manager',
                'source_type'      => 'branch-period',
                'source_id'        => $sourceId,
                'calculation_base' => $net,
                'rate_percent'     => (float) $mr->percent,
                'amount'           => $amount,
                'transaction_date' => $to,
                'rule_snapshot'    => ['manager_rule_id' => $mr->id, 'period' => $period, 'location_id' => $mr->location_id],
            ]);

            if ($tx) {
                $created->push($tx);
            }
        });

        return $created;
    }

    private function resolveSalesmanRule(Sale $sale): ?CommissionRule
    {
        $date = $sale->sale_date->toDateString();

        return CommissionRule::forCompany($sale->company_id)
            ->where('user_id', $sale->created_by)
            ->where('is_active', true)
            ->where('is_supervisor', false)
            ->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $sale->location_id))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->where(function ($q) use ($sale) {
                $q->whereNull('eligible_bill_kinds')
                    ->orWhereJsonContains('eligible_bill_kinds', $sale->bill_kind);
            })
            ->first();
    }

    private function applyMaxCommissionCap(CommissionRule $rule, Sale $sale, float $amount): float
    {
        if (! $rule->max_commission || $amount <= 0) {
            return $amount;
        }

        $monthStart = $sale->sale_date->copy()->startOfMonth()->toDateString();
        $monthEnd = $sale->sale_date->toDateString();
        $already = (float) CommissionTransaction::forCompany($sale->company_id)
            ->where('user_id', $sale->created_by)
            ->where('commission_type', 'salesman_tier')
            ->where('status', 'accrued')
            ->whereDate('transaction_date', '>=', $monthStart)
            ->whereDate('transaction_date', '<=', $monthEnd)
            ->sum('amount');

        return max(0.0, min($amount, (float) $rule->max_commission - $already));
    }

    /** Marginal tier commission for one sale based on month-to-date net sales. */
    private function accrueTierCommission(Sale $sale, CommissionRule $rule, float $saleBase): void
    {
        $tiers = CommissionTier::where('commission_rule_id', $rule->id)->orderBy('sort_order')->orderBy('min_amount')->get();

        if ($tiers->isEmpty()) {
            $rate = (float) $rule->base_percent;
            if ($rate <= 0) {
                return;
            }
            $amount = round($saleBase * $rate / 100, 2);
            $amount = $this->applyMaxCommissionCap($rule, $sale, $amount);
            if ($amount <= 0) {
                return;
            }
            $this->recordTransaction([
                'company_id'      => $sale->company_id,
                'user_id'         => $sale->created_by,
                'commission_type' => 'salesman_tier',
                'source_type'     => 'sale',
                'source_id'       => $sale->id,
                'calculation_base'  => $saleBase,
                'rate_percent'    => $rate,
                'amount'          => $amount,
                'transaction_date'=> $sale->sale_date,
                'rule_snapshot'   => ['rule_id' => $rule->id, 'flat_percent' => true],
            ]);

            return;
        }

        $monthStart = $sale->sale_date->copy()->startOfMonth();
        $before = $this->monthNetSalesBefore($sale, $monthStart);
        $after = $before + $saleBase;
        $amount = $this->marginalTierAmount($before, $after, $tiers, $sale);

        if ($amount <= 0) {
            return;
        }

        $amount = $this->applyMaxCommissionCap($rule, $sale, $amount);
        if ($amount <= 0) {
            return;
        }

        $this->recordTransaction([
            'company_id'      => $sale->company_id,
            'user_id'         => $sale->created_by,
            'commission_type' => 'salesman_tier',
            'source_type'     => 'sale',
            'source_id'       => $sale->id,
            'calculation_base'  => $saleBase,
            'amount'          => $amount,
            'transaction_date'=> $sale->sale_date,
            'rule_snapshot'   => ['rule_id' => $rule->id, 'tiers' => $tiers->count(), 'mtd_before' => $before, 'mtd_after' => $after],
        ]);
    }

    private function marginalTierAmount(float $before, float $after, Collection $tiers, Sale $sale): float
    {
        $generic = $tiers->filter(fn ($t) => ! $t->product_id && ! $t->category_id);
        $specific = $tiers->filter(fn ($t) => $t->product_id || $t->category_id);

        $amount = 0.0;
        if ($generic->isNotEmpty()) {
            $commissionBefore = $this->tierTotalAt($before, $generic);
            $commissionAfter = $this->tierTotalAt($after, $generic);
            $amount += $commissionAfter - $commissionBefore;
        }

        if ($specific->isNotEmpty()) {
            $amount += $this->specificTierCommission($sale, $specific);
        }

        return round($amount, 2);
    }

    private function specificTierCommission(Sale $sale, Collection $tiers): float
    {
        $sale->loadMissing('items');
        $productMeta = Product::whereIn('id', $sale->items->pluck('product_id')->filter())
            ->get(['id', 'category_id'])
            ->keyBy('id');

        $total = 0.0;
        foreach ($sale->items as $item) {
            $lineBase = max(0, (float) $item->taxable_value - (float) ($item->discount ?? 0));
            $product = $productMeta[$item->product_id] ?? null;

            foreach ($tiers as $tier) {
                if ($tier->product_id && $tier->product_id !== $item->product_id) {
                    continue;
                }
                if ($tier->category_id && $product?->category_id !== $tier->category_id) {
                    continue;
                }
                $min = (float) $tier->min_amount;
                if ($lineBase <= $min) {
                    continue;
                }
                $max = $tier->max_amount !== null ? (float) $tier->max_amount : $lineBase;
                $slice = min($lineBase, $max) - $min;
                if ($slice > 0) {
                    $total += $slice * (float) $tier->percent / 100;
                }
            }
        }

        return $total;
    }

    private function tierTotalAt(float $total, Collection $tiers): float
    {
        $sum = 0.0;
        foreach ($tiers as $tier) {
            $min = (float) $tier->min_amount;
            $max = $tier->max_amount !== null ? (float) $tier->max_amount : INF;
            if ($total <= $min) {
                break;
            }
            $slice = min($total, $max) - $min;
            if ($slice > 0) {
                $sum += $slice * (float) $tier->percent / 100;
            }
        }

        return $sum;
    }

    private function monthNetSalesBefore(Sale $sale, Carbon $monthStart): float
    {
        return (float) Sale::forCompany($sale->company_id)
            ->where('created_by', $sale->created_by)
            ->where('status', 'confirmed')
            ->where('id', '!=', $sale->id)
            ->whereDate('sale_date', '>=', $monthStart)
            ->whereDate('sale_date', '<=', $sale->sale_date)
            ->get()
            ->sum(fn (Sale $s) => $this->netSalesBase($s));
    }

    private function accrueDailyTargetBonuses(Sale $sale, CommissionRule $rule): void
    {
        $this->syncDailyTargetBonusesForDay(
            $sale->company_id,
            $sale->created_by,
            $sale->sale_date->toDateString(),
            $rule,
        );
    }

    private function accruePromotionBonuses(Sale $sale): void
    {
        $sale->loadMissing('items');
        $date = $sale->sale_date->toDateString();
        $promos = CommissionPromotion::forCompany($sale->company_id)->activeOn($date)->get();

        foreach ($promos as $promo) {
            if ($promo->eligible_user_ids && ! in_array($sale->created_by, $promo->eligible_user_ids, true)) {
                continue;
            }

            foreach ($sale->items as $item) {
                $product = Product::find($item->product_id);
                if ($promo->product_id && $promo->product_id !== $item->product_id) {
                    continue;
                }
                if ($promo->category_id && $product?->category_id !== $promo->category_id) {
                    continue;
                }

                $qty = (float) $item->qty;
                if ($qty < (float) $promo->min_qty) {
                    continue;
                }

                $lineBase = max(0, (float) $item->taxable_value - (float) ($item->discount ?? 0));
                $amount = (float) $promo->bonus_fixed
                    + $qty * (float) $promo->bonus_per_unit
                    + round($lineBase * (float) $promo->bonus_percent / 100, 2);

                if ($amount <= 0) {
                    continue;
                }

                $this->recordTransaction([
                    'company_id'      => $sale->company_id,
                    'user_id'         => $sale->created_by,
                    'commission_type' => 'promotion',
                    'source_type'     => 'sale-line',
                    'source_id'       => "{$sale->id}:{$item->id}:{$promo->id}",
                    'product_id'      => $item->product_id,
                    'calculation_base'  => $lineBase,
                    'fixed_bonus'     => (float) $promo->bonus_fixed,
                    'amount'          => round($amount, 2),
                    'transaction_date'=> $date,
                    'rule_snapshot'   => ['promotion_id' => $promo->id, 'qty' => $qty],
                ]);
            }
        }
    }

    private function recordTransaction(array $data): ?CommissionTransaction
    {
        $dedupe = [
            'company_id'      => $data['company_id'],
            'user_id'         => $data['user_id'],
            'commission_type' => $data['commission_type'],
            'source_type'     => $data['source_type'],
            'source_id'       => $data['source_id'],
            'status'          => 'accrued',
        ];

        if (CommissionTransaction::query()->where($dedupe)->exists()) {
            return null;
        }

        try {
            return CommissionTransaction::create(array_merge(['status' => 'accrued'], $data));
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isDuplicateCommissionRow($e)) {
                return null;
            }
            throw $e;
        }
    }

    private function syncDailyTargetBonusesForDay(int|string $companyId, int $userId, string $day, CommissionRule $rule): void
    {
        $tiers = CommissionDailyTargetTier::where('commission_rule_id', $rule->id)
            ->orderBy('min_amount')
            ->get();

        if ($tiers->isEmpty()) {
            return;
        }

        $dailyTotal = $this->dailyNetSales($companyId, $userId, $day);

        foreach ($tiers as $tier) {
            $sourceId = "{$day}:{$tier->id}";
            $existing = CommissionTransaction::forCompany($companyId)
                ->where('user_id', $userId)
                ->where('commission_type', 'daily_target')
                ->where('source_type', 'daily-target')
                ->where('source_id', $sourceId)
                ->where('status', 'accrued')
                ->first();

            if ($dailyTotal >= (float) $tier->min_amount) {
                if (! $existing) {
                    $this->recordTransaction([
                        'company_id'       => $companyId,
                        'user_id'          => $userId,
                        'commission_type'  => 'daily_target',
                        'source_type'      => 'daily-target',
                        'source_id'        => $sourceId,
                        'calculation_base' => $dailyTotal,
                        'fixed_bonus'      => (float) $tier->bonus_amount,
                        'amount'           => (float) $tier->bonus_amount,
                        'transaction_date' => $day,
                        'rule_snapshot'    => ['tier_id' => $tier->id, 'min_amount' => (float) $tier->min_amount],
                    ]);
                }
            } elseif ($existing) {
                $this->reverseTransaction($existing);
            }
        }
    }

    private function dailyNetSales(int|string $companyId, int $userId, string $day): float
    {
        $sales = (float) Sale::forCompany($companyId)
            ->where('created_by', $userId)
            ->where('status', 'confirmed')
            ->whereDate('sale_date', $day)
            ->get()
            ->sum(fn (Sale $s) => $this->netSalesBase($s));

        $returns = (float) SalesReturn::forCompany($companyId)
            ->where('status', 'confirmed')
            ->whereDate('return_date', $day)
            ->whereHas('sale', fn ($q) => $q->where('created_by', $userId))
            ->sum('subtotal');

        return max(0.0, round($sales - $returns, 2));
    }

    private function createPartialReversal(CommissionTransaction $tx, float $ratio, string $refType, string $refId): void
    {
        if ($tx->status !== 'accrued' || $ratio <= 0) {
            return;
        }

        $amount = round((float) $tx->amount * min(1.0, $ratio), 2);
        if ($amount <= 0) {
            return;
        }

        $created = $this->recordTransaction([
            'company_id'       => $tx->company_id,
            'user_id'          => $tx->user_id,
            'commission_type'  => 'reversal',
            'source_type'      => $refType,
            'source_id'        => "{$refId}:{$tx->id}",
            'product_id'       => $tx->product_id,
            'calculation_base' => $tx->calculation_base,
            'amount'           => -1 * $amount,
            'transaction_date' => now()->toDateString(),
            'reversal_of_id'   => $tx->id,
            'rule_snapshot'    => ['reversed_type' => $tx->commission_type, 'ratio' => $ratio],
        ]);

        if (! $created) {
            return;
        }

        $reversed = abs((float) CommissionTransaction::query()
            ->where('reversal_of_id', $tx->id)
            ->where('commission_type', 'reversal')
            ->sum('amount'));

        if ($reversed + 0.01 >= (float) $tx->amount) {
            $tx->update(['status' => 'reversed']);
        }
    }

    private function isDuplicateCommissionRow(\Illuminate\Database\QueryException $e): bool
    {
        if (str_contains($e->getMessage(), 'commission_tx_dedupe')) {
            return true;
        }

        if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
            return true;
        }

        return in_array($e->errorInfo[1] ?? null, [1062, 19], true);
    }

    private function reverseTransaction(CommissionTransaction $tx): void
    {
        if ($tx->status !== 'accrued') {
            return;
        }

        DB::transaction(function () use ($tx) {
            CommissionTransaction::create([
                'company_id'       => $tx->company_id,
                'user_id'          => $tx->user_id,
                'commission_type'  => 'reversal',
                'source_type'      => $tx->source_type,
                'source_id'        => $tx->source_id,
                'product_id'       => $tx->product_id,
                'calculation_base' => $tx->calculation_base,
                'amount'           => -1 * (float) $tx->amount,
                'transaction_date' => now()->toDateString(),
                'status'           => 'accrued',
                'reversal_of_id'   => $tx->id,
                'rule_snapshot'    => ['reversed_type' => $tx->commission_type],
            ]);
            $tx->update(['status' => 'reversed']);
        });
    }
}
