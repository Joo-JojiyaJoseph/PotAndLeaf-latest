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
use App\Support\Commission\TierCalculator;
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
            ->counted()
            ->get();

        $rule = CommissionRule::forCompany($companyId)->where('user_id', $userId)->first();
        $dailyTiers = $rule
            ? CommissionDailyTargetTier::where('commission_rule_id', $rule->id)->orderBy('min_amount')->get()
            : collect();

        $topTarget = (float) ($dailyTiers->max('min_amount') ?? 0);
        $targetPct = $topTarget > 0 ? round($salesTotal / $topTarget * 100, 1) : 0;

        $supervisorRows = SupervisorCommissionEntry::forCompany($companyId)
            ->where('user_id', $userId)
            ->whereDate('accrued_date', $day)
            ->counted()
            ->get();
        $supervisorAmount = round((float) $supervisorRows->sum('amount'), 2);
        // Reversal rows carry a positive qty; net them out of produced qty.
        $productionQty = (float) $supervisorRows->where('trigger_event', '!=', 'reversal')->sum('qty')
            - (float) $supervisorRows->where('trigger_event', 'reversal')->sum('qty');

        $managerRows = $tx->where('commission_type', 'manager');
        $dailyManager = $managerRows->where('source_type', 'branch-day');
        $managerAmount = round((float) ($dailyManager->isNotEmpty()
            ? $dailyManager->sum('amount')
            : $managerRows->where('source_type', '!=', 'branch-day')->sum('amount')), 2);
        $incentiveTx = $dailyManager->isNotEmpty()
            ? $tx->reject(fn ($row) => $row->commission_type === 'manager' && $row->source_type === 'branch-period')
            : $tx;

        $mtd = $this->monthToDate($companyId, $userId, $day);

        return [
            'date'                  => $day,
            'user_id'               => $userId,
            'sales_total'           => round($salesTotal, 2),
            'sales_commission'      => round((float) $tx->where('commission_type', 'salesman_tier')->sum('amount'), 2),
            'daily_target_bonus'    => round((float) $tx->where('commission_type', 'daily_target')->sum('amount'), 2),
            'promotion_bonus'       => round((float) $tx->where('commission_type', 'promotion')->sum('amount'), 2),
            'supervisor_commission' => $supervisorAmount,
            'manager_commission'    => $managerAmount,
            'total_incentive'       => round((float) $incentiveTx->sum('amount') + $supervisorAmount, 2),
            'daily_target'          => $topTarget,
            'target_achievement_pct'=> $targetPct,
            'production_qty'        => round(max(0.0, $productionQty), 3),
            'mtd_sales'             => $mtd['sales'],
            'mtd_incentive'         => $mtd['incentive'],
        ];
    }

    /** Month-to-date sales and incentive for one employee, without double-counting a monthly manager rollup. */
    private function monthToDate(int|string $companyId, int $userId, string $day): array
    {
        $monthStart = Carbon::parse($day)->startOfMonth()->toDateString();
        $sales = (float) Sale::forCompany($companyId)
            ->where('created_by', $userId)
            ->where('status', 'confirmed')
            ->whereDate('sale_date', '>=', $monthStart)
            ->whereDate('sale_date', '<=', $day)
            ->get()
            ->sum(fn (Sale $s) => $this->netSalesBase($s));

        $tx = CommissionTransaction::forCompany($companyId)
            ->where('user_id', $userId)
            ->whereDate('transaction_date', '>=', $monthStart)
            ->whereDate('transaction_date', '<=', $day)
            ->counted()
            ->get();

        $hasDailyManager = $tx->contains(fn ($row) => $row->source_type === 'branch-day');
        if ($hasDailyManager) {
            $tx = $tx->reject(fn ($row) => $row->commission_type === 'manager' && $row->source_type === 'branch-period');
        }

        $supervisor = (float) SupervisorCommissionEntry::forCompany($companyId)
            ->where('user_id', $userId)
            ->whereDate('accrued_date', '>=', $monthStart)
            ->whereDate('accrued_date', '<=', $day)
            ->counted()
            ->sum('amount');

        return [
            'sales'     => round($sales, 2),
            'incentive' => round((float) $tx->sum('amount') + $supervisor, 2),
        ];
    }

    /** Branch net sales for manager commission — same formula as salesman/daily (subtotal − loyalty, less returns). */
    public function branchNetSales(int|string $companyId, string $from, string $to, int|string|null $locationId = null): float
    {
        $sales = (float) Sale::forCompany($companyId)
            ->where('status', 'confirmed')
            ->whereNotIn('bill_kind', ['complimentary', 'proforma'])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->get()
            ->sum(fn (Sale $s) => $this->netSalesBase($s));

        $returns = (float) SalesReturn::forCompany($companyId)
            ->where('status', 'confirmed')
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereDate('return_date', '>=', $from)
            ->whereDate('return_date', '<=', $to)
            ->sum('subtotal');

        return max(0.0, round($sales - $returns, 2));
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
                'role'             => 'manager',
                'branch_id'        => $mr->location_id,
                'rule_id'          => $mr->id,
                'commission_type'  => 'manager',
                'source_type'      => 'branch-period',
                'source_id'        => $sourceId,
                'calculation_base' => $net,
                'rate_percent'     => (float) $mr->percent,
                'amount'           => $amount,
                'transaction_date' => $to,
                'period'           => $period,
                'rule_snapshot'    => ['manager_rule_id' => $mr->id, 'period' => $period, 'location_id' => $mr->location_id],
            ]);

            if ($tx) {
                $created->push($tx);
            }
        });

        return $created;
    }

    /** One idempotent manager row per branch (or company) for a single day. */
    public function accrueManagerCommissionForDate(int|string $companyId, string $date): Collection
    {
        $day = Carbon::parse($date)->toDateString();
        $created = collect();

        ManagerCommissionRule::forCompany($companyId)->each(function (ManagerCommissionRule $mr) use ($companyId, $day, &$created) {
            if ($mr->effective_from && $mr->effective_from->toDateString() > $day) {
                return;
            }
            if ($mr->effective_to && $mr->effective_to->toDateString() < $day) {
                return;
            }

            $net = $this->branchNetSales($companyId, $day, $day, $mr->location_id);
            $amount = round($net * (float) $mr->percent / 100, 2);
            $sourceId = $mr->location_id
                ? "{$companyId}:{$mr->location_id}:{$day}"
                : "{$companyId}:{$day}";

            $existing = CommissionTransaction::query()
                ->where('company_id', $companyId)
                ->where('user_id', $mr->user_id)
                ->where('commission_type', 'manager')
                ->where('source_type', 'branch-day')
                ->where('source_id', $sourceId)
                ->where('status', 'accrued')
                ->first();

            if ($existing) {
                if ($amount <= 0) {
                    $this->reverseTransaction($existing);
                } elseif (abs((float) $existing->amount - $amount) >= 0.01) {
                    $existing->update([
                        'calculation_base' => $net,
                        'amount'           => $amount,
                        'rate_percent'     => (float) $mr->percent,
                    ]);
                }

                return;
            }

            if ($amount <= 0) {
                return;
            }

            $tx = $this->recordTransaction([
                'company_id'       => $companyId,
                'user_id'          => $mr->user_id,
                'role'             => 'manager',
                'branch_id'        => $mr->location_id,
                'rule_id'          => $mr->id,
                'commission_type'  => 'manager',
                'source_type'      => 'branch-day',
                'source_id'        => $sourceId,
                'calculation_base' => $net,
                'rate_percent'     => (float) $mr->percent,
                'amount'           => $amount,
                'transaction_date' => $day,
                'period'           => Carbon::parse($day)->format('Y-m'),
                'rule_snapshot'    => ['manager_rule_id' => $mr->id, 'date' => $day, 'location_id' => $mr->location_id],
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
        $tierRows = CommissionTransaction::forCompany($sale->company_id)
            ->where('user_id', $sale->created_by)
            ->where('commission_type', 'salesman_tier')
            ->counted()
            ->whereDate('transaction_date', '>=', $monthStart)
            ->whereDate('transaction_date', '<=', $monthEnd)
            ->get(['id', 'amount']);

        // Net of reversals: a cancelled or returned sale frees up cap room.
        $reversed = $tierRows->isEmpty() ? 0.0 : (float) CommissionTransaction::query()
            ->where('commission_type', 'reversal')
            ->whereIn('reversal_of_id', $tierRows->pluck('id'))
            ->sum('amount');
        $already = (float) $tierRows->sum('amount') + $reversed;

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
                'role'            => 'salesman',
                'branch_id'       => $sale->location_id,
                'rule_id'         => $rule->id,
                'commission_type' => 'salesman_tier',
                'source_type'     => 'sale',
                'source_id'       => $sale->id,
                'calculation_base'  => $saleBase,
                'rate_percent'    => $rate,
                'amount'          => $amount,
                'transaction_date'=> $sale->sale_date,
                'period'          => $sale->sale_date->format('Y-m'),
                'rule_snapshot'   => ['rule_id' => $rule->id, 'flat_percent' => true, 'tier_mode' => $rule->tier_mode, 'tier_period' => $rule->tier_period],
            ]);

            return;
        }

        $period = $rule->tier_period ?: 'monthly';
        $mode = $rule->tier_mode ?: 'marginal';
        $before = $period === 'daily'
            ? $this->dayNetSalesBefore($sale)
            : $this->monthNetSalesBefore($sale, $sale->sale_date->copy()->startOfMonth());
        $after = $before + $saleBase;
        $amount = $this->marginalTierAmount($before, $after, $tiers, $sale, $mode);

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
            'role'            => 'salesman',
            'branch_id'       => $sale->location_id,
            'rule_id'         => $rule->id,
            'commission_type' => 'salesman_tier',
            'source_type'     => 'sale',
            'source_id'       => $sale->id,
            'calculation_base'  => $saleBase,
            'amount'          => $amount,
            'transaction_date'=> $sale->sale_date,
            'period'          => $sale->sale_date->format('Y-m'),
            'rule_snapshot'   => [
                'rule_id' => $rule->id,
                'tiers' => $tiers->count(),
                'before' => $before,
                'after' => $after,
                'tier_mode' => $mode,
                'tier_period' => $period,
            ],
        ]);
    }

    private function marginalTierAmount(float $before, float $after, Collection $tiers, Sale $sale, string $mode = 'marginal'): float
    {
        $generic = $tiers->filter(fn ($t) => ! $t->product_id && ! $t->category_id);
        $specific = $tiers->filter(fn ($t) => $t->product_id || $t->category_id);

        $amount = 0.0;
        if ($generic->isNotEmpty()) {
            $amount += TierCalculator::amount($before, $after, $this->tierRows($generic), $mode);
        }

        if ($specific->isNotEmpty()) {
            $amount += $this->specificTierCommission($sale, $specific, $mode);
        }

        return round($amount, 2);
    }

    private function tierRows(Collection $tiers): array
    {
        return $tiers->map(fn ($tier) => [
            'min_amount' => (float) $tier->min_amount,
            'max_amount' => $tier->max_amount !== null ? (float) $tier->max_amount : null,
            'percent'    => (float) $tier->percent,
        ])->values()->all();
    }

    private function specificTierCommission(Sale $sale, Collection $tiers, string $mode = 'marginal'): float
    {
        $sale->loadMissing('items');
        $productMeta = Product::whereIn('id', $sale->items->pluck('product_id')->filter())
            ->get(['id', 'category_id'])
            ->keyBy('id');

        $total = 0.0;
        foreach ($sale->items as $item) {
            // taxable_value is already qty × rate − discount.
            $lineBase = max(0, (float) $item->taxable_value);
            $product = $productMeta[$item->product_id] ?? null;
            $matching = $tiers->filter(function ($tier) use ($item, $product) {
                if ($tier->product_id && $tier->product_id !== $item->product_id) {
                    return false;
                }
                if ($tier->category_id && $product?->category_id !== $tier->category_id) {
                    return false;
                }

                return (bool) ($tier->product_id || $tier->category_id);
            });
            if ($matching->isEmpty()) {
                continue;
            }
            $total += TierCalculator::amount(0, $lineBase, $this->tierRows($matching), $mode);
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

    private function dayNetSalesBefore(Sale $sale): float
    {
        return (float) Sale::forCompany($sale->company_id)
            ->where('created_by', $sale->created_by)
            ->where('status', 'confirmed')
            ->where('id', '!=', $sale->id)
            ->whereDate('sale_date', $sale->sale_date)
            ->get()
            ->sum(fn (Sale $s) => $this->netSalesBase($s));
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

                // taxable_value is already qty × rate − discount.
                $lineBase = max(0, (float) $item->taxable_value);
                $amount = (float) $promo->bonus_fixed
                    + $qty * (float) $promo->bonus_per_unit
                    + round($lineBase * (float) $promo->bonus_percent / 100, 2);

                if ($amount <= 0) {
                    continue;
                }

                $this->recordTransaction([
                    'company_id'      => $sale->company_id,
                    'user_id'         => $sale->created_by,
                    'role'            => 'salesman',
                    'branch_id'       => $sale->location_id,
                    'rule_id'         => $promo->id,
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
        $data['role'] ??= match ($data['commission_type'] ?? '') {
            'salesman_tier', 'daily_target', 'promotion' => 'salesman',
            'manager' => 'manager',
            default => null,
        };
        if (! empty($data['transaction_date']) && empty($data['period'])) {
            $data['period'] = Carbon::parse($data['transaction_date'])->format('Y-m');
        }

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

        // A daily-target / branch-day row that was reversed earlier still holds
        // this unique key. Retire its key so the bonus can be earned again
        // (e.g. a same-day return dropped the day below target, then a new
        // sale lifted it back). The reversed row and its reversal stay in the
        // ledger and still net to zero.
        $retired = CommissionTransaction::query()
            ->where(array_diff_key($dedupe, ['status' => true]))
            ->where('status', 'reversed')
            ->first();
        if ($retired) {
            $retired->update(['source_id' => substr($retired->source_id.'#'.$retired->id, 0, 191)]);
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

        $dailyTotal = ($rule->target_scope === 'branch')
            ? $this->branchNetSales($companyId, $day, $day, $rule->location_id)
            : $this->dailyNetSales($companyId, $userId, $day);

        foreach ($tiers as $tier) {
            $sourceId = "{$day}:{$tier->id}";
            $existing = CommissionTransaction::forCompany($companyId)
                ->where('user_id', $userId)
                ->where('commission_type', 'daily_target')
                ->where('source_type', 'daily-target')
                ->where('source_id', $sourceId)
                ->where('status', 'accrued')
                ->first();

            $percentBonus = round($dailyTotal * (float) ($tier->bonus_percent ?? 0) / 100, 2);
            $bonus = round((float) $tier->bonus_amount + $percentBonus, 2);

            if ($dailyTotal >= (float) $tier->min_amount && $bonus > 0) {
                if (! $existing) {
                    $this->recordTransaction([
                        'company_id'       => $companyId,
                        'user_id'          => $userId,
                        'role'             => 'salesman',
                        'branch_id'        => $rule->location_id,
                        'rule_id'          => $rule->id,
                        'commission_type'  => 'daily_target',
                        'source_type'      => 'daily-target',
                        'source_id'        => $sourceId,
                        'calculation_base' => $dailyTotal,
                        'rate_percent'     => (float) ($tier->bonus_percent ?? 0),
                        'fixed_bonus'      => (float) $tier->bonus_amount,
                        'amount'           => $bonus,
                        'transaction_date' => $day,
                        'period'           => Carbon::parse($day)->format('Y-m'),
                        'rule_snapshot'    => [
                            'tier_id' => $tier->id,
                            'min_amount' => (float) $tier->min_amount,
                            'target_scope' => $rule->target_scope ?: 'salesman',
                        ],
                    ]);
                } elseif ((float) ($tier->bonus_percent ?? 0) > 0 && abs((float) $existing->amount - $bonus) >= 0.01) {
                    $existing->update([
                        'calculation_base' => $dailyTotal,
                        'amount'           => $bonus,
                        'rate_percent'     => (float) $tier->bonus_percent,
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
        // Never reverse more than is still left on the original row.
        $amount = min($amount, $this->unreversedAmount($tx));
        if ($amount <= 0) {
            return;
        }

        $created = $this->recordTransaction([
            'company_id'       => $tx->company_id,
            'user_id'          => $tx->user_id,
            'role'             => $tx->role,
            'branch_id'        => $tx->branch_id,
            'rule_id'          => $tx->rule_id,
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

    /** What is still left on an original row after earlier (partial) reversals. */
    private function unreversedAmount(CommissionTransaction $tx): float
    {
        $reversed = abs((float) CommissionTransaction::query()
            ->where('reversal_of_id', $tx->id)
            ->where('commission_type', 'reversal')
            ->sum('amount'));

        return max(0.0, round((float) $tx->amount - $reversed, 2));
    }

    /**
     * Reverse whatever is still open on a row. The original keeps its amount
     * (status "reversed") and one negative row is added, so totals over
     * COUNTED_STATUSES net to zero. A sale returned in part and then
     * cancelled is only reversed for the part not already reversed.
     */
    private function reverseTransaction(CommissionTransaction $tx): void
    {
        if ($tx->status !== 'accrued') {
            return;
        }

        DB::transaction(function () use ($tx) {
            $remaining = $this->unreversedAmount($tx);
            if ($remaining > 0.005) {
                // Keep the original key (reports look reversals up by sale id);
                // fall back to a per-row key when a re-earned bonus is reversed
                // a second time and the original key is already used.
                $keyTaken = CommissionTransaction::query()
                    ->where('company_id', $tx->company_id)
                    ->where('user_id', $tx->user_id)
                    ->where('commission_type', 'reversal')
                    ->where('source_type', $tx->source_type)
                    ->where('source_id', $tx->source_id)
                    ->exists();
                CommissionTransaction::create([
                    'company_id'       => $tx->company_id,
                    'user_id'          => $tx->user_id,
                    'role'             => $tx->role,
                    'branch_id'        => $tx->branch_id,
                    'rule_id'          => $tx->rule_id,
                    'commission_type'  => 'reversal',
                    'source_type'      => $tx->source_type,
                    'source_id'        => $keyTaken ? 'rev:'.$tx->id : $tx->source_id,
                    'product_id'       => $tx->product_id,
                    'calculation_base' => $tx->calculation_base,
                    'amount'           => -1 * $remaining,
                    'transaction_date' => now()->toDateString(),
                    'status'           => 'accrued',
                    'reversal_of_id'   => $tx->id,
                    'rule_snapshot'    => ['reversed_type' => $tx->commission_type],
                ]);
            }
            $tx->update(['status' => 'reversed']);
        });
    }
}
