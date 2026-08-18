<?php

namespace App\Actions\Purchases;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\ActivityLogService;
use App\Services\InventoryService;
use App\Services\SellAsFulfillmentService;
use App\Support\Barcode\BarcodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirming a purchase is the point stock becomes real: each line posts an
 * "in" movement to the ledger, the product's current_stock rises, and its
 * cost_price is refreshed to the landed unit cost. All within one transaction.
 * Bulk lines with a `sell_as` strategy are delegated to
 * SellAsFulfillmentService instead, which decides where the stock lands
 * (set product, split units, or a shared pool of both).
 */
class ConfirmPurchase
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SellAsFulfillmentService $sellAs,
        private readonly ActivityLogService $activity,
        private readonly BarcodeGenerator $barcodes,
    ) {}

    public function handle(Purchase $purchase, ?int $userId = null): Purchase
    {
        // Idempotent: a repeat confirm (double-click or retry after the first
        // request already posted stock) returns the confirmed purchase as
        // success instead of erroring.
        if ($purchase->status === 'confirmed') {
            return $purchase->load(['supplier', 'items', 'createdBy:id,name']);
        }

        if (! $purchase->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft purchases can be confirmed.',
            ]);
        }

        return DB::transaction(function () use ($purchase, $userId) {
            $purchase->loadMissing('items');

            $lineNo = 0;

            foreach ($purchase->items as $item) {
                if (! $item->product_id) {
                    continue; // free-text line, nothing to stock
                }

                $product = Product::forCompany($purchase->company_id)
                    ->lockForUpdate()
                    ->find($item->product_id);

                if (! $product) {
                    continue;
                }

                if ($item->is_bulk && $item->sell_as) {
                    // Delegates entirely — the set/unit product(s) it stocks may
                    // differ from the purchased line's own product. The split
                    // products each get their own batch barcode inside fulfil().
                    $lineNo++;
                    $this->sellAs->fulfil($purchase, $item, $product, $userId, $lineNo);
                    $item->save();
                    continue;
                }

                // Each stocked line becomes a batch with its own barcode — this
                // is the point stock is labelled batch-wise.
                $lineNo++;
                $batch = $this->makeBatch($purchase, $item, $product, $lineNo);

                $this->inventory->post(
                    product: $product,
                    direction: 'in',
                    qty: (float) $item->qty,
                    unitCost: (float) $item->landed_unit_cost,
                    referenceType: 'purchase',
                    referenceId: $purchase->id,
                    note: "Purchase {$purchase->purchase_no}",
                    userId: $userId,
                    productBatchId: $batch->id,
                );

                $product->cost_price = $item->landed_unit_cost;
                $product->save();
            }

            // A confirmed purchase is money owed to the supplier.
            $supplier = Supplier::where('company_id', $purchase->company_id)
                ->lockForUpdate()->find($purchase->supplier_id);
            if ($supplier) {
                $supplier->outstanding = (float) $supplier->outstanding + (float) $purchase->grand_total;
                $supplier->save();
            }

            $purchase->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            $this->activity->log(
                $purchase->company_id, $userId, 'confirm', 'purchases', 'purchase', $purchase->id,
                "Purchase {$purchase->purchase_no} confirmed",
                ['grand_total' => (float) $purchase->grand_total, 'created_by' => $purchase->created_by],
            );

            return $purchase->refresh()->load(['supplier', 'items', 'createdBy:id,name']);
        });
    }

    /** Mint a barcoded batch for one stocked purchase line. */
    private function makeBatch(Purchase $purchase, $item, Product $product, int $lineNo): ProductBatch
    {
        // If a batch already exists for this line, reuse it instead of creating a
        // duplicate (skip-if-exists) so the barcode is stable across re-runs.
        $existing = ProductBatch::where('purchase_item_id', $item->id)->first();
        if ($existing) {
            return $existing;
        }

        $qty = (float) $item->qty;

        return ProductBatch::create([
            'company_id'       => $purchase->company_id,
            'product_id'       => $product->id,
            'purchase_id'      => $purchase->id,
            'purchase_item_id' => $item->id,
            'supplier_id'      => $purchase->supplier_id,
            'location_id'      => $purchase->location_id,
            'batch_no'         => sprintf('%s-%02d', $purchase->purchase_no, $lineNo),
            'barcode'          => $this->barcodes->forBatch($purchase->company_id, $purchase->purchase_no, $lineNo),
            'qty'              => $qty,
            'remaining_qty'    => $qty,
            'cost_price'       => (float) $item->landed_unit_cost,
            'status'           => 'active',
            'received_at'      => now(),
        ]);
    }
}
