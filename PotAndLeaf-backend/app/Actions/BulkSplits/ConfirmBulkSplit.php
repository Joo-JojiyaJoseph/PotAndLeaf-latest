<?php

namespace App\Actions\BulkSplits;

use App\Models\BulkSplit;
use App\Models\BulkSplitUnit;
use App\Models\Product;
use App\Services\ActivityLogService;
use App\Services\InventoryService;
use App\Support\Barcode\BarcodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirming a split posts the stock: the source is drawn down and each output
 * is received at its redistributed unit cost (which also becomes the output's
 * new cost price). Guarded so the source can't go negative.
 * Generates a unique barcode per whole saleable unit for traceability.
 */
class ConfirmBulkSplit
{
    private int $unitSeq = 0;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly BarcodeGenerator $barcodes,
        private readonly ActivityLogService $activity,
    ) {}

    public function handle(BulkSplit $split, ?int $userId = null): BulkSplit
    {
        // Idempotent: a repeat confirm returns the confirmed split as success
        // rather than erroring, so double-clicks/retries don't fail.
        if ($split->status === 'confirmed') {
            return $split->load(['items.units', 'sourceProduct:id,sku,name']);
        }

        if (! $split->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft splits can be confirmed.']);
        }

        return DB::transaction(function () use ($split, $userId) {
            $split->loadMissing('items');
            $this->unitSeq = (int) BulkSplitUnit::whereHas('split', fn ($q) => $q->where('company_id', $split->company_id))->count();

            $source = Product::forCompany($split->company_id)->lockForUpdate()->find($split->source_product_id);
            if (! $source) {
                throw ValidationException::withMessages(['source_product_id' => 'Source product no longer exists.']);
            }
            if ((float) $source->current_stock < (float) $split->source_qty) {
                throw ValidationException::withMessages([
                    'source_qty' => "Not enough stock: {$source->current_stock} available, {$split->source_qty} required.",
                ]);
            }

            $this->inventory->post(
                product: $source, direction: 'out', qty: (float) $split->source_qty,
                unitCost: (float) $split->source_unit_cost, referenceType: 'bulk-split',
                referenceId: $split->id, note: "Split {$split->split_no}", userId: $userId,
            );
            $source->save();

            foreach ($split->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $target = Product::forCompany($split->company_id)->lockForUpdate()->find($item->product_id);
                if (! $target) {
                    continue;
                }
                $this->inventory->post(
                    product: $target, direction: 'in', qty: (float) $item->qty,
                    unitCost: (float) $item->unit_cost, referenceType: 'bulk-split',
                    referenceId: $split->id, note: "Split {$split->split_no}", userId: $userId,
                );
                $target->cost_price = $item->unit_cost;
                if ($item->retail_price) {
                    $target->retail_price = $item->retail_price;
                }
                $target->save();

                $this->createUnitBarcodes($split, $item);
            }

            $split->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            $this->activity->log(
                $split->company_id, $userId, 'confirm', 'bulk_split', 'bulk_split', $split->id,
                "Bulk split {$split->split_no} confirmed",
            );

            return $split->refresh()->load(['items.units', 'sourceProduct:id,sku,name']);
        });
    }

    private function createUnitBarcodes(BulkSplit $split, $item): void
    {
        $count = max(1, (int) floor((float) $item->qty));
        for ($n = 1; $n <= $count; $n++) {
            $this->unitSeq++;
            $barcode = $this->uniqueBarcode($split, $this->unitSeq);
            BulkSplitUnit::create([
                'bulk_split_id'      => $split->id,
                'bulk_split_item_id' => $item->id,
                'product_id'         => $item->product_id,
                'barcode'            => $barcode,
                'unit_no'            => $n,
            ]);
        }
    }

    private function uniqueBarcode(BulkSplit $split, int $seq): string
    {
        do {
            $code = $this->barcodes->forSplitUnit($split->company_id, $split->split_no, $seq);
            $seq++;
        } while (
            BulkSplitUnit::where('barcode', $code)->exists()
            || Product::forCompany($split->company_id)->where('barcode', $code)->exists()
        );

        return $code;
    }
}
