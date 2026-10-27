<?php

namespace App\Actions\PurchaseReturns;

use App\Models\DamageEntry;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Repositories\Contracts\PurchaseReturnRepositoryInterface;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirming a return is the point stock leaves: each line posts an "out"
 * movement at the original landed unit cost, reducing current_stock. Returnable
 * quantities are re-checked against other confirmed returns to guard against a
 * draft that was created before another return posted.
 */
class ConfirmPurchaseReturn
{
    public function __construct(
        private readonly PurchaseReturnRepositoryInterface $returns,
        private readonly InventoryService $inventory,
    ) {}

    public function handle(PurchaseReturn $return, ?int $userId = null): PurchaseReturn
    {
        if (! $return->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft returns can be confirmed.',
            ]);
        }

        return DB::transaction(function () use ($return, $userId) {
            $return->loadMissing('items');
            $this->guardReturnable($return);

            foreach ($return->items as $item) {
                if ($item->damage_entry_id) {
                    $damage = DamageEntry::query()->whereKey($item->damage_entry_id)->lockForUpdate()->first();
                    $eligible = $damage ? $damage->eligibleReturnQty() : 0;
                    if (! $damage || (float) $item->qty > $eligible + 1e-6) {
                        throw ValidationException::withMessages([
                            'items' => "{$item->product_name}: only {$eligible} damaged units can still be returned.",
                        ]);
                    }
                    $damage->returned_qty = round((float) $damage->returned_qty + (float) $item->qty, 3);
                    $damage->save();

                    continue;
                }

                if (! $item->product_id) {
                    continue;
                }

                $product = Product::forCompany($return->company_id)
                    ->lockForUpdate()
                    ->find($item->product_id);

                if (! $product) {
                    continue;
                }

                if ((float) $product->current_stock + 1e-6 < (float) $item->qty) {
                    throw ValidationException::withMessages([
                        'items' => "{$item->product_name}: only {$product->current_stock} in stock — cannot return {$item->qty}.",
                    ]);
                }

                $this->inventory->post(
                    product: $product,
                    direction: 'out',
                    qty: (float) $item->qty,
                    unitCost: (float) $item->unit_cost,
                    referenceType: 'purchase-return',
                    referenceId: $return->id,
                    note: "Return {$return->return_no}",
                    userId: $userId,
                    productBatchId: $item->product_batch_id,
                );

                $product->save();
                if ($item->product_batch_id) {
                    $batch = \App\Models\ProductBatch::where('id', $item->product_batch_id)->lockForUpdate()->first();
                    if ($batch) {
                        $batch->decrement('remaining_qty', (float) $item->qty);
                        if ((float) $batch->fresh()->remaining_qty <= 0) $batch->update(['status' => 'depleted']);
                    }
                }
            }

            $this->creditSupplier($return);
            $return->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            return $return->refresh()->load(['supplier', 'purchase:id,purchase_no', 'items']);
        });
    }

    private function guardReturnable(PurchaseReturn $return): void
    {
        if (! $return->purchase_id) {
            return;
        }

        $confirmed = $this->returns->returnedQtyByPurchaseItem($return->purchase_id, $return->id);
        $origQty = PurchaseItem::whereIn('id', $return->items->pluck('purchase_item_id')->filter())
            ->pluck('qty', 'id');

        foreach ($return->items as $item) {
            $available = (float) ($origQty[$item->purchase_item_id] ?? 0)
                - (float) ($confirmed[$item->purchase_item_id] ?? 0);

            if ((float) $item->qty > $available + 1e-6) {
                throw ValidationException::withMessages([
                    'items' => "{$item->product_name}: only {$available} left to return.",
                ]);
            }

            if ($item->damage_entry_id) {
                continue;
            }

            if ($item->product_batch_id) {
                $batch = \App\Models\ProductBatch::where('id', $item->product_batch_id)->lockForUpdate()->first();
                if (! $batch || (float) $batch->remaining_qty + 1e-6 < (float) $item->qty) {
                    $left = $batch ? (float) $batch->remaining_qty : 0;
                    throw ValidationException::withMessages([
                        'items' => "{$item->product_name}: batch has only {$left} available.",
                    ]);
                }
            }
        }
    }

    /** Reduce what we owe the supplier. Any credit beyond outstanding becomes advance. */
    private function creditSupplier(PurchaseReturn $return): void
    {
        if (! $return->supplier_id) {
            return;
        }

        $supplier = Supplier::query()->whereKey($return->supplier_id)->lockForUpdate()->first();
        if (! $supplier) {
            return;
        }

        $credit = round((float) $return->grand_total, 2);
        $outstanding = (float) $supplier->outstanding;
        $applied = round(min(max($outstanding, 0), $credit), 2);
        $supplier->outstanding = round($outstanding - $applied, 2);
        $excess = round($credit - $applied, 2);
        if ($excess > 0.005) {
            $supplier->advance_balance = round((float) $supplier->advance_balance + $excess, 2);
            $return->update(['advance_credit' => $excess]);
        }
        $supplier->save();
    }
}
