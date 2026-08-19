<?php

namespace App\Services;

use App\Models\CommissionRule;
use App\Models\ProductionOrder;
use App\Models\Product;
use App\Models\Sale;
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
                'status'              => 'accrued',
            ]);

            $order->commission_pending_qty = round((float) $order->commission_pending_qty - $take, 3);
            $order->save();

            $entries->push($entry);
            $remaining = round($remaining - $take, 3);
        }

        return $entries;
    }

    /** Reverse supervisor accruals when a sale is cancelled; restore pending production qty. */
    public function reverseForSale(Sale $sale): void
    {
        SupervisorCommissionEntry::forCompany($sale->company_id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('status', 'accrued')
            ->each(fn (SupervisorCommissionEntry $entry) => $this->createFullReversal($entry));
    }

    /** Pro-rata reversal when sold produced stock is returned. */
    public function reverseForSalesReturn(\App\Models\SalesReturn $return): void
    {
        $return->loadMissing(['items', 'sale']);
        $sale = $return->sale;
        if (! $sale) {
            return;
        }

        foreach ($return->items as $item) {
            if (! $item->product_id || (float) $item->qty <= 0) {
                continue;
            }

            $origQty = (float) $sale->items()->whereKey($item->sale_item_id)->value('qty');
            if ($origQty <= 0) {
                continue;
            }

            $ratio = min(1.0, (float) $item->qty / $origQty);

            SupervisorCommissionEntry::forCompany($return->company_id)
                ->where('reference_type', 'sale')
                ->where('reference_id', $sale->id)
                ->where('product_id', $item->product_id)
                ->where('status', 'accrued')
                ->each(fn (SupervisorCommissionEntry $entry) => $this->createPartialReversal($entry, $ratio, 'sales-return', $return->id));
        }
    }

    /** Reverse supervisor accruals when a dispatched transfer is cancelled. */
    public function reverseForTransfer(\App\Models\StockTransfer $transfer): void
    {
        SupervisorCommissionEntry::forCompany($transfer->company_id)
            ->where('reference_type', 'stock-transfer')
            ->where('reference_id', $transfer->id)
            ->where('status', 'accrued')
            ->each(fn (SupervisorCommissionEntry $entry) => $this->createFullReversal($entry));
    }

    private function createFullReversal(SupervisorCommissionEntry $entry): void
    {
        SupervisorCommissionEntry::create([
            'company_id'          => $entry->company_id,
            'user_id'             => $entry->user_id,
            'product_id'          => $entry->product_id,
            'production_order_id' => $entry->production_order_id,
            'trigger_event'       => 'reversal',
            'reference_type'      => $entry->reference_type,
            'reference_id'        => $entry->reference_id,
            'qty'                 => $entry->qty,
            'unit_value'          => $entry->unit_value,
            'amount'              => -1 * (float) $entry->amount,
            'accrued_date'        => now()->toDateString(),
            'status'              => 'accrued',
            'reversal_of_id'      => $entry->id,
        ]);
        $entry->update(['status' => 'reversed']);

        if ($entry->production_order_id) {
            ProductionOrder::whereKey($entry->production_order_id)
                ->increment('commission_pending_qty', (float) $entry->qty);
        }
    }

    private function createPartialReversal(SupervisorCommissionEntry $entry, float $ratio, string $refType, string $refId): void
    {
        if ($entry->status !== 'accrued' || $ratio <= 0) {
            return;
        }

        $amount = round((float) $entry->amount * min(1.0, $ratio), 2);
        $qty = round((float) $entry->qty * min(1.0, $ratio), 3);
        if ($amount <= 0) {
            return;
        }

        SupervisorCommissionEntry::create([
            'company_id'          => $entry->company_id,
            'user_id'             => $entry->user_id,
            'product_id'          => $entry->product_id,
            'production_order_id' => $entry->production_order_id,
            'trigger_event'       => 'reversal',
            'reference_type'      => $refType,
            'reference_id'        => $refId,
            'qty'                 => $qty,
            'unit_value'          => $entry->unit_value,
            'amount'              => -1 * $amount,
            'accrued_date'        => now()->toDateString(),
            'status'              => 'accrued',
            'reversal_of_id'      => $entry->id,
        ]);

        $reversed = abs((float) SupervisorCommissionEntry::query()
            ->where('reversal_of_id', $entry->id)
            ->where('trigger_event', 'reversal')
            ->sum('amount'));

        if ($reversed + 0.01 >= (float) $entry->amount) {
            $entry->update(['status' => 'reversed']);
        }

        if ($entry->production_order_id) {
            ProductionOrder::whereKey($entry->production_order_id)
                ->increment('commission_pending_qty', $qty);
        }
    }

    public function entries(int|string|null $companyId, array $filters = [])
    {
        return SupervisorCommissionEntry::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
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
