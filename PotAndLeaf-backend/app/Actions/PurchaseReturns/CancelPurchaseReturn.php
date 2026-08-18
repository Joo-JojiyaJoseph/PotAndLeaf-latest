<?php

namespace App\Actions\PurchaseReturns;

use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a confirmed return brings the goods back in (opposite "in"
 * movements). Draft returns just flip to cancelled.
 */
class CancelPurchaseReturn
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(PurchaseReturn $return, ?int $userId = null): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $userId) {
            if ($return->isConfirmed()) {
                $return->loadMissing('items');

                foreach ($return->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }

                    $product = Product::forCompany($return->company_id)
                        ->lockForUpdate()
                        ->find($item->product_id);

                    if (! $product) {
                        continue;
                    }

                    $this->inventory->post(
                        product: $product,
                        direction: 'in',
                        qty: (float) $item->qty,
                        unitCost: (float) $item->unit_cost,
                        referenceType: 'purchase-return-cancel',
                        referenceId: $return->id,
                        note: "Reversal of {$return->return_no}",
                        userId: $userId,
                        productBatchId: $item->product_batch_id,
                    );

                    $product->save();
                    if ($item->product_batch_id) {
                        $batch = \App\Models\ProductBatch::where('id', $item->product_batch_id)->lockForUpdate()->first();
                        if ($batch) {
                            $batch->increment('remaining_qty', (float) $item->qty);
                            if ($batch->status === 'depleted') $batch->update(['status' => 'active']);
                        }
                    }
                }
            }

            $return->update(['status' => 'cancelled']);

            return $return->refresh();
        });
    }
}
