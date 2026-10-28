<?php

namespace App\Actions\StockVerifications;

use App\Models\Product;
use App\Models\ProductBatch;
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

            // Batch lines first (each batch is set to its own count), then product lines.
            $items = $verification->items->sortBy(fn ($i) => $i->product_batch_id ? 0 : 1);
            $batchedProducts = $verification->items->whereNotNull('product_batch_id')->pluck('product_id')->unique()->flip();

            foreach ($items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                if ($item->product_batch_id) {
                    $this->approveBatchLine($verification, $item, $userId);
                    continue;
                }

                $product = Product::forCompany($verification->company_id)
                    ->lockForUpdate()
                    ->find($item->product_id);

                if (! $product) {
                    continue;
                }

                // Beside batch lines this line is the stock outside every batch.
                $outside = $batchedProducts->has($product->id);
                $before = $outside
                    ? max(0, round((float) $product->current_stock - (float) ProductBatch::where('product_id', $product->id)->sum('remaining_qty'), 3))
                    : (float) $product->current_stock;
                $delta = round((float) $item->counted_qty - $before, 3);

                // Persist the approval-time baseline so the count sheet matches the ledger posting.
                $item->update([
                    'system_qty' => $before,
                    'variance'   => $delta,
                ]);

                if (abs($delta) < 1e-6) {
                    continue;
                }

                // Batches move with the product total so the batch/barcode
                // screen matches the shelf, and each ledger line names its batch:
                //   shortage → taken off the oldest batches first (FIFO)
                //   excess   → added to the newest batch of this product
                $slices = $outside ? [] : ($delta < 0
                    ? $this->inventory->consumeBatchesFifo($product->id, abs($delta))
                    : $this->addToNewestBatch($product->id, $delta));

                $covered = round(array_sum(array_column($slices, 'qty')), 3);
                $postings = array_map(fn ($s) => [$s['qty'], $s['batch_id'], $s['batch_no']], $slices);
                $rest = round(abs($delta) - $covered, 3);
                if ($rest > 0.0005) {
                    $postings[] = [$rest, null, null]; // product has no (or not enough) batch stock
                }

                foreach ($postings as [$qty, $batchId, $batchNo]) {
                    $this->inventory->post(
                        product: $product,
                        direction: $delta > 0 ? 'in' : 'out',
                        qty: $qty,
                        unitCost: (float) $item->unit_cost,
                        referenceType: 'stock-verification',
                        referenceId: $verification->id,
                        note: "Count {$verification->count_no}".($batchNo ? " · batch {$batchNo}" : ''),
                        userId: $userId,
                        productBatchId: $batchId,
                    );
                }
                $product->save();

                $item->update(['batch_adjustments' => array_map(fn ($s) => [
                    'batch_id' => $s['batch_id'],
                    'batch_no' => $s['batch_no'],
                    'qty'      => $delta < 0 ? -$s['qty'] : $s['qty'],
                ], $slices)]);
            }

            $verification->update([
                'status'      => 'approved',
                'approved_at' => now(),
                'approved_by' => $userId,
            ]);

            return $verification->refresh()->load('items');
        });
    }

    /** One counted batch: the batch and the product move by the same difference. */
    private function approveBatchLine(StockVerification $verification, $item, ?int $userId): void
    {
        $batch = ProductBatch::query()->lockForUpdate()->find($item->product_batch_id);
        $product = Product::forCompany($verification->company_id)->lockForUpdate()->find($item->product_id);
        if (! $batch || ! $product) {
            return;
        }
        $before = (float) $batch->remaining_qty;
        $delta = round((float) $item->counted_qty - $before, 3);
        $item->update(['system_qty' => $before, 'variance' => $delta]);
        if (abs($delta) < 1e-6) {
            return;
        }

        $batch->remaining_qty = round((float) $item->counted_qty, 3);
        $batch->status = $batch->remaining_qty > 0 ? 'active' : 'depleted';
        $batch->save();

        $this->inventory->post(
            product: $product,
            direction: $delta > 0 ? 'in' : 'out',
            qty: abs($delta),
            unitCost: (float) $item->unit_cost,
            referenceType: 'stock-verification',
            referenceId: $verification->id,
            note: "Count {$verification->count_no} · batch {$batch->batch_no}",
            userId: $userId,
            productBatchId: $batch->id,
        );
        $product->save();

        $item->update(['batch_adjustments' => [['batch_id' => $batch->id, 'batch_no' => $batch->batch_no, 'qty' => $delta]]]);
    }

    /** @return list<array{batch_id: string, batch_no: ?string, qty: float}> */
    private function addToNewestBatch(string $productId, float $qty): array
    {
        $batch = ProductBatch::query()
            ->where('product_id', $productId)
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        if (! $batch) {
            return [];
        }

        $batch->remaining_qty = round((float) $batch->remaining_qty + $qty, 3);
        if ($batch->status === 'depleted') {
            $batch->status = 'active';
        }
        $batch->save();

        return [['batch_id' => $batch->id, 'batch_no' => $batch->batch_no, 'qty' => round($qty, 3)]];
    }
}
