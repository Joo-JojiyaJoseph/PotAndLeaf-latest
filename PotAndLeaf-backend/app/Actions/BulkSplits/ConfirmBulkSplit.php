<?php

namespace App\Actions\BulkSplits;

use App\Models\BulkSplit;
use App\Models\BulkSplitUnit;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\ActivityLogService;
use App\Services\InventoryService;
use App\Support\Barcode\BarcodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirms a bulk split: creates split products, posts stock, generates barcodes/batches.
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
        if ($split->status === 'confirmed') {
            return $split->load(['items.units', 'items.product:id,sku,name,barcode', 'sourceProduct:id,sku,name']);
        }

        if (! $split->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft splits can be confirmed.']);
        }

        $split->loadMissing('items');
        $totalSplitQty = (float) $split->items->sum('qty');

        if ($totalSplitQty <= 0) {
            throw ValidationException::withMessages(['items' => 'Add at least one split with quantity greater than zero.']);
        }

        if ($totalSplitQty > (float) $split->source_qty) {
            throw ValidationException::withMessages([
                'items' => 'Total split quantity cannot exceed the available bulk quantity.',
            ]);
        }

        return DB::transaction(function () use ($split, $userId, $totalSplitQty) {
            $this->unitSeq = (int) BulkSplitUnit::whereHas('split', fn ($q) => $q->where('company_id', $split->company_id))->count();

            $source = Product::forCompany($split->company_id)->lockForUpdate()->find($split->source_product_id);
            if (! $source) {
                throw ValidationException::withMessages(['source_product_id' => 'Source product no longer exists.']);
            }

            if ((float) $source->current_stock < $totalSplitQty) {
                throw ValidationException::withMessages([
                    'source_qty' => "Not enough stock: {$source->current_stock} available, {$totalSplitQty} required for this split.",
                ]);
            }

            $this->inventory->post(
                product: $source,
                direction: 'out',
                qty: $totalSplitQty,
                unitCost: (float) $split->source_unit_cost,
                referenceType: 'bulk-split',
                referenceId: $split->id,
                note: "Split {$split->split_no}",
                userId: $userId,
            );
            $source->save();

            foreach ($split->items as $index => $item) {
                $target = $this->resolveOrCreateSplitProduct($split, $source, $item, $index + 1);

                $batch = ProductBatch::create([
                    'company_id'    => $split->company_id,
                    'product_id'    => $target->id,
                    'bulk_split_id' => $split->id,
                    'batch_no'      => $split->split_no.'-'.str_pad((string) ($item->split_sequence ?? ($index + 1)), 3, '0', STR_PAD_LEFT),
                    'barcode'       => $target->barcode,
                    'qty'           => $item->qty,
                    'remaining_qty' => $item->qty,
                    'cost_price'    => $item->unit_cost,
                    'status'        => 'active',
                    'received_at'   => now(),
                ]);

                $this->inventory->post(
                    product: $target,
                    direction: 'in',
                    qty: (float) $item->qty,
                    unitCost: (float) $item->unit_cost,
                    referenceType: 'bulk-split',
                    referenceId: $split->id,
                    note: "Split {$split->split_no}",
                    userId: $userId,
                    productBatchId: $batch->id,
                );
                $target->cost_price = $item->unit_cost;
                if ($item->retail_price) {
                    $target->retail_price = $item->retail_price;
                    $target->mrp = max((float) $target->mrp, (float) $item->retail_price);
                }
                $target->save();

                $this->createUnitBarcodes($split, $item, $target);
            }

            $split->update([
                'status'          => 'confirmed',
                'confirmed_at'    => now(),
                'split_total_qty' => $totalSplitQty,
            ]);

            $this->activity->log(
                $split->company_id,
                $userId,
                'confirm',
                'bulk_split',
                'bulk_split',
                $split->id,
                "Bulk split {$split->split_no} confirmed — {$split->items->count()} products created",
            );

            return $split->refresh()->load(['items.units', 'items.product:id,sku,name,barcode', 'sourceProduct:id,sku,name']);
        });
    }

    private function resolveOrCreateSplitProduct(BulkSplit $split, Product $source, $item, int $sequence): Product
    {
        if ($item->product_id) {
            $existing = Product::forCompany($split->company_id)->lockForUpdate()->find($item->product_id);
            if ($existing) {
                return $existing;
            }
        }

        $label = $item->split_label ?: sprintf('Split %03d', $sequence);
        $barcode = $this->uniqueProductBarcode($split, $sequence);
        $sku = $this->nextSplitSku($split->company_id, $source->sku, $sequence);

        $product = Product::create([
            'company_id'        => $split->company_id,
            'parent_product_id' => $source->id,
            'bulk_split_id'     => $split->id,
            'split_sequence'    => $item->split_sequence ?? $sequence,
            'sku'               => $sku,
            'name'              => $source->name.' - '.$label,
            'barcode'           => $barcode,
            'hsn_code'          => $source->hsn_code,
            'description'       => $source->description,
            'category_id'       => $source->category_id,
            'brand_id'          => $source->brand_id,
            'unit_id'           => $source->unit_id,
            'gst_rate'          => $source->gst_rate,
            'mrp'               => $source->mrp,
            'cost_price'        => $item->unit_cost,
            'dealer_price'      => $source->dealer_price,
            'wholesale_price'   => $source->wholesale_price,
            'retail_price'      => $item->retail_price ?? $source->retail_price,
            'reorder_level'     => $source->reorder_level,
            'opening_stock'     => 0,
            'current_stock'     => 0,
            'length_cm'         => $source->length_cm,
            'width_cm'          => $source->width_cm,
            'height_cm'         => $source->height_cm,
            'images'            => $source->images,
            'status'            => 'active',
            'is_rental'         => false,
        ]);

        $item->update([
            'product_id'   => $product->id,
            'product_name' => $product->name,
        ]);

        return $product;
    }

    private function nextSplitSku(int|string $companyId, ?string $parentSku, int $sequence): string
    {
        $base = $parentSku ? preg_replace('/-S\d+$/', '', $parentSku) : 'SKU';
        $candidate = $base.'-S'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
        $n = $sequence;
        while (Product::withTrashed()->forCompany($companyId)->where('sku', $candidate)->exists()) {
            $n++;
            $candidate = $base.'-S'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    private function uniqueProductBarcode(BulkSplit $split, int $seq): string
    {
        do {
            $code = $this->barcodes->forSplitUnit($split->company_id, $split->split_no, $seq);
            $seq++;
        } while (
            BulkSplitUnit::where('barcode', $code)->exists()
            || Product::forCompany($split->company_id)->where('barcode', $code)->exists()
            || ProductBatch::forCompany($split->company_id)->where('barcode', $code)->exists()
        );

        return $code;
    }

    private function createUnitBarcodes(BulkSplit $split, $item, Product $product): void
    {
        $count = max(1, (int) floor((float) $item->qty));
        for ($n = 1; $n <= $count; $n++) {
            $this->unitSeq++;
            $barcode = $n === 1 && $count === 1
                ? $product->barcode
                : $this->uniqueUnitBarcode($split, $this->unitSeq);

            BulkSplitUnit::create([
                'bulk_split_id'      => $split->id,
                'bulk_split_item_id' => $item->id,
                'product_id'         => $product->id,
                'barcode'            => $barcode,
                'unit_no'            => $n,
            ]);
        }
    }

    private function uniqueUnitBarcode(BulkSplit $split, int $seq): string
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
