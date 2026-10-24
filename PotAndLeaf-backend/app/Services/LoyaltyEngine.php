<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\Product;
use App\Models\Sale;

class LoyaltyEngine
{
    public function __construct(private readonly SettingsService $settings) {}

    /** Compute points earned for a confirmed sale using configurable rules (fallback to settings). */
    public function pointsForSale(Sale $sale, Customer $customer): array
    {
        $sale->loadMissing('items');
        $date = $sale->sale_date->toDateString();
        $rules = LoyaltyRule::forCompany($sale->company_id)
            ->activeOn($date)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get();

        if ($rules->isEmpty()) {
            $base = max(0, (float) $sale->grand_total - (float) $sale->loyalty_discount);
            $points = $this->fallbackPoints($sale->company_id, $base);

            return ['points' => $points, 'rules' => []];
        }

        $total = 0;
        $applied = [];
        $spendApplied = false;
        $productMeta = Product::whereIn('id', $sale->items->pluck('product_id')->filter())
            ->get(['id', 'category_id'])
            ->keyBy('id');

        $billBase = max(0, (float) $sale->grand_total - (float) $sale->loyalty_discount);

        foreach ($rules as $rule) {
            if (! $this->customerMatches($customer, $rule)) {
                continue;
            }

            $type = $rule->rule_type;
            if (in_array($type, ['spend', 'customer_tier'], true)) {
                if ($spendApplied) {
                    continue;
                }
                $spendApplied = true;
            }

            $earned = match ($type) {
                'product'  => $this->productRulePoints($sale, $rule),
                'category' => $this->categoryRulePoints($sale, $rule, $productMeta),
                'customer_tier', 'spend' => $this->spendRulePoints($billBase, $rule),
                default => 0,
            };

            if ($earned <= 0) {
                continue;
            }

            if ($rule->max_points_per_transaction) {
                $earned = min($earned, (int) $rule->max_points_per_transaction);
            }

            $total += $earned;
            $applied[] = ['rule_id' => $rule->id, 'name' => $rule->name, 'points' => $earned];
        }

        if ($total <= 0) {
            $points = $this->fallbackPoints($sale->company_id, $billBase);

            return ['points' => $points, 'rules' => []];
        }

        // Product / category bonuses come on top of the normal points for the
        // bill. With no Spend-based or Customer-tier rule for this customer,
        // the normal points are the standard rate from Settings.
        if (! $spendApplied) {
            $normal = $this->fallbackPoints($sale->company_id, $billBase);
            if ($normal > 0) {
                $total += $normal;
                $applied[] = ['rule_id' => null, 'name' => 'Standard points (Settings)', 'points' => $normal];
            }
        }

        return ['points' => $total, 'rules' => $applied];
    }

    public function rulesForCompany(int|string|null $companyId)
    {
        return LoyaltyRule::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();
    }

    private function customerMatches(Customer $customer, LoyaltyRule $rule): bool
    {
        if (! $rule->customer_tier) {
            return true;
        }

        $tier = $customer->loyalty_tier ?: $customer->type?->value;

        return strtolower((string) $tier) === strtolower($rule->customer_tier);
    }

    private function spendRulePoints(float $billBase, LoyaltyRule $rule): int
    {
        if ($billBase < (float) $rule->min_purchase) {
            return 0;
        }
        $rupees = max(1, (float) $rule->earn_rupees);

        return (int) floor($billBase / $rupees) * (int) $rule->earn_points;
    }

    private function productRulePoints(Sale $sale, LoyaltyRule $rule): int
    {
        if (! $rule->product_id) {
            return 0;
        }

        $items = $sale->items->where('product_id', $rule->product_id);
        $bonus = (int) $rule->bonus_points_per_unit > 0
            ? (int) floor((float) $items->sum('qty')) * (int) $rule->bonus_points_per_unit
            : 0;

        $spend = 0;
        if ((float) ($rule->points_multiplier ?? 1) > 1) {
            $base = (float) $items->sum(fn ($item) => max(0, (float) $item->taxable_value));
            $rupees = max(1, (float) $rule->earn_rupees);
            $spend = (int) floor($base / $rupees) * max(1, (int) $rule->earn_points);
        }

        return $this->scalePoints($bonus + $spend, $rule);
    }

    private function categoryRulePoints(Sale $sale, LoyaltyRule $rule, $productMeta): int
    {
        if (! $rule->category_id) {
            return 0;
        }

        $base = 0.0;
        foreach ($sale->items as $item) {
            $product = $productMeta[$item->product_id] ?? null;
            if ($product?->category_id === $rule->category_id) {
                $base += max(0, (float) $item->taxable_value);
            }
        }

        if ($base < (float) $rule->min_purchase) {
            return 0;
        }

        if ((int) $rule->bonus_points_per_unit > 0) {
            $qty = 0.0;
            foreach ($sale->items as $item) {
                $product = $productMeta[$item->product_id] ?? null;
                if ($product?->category_id === $rule->category_id) {
                    $qty += (float) $item->qty;
                }
            }

            return $this->scalePoints((int) floor($qty) * (int) $rule->bonus_points_per_unit, $rule);
        }

        $rupees = max(1, (float) $rule->earn_rupees);

        return $this->scalePoints((int) floor($base / $rupees) * (int) $rule->earn_points, $rule);
    }

    private function scalePoints(int $points, LoyaltyRule $rule): int
    {
        $multiplier = (float) ($rule->points_multiplier ?? 1);
        if ($points <= 0 || $multiplier <= 1) {
            return $points;
        }

        return (int) floor($points * $multiplier);
    }

    private function fallbackPoints(int|string $companyId, float $billBase): int
    {
        $rupees = max(1, $this->settings->getInt($companyId, 'loyalty_earn_rupees'));
        $per = max(1, $this->settings->getInt($companyId, 'loyalty_earn_points'));

        return (int) floor($billBase / $rupees) * $per;
    }

    /** Public wrapper for legacy callers. */
    public function fallbackPointsPublic(int|string $companyId, float $billBase): int
    {
        return $this->fallbackPoints($companyId, $billBase);
    }
}
