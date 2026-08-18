<?php

namespace App\Actions\Purchases;

use App\Models\Product;
use App\Models\Purchase;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a confirmed purchase reverses its stock with opposite "out"
 * movements (the original "in" rows stay for audit). Draft purchases just flip
 * to cancelled.
 */
class CancelPurchase
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(Purchase $purchase, ?int $userId = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $userId) {
            if ($purchase->isConfirmed()) {
                $purchase->loadMissing('items');

                foreach ($purchase->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }

                    $product = Product::forCompany($purchase->company_id)
                        ->lockForUpdate()
                        ->find($item->product_id);

                    if (! $product) {
                        continue;
                    }

                    $this->inventory->post(
                        product: $product,
                        direction: 'out',
                        qty: (float) $item->qty,
                        unitCost: (float) $item->landed_unit_cost,
                        referenceType: 'purchase-cancel',
                        referenceId: $purchase->id,
                        note: "Reversal of {$purchase->purchase_no}",
                        userId: $userId,
                    );

                    $product->save();
                }

                // Reverse the payable created when the purchase was confirmed.
                $supplier = \App\Models\Supplier::where('company_id', $purchase->company_id)
                    ->lockForUpdate()->find($purchase->supplier_id);
                if ($supplier) {
                    $supplier->outstanding = (float) $supplier->outstanding - (float) $purchase->grand_total;
                    $supplier->save();
                }
            }

            $purchase->update(['status' => 'cancelled']);

            return $purchase->refresh();
        });
    }
}
