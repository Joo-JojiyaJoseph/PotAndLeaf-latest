<?php

namespace App\Actions\Sales;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\InventoryService;
use App\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;

class CancelSale
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly LoyaltyService $loyalty,
    ) {}

    public function handle(Sale $sale, ?int $userId = null): Sale
    {
        return DB::transaction(function () use ($sale, $userId) {
            if ($sale->isConfirmed()) {
                $sale->loadMissing('items');

                foreach ($sale->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $product = Product::forCompany($sale->company_id)->lockForUpdate()->find($item->product_id);
                    if (! $product) {
                        continue;
                    }
                    $this->inventory->post(
                        product: $product, direction: 'in', qty: (float) $item->qty,
                        unitCost: (float) $product->cost_price, referenceType: 'sale-cancel',
                        referenceId: $sale->id, note: "Reversal of {$sale->sale_no}", userId: $userId,
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

                if ($sale->customer_id) {
                    $customer = Customer::forCompany($sale->company_id)->lockForUpdate()->find($sale->customer_id);
                    if ($customer) {
                        $due = max(0, (float) $sale->grand_total - (float) $sale->loyalty_discount);
                        if ($sale->payment_mode === 'credit') {
                            $customer->outstanding = (float) $customer->outstanding - ($due - (float) $sale->amount_paid);
                            $customer->save();
                        }
                        $this->loyalty->reverseForSale($customer, $sale);
                    }
                }
            }

            $sale->update(['status' => 'cancelled']);

            return $sale->refresh();
        });
    }
}
