<?php

namespace App\Actions\BulkSplits;

use App\Models\BulkSplit;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

class CancelBulkSplit
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(BulkSplit $split, ?int $userId = null): BulkSplit
    {
        return DB::transaction(function () use ($split, $userId) {
            if ($split->isConfirmed()) {
                $split->loadMissing('items');

                // Put the source back, take the outputs away.
                $source = Product::forCompany($split->company_id)->lockForUpdate()->find($split->source_product_id);
                if ($source) {
                    $this->inventory->post(
                        product: $source, direction: 'in', qty: (float) $split->source_qty,
                        unitCost: (float) $split->source_unit_cost, referenceType: 'bulk-split-cancel',
                        referenceId: $split->id, note: "Reversal of {$split->split_no}", userId: $userId,
                    );
                    $source->save();
                }

                foreach ($split->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $target = Product::forCompany($split->company_id)->lockForUpdate()->find($item->product_id);
                    if (! $target) {
                        continue;
                    }
                    $this->inventory->post(
                        product: $target, direction: 'out', qty: (float) $item->qty,
                        unitCost: (float) $item->unit_cost, referenceType: 'bulk-split-cancel',
                        referenceId: $split->id, note: "Reversal of {$split->split_no}", userId: $userId,
                    );
                    $target->save();
                }
            }

            $split->update(['status' => 'cancelled']);

            return $split->refresh();
        });
    }
}
