<?php

namespace App\Services;

use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\SupervisorCommissionEntry;
use Illuminate\Support\Collection;

/**
 * Accrues supervisor commission on the first sale OR transfer of produced stock.
 * Pending qty on production orders prevents double-firing when both events occur.
 */
class SupervisorCommissionService
{
    /**
     * Accrue against pending production for a product movement.
     *
     * @return Collection<int, SupervisorCommissionEntry>
     */
    public function accrue(
        int|string $companyId,
        string $productId,
        float $qty,
        string $triggerEvent,
        string $referenceType,
        ?string $referenceId,
        ?float $unitValue = null,
    ): Collection {
        if ($qty <= 0) {
            return collect();
        }

        $remaining = $qty;
        $entries = collect();

        $orders = ProductionOrder::forCompany($companyId)
            ->where('output_product_id', $productId)
            ->where('status', 'completed')
            ->where('commission_pending_qty', '>', 0)
            ->whereNotNull('supervisor_id')
            ->orderBy('completed_at')
            ->lockForUpdate()
            ->get();

        foreach ($orders as $order) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (float) $order->commission_pending_qty);
            if ($take <= 0) {
                continue;
            }

            $rule = CommissionRule::forCompany($companyId)
                ->where('user_id', $order->supervisor_id)
                ->where('is_active', true)
                ->first();

            $product = Product::find($productId);
            $value = $unitValue ?? (float) ($order->output_unit_cost ?: $product?->cost_price ?: $product?->retail_price ?: 0);
            $amount = $this->calculateAmount($rule, $take, $value);

            $entry = SupervisorCommissionEntry::create([
                'company_id'          => $companyId,
                'user_id'             => $order->supervisor_id,
                'product_id'          => $productId,
                'production_order_id' => $order->id,
                'trigger_event'       => $triggerEvent,
                'reference_type'      => $referenceType,
                'reference_id'        => $referenceId,
                'qty'                 => $take,
                'unit_value'          => $value,
                'amount'              => $amount,
                'accrued_date'        => now()->toDateString(),
            ]);

            $order->commission_pending_qty = round((float) $order->commission_pending_qty - $take, 3);
            $order->save();

            $entries->push($entry);
            $remaining = round($remaining - $take, 3);
        }

        return $entries;
    }

    public function entries(int|string $companyId, array $filters = [])
    {
        return SupervisorCommissionEntry::forCompany($companyId)
            ->with(['user:id,name', 'product:id,sku,name'])
            ->when(filled($filters['user_id'] ?? null), fn ($q) => $q->where('user_id', $filters['user_id']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('accrued_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('accrued_date', '<=', $filters['to']))
            ->orderByDesc('accrued_date')
            ->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 30), 100));
    }

    private function calculateAmount(?CommissionRule $rule, float $qty, float $unitValue): float
    {
        if (! $rule) {
            return 0.0;
        }

        if (($rule->rate_type ?? 'percent') === 'per_unit') {
            return round($qty * (float) $rule->per_unit_amount, 2);
        }

        return round($qty * $unitValue * ((float) $rule->base_percent) / 100, 2);
    }
}
