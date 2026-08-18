<?php

namespace App\Actions\StockVerifications;

use App\Models\Product;
use App\Models\StockVerification;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HO approval. For each counted line, an adjustment is posted so the system
 * stock lands exactly on the physical count — the adjustment is recomputed
 * against live stock at approval time (in case stock moved after counting), and
 * only non-zero deltas hit the ledger.
 */
class ApproveStockVerification
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(StockVerification $verification, ?int $userId = null): StockVerification
    {
        if (! $verification->isSubmitted()) {
            throw ValidationException::withMessages(['status' => 'Only submitted counts can be approved.']);
        }

        return DB::transaction(function () use ($verification, $userId) {
            $verification->loadMissing('items');

            foreach ($verification->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::forCompany($verification->company_id)
                    ->lockForUpdate()
                    ->find($item->product_id);

                if (! $product) {
                    continue;
                }

                $delta = round((float) $item->counted_qty - (float) $product->current_stock, 3);
                if (abs($delta) < 1e-6) {
                    continue;
                }

                $this->inventory->post(
                    product: $product,
                    direction: $delta > 0 ? 'in' : 'out',
                    qty: abs($delta),
                    unitCost: (float) $item->unit_cost,
                    referenceType: 'stock-verification',
                    referenceId: $verification->id,
                    note: "Count {$verification->count_no}",
                    userId: $userId,
                );
                $product->save();
            }

            $verification->update([
                'status'      => 'approved',
                'approved_at' => now(),
                'approved_by' => $userId,
            ]);

            return $verification->refresh()->load('items');
        });
    }
}
