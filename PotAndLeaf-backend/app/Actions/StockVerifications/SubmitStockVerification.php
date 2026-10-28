<?php

namespace App\Actions\StockVerifications;

use App\Models\Product;
use App\Models\StockVerification;
use Illuminate\Support\Facades\DB;

/**
 * Submitting freezes each line's system_qty to live stock at submission time so
 * the count sheet reflects what was true when it entered the approval queue.
 */
class SubmitStockVerification
{
    public function handle(StockVerification $verification, ?int $userId = null): StockVerification
    {
        if (! $verification->isDraft()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'Only draft counts can be submitted.']);
        }

        return DB::transaction(function () use ($verification) {
            $verification->loadMissing('items');

            $batchedProducts = $verification->items->whereNotNull('product_batch_id')->pluck('product_id')->unique()->flip();

            foreach ($verification->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                if ($item->product_batch_id) {
                    // A batch line: compare with that batch's stock now.
                    $system = (float) (\App\Models\ProductBatch::query()->whereKey($item->product_batch_id)->value('remaining_qty') ?? 0);
                    $item->update(['system_qty' => $system, 'variance' => round((float) $item->counted_qty - $system, 3)]);
                    continue;
                }

                $product = Product::forCompany($verification->company_id)
                    ->find($item->product_id);

                if (! $product) {
                    continue;
                }

                $system = $batchedProducts->has($product->id)
                    ? max(0, round((float) $product->current_stock - (float) \App\Models\ProductBatch::where('product_id', $product->id)->sum('remaining_qty'), 3))
                    : (float) $product->current_stock;
                $item->update([
                    'system_qty' => $system,
                    'variance'   => round((float) $item->counted_qty - $system, 3),
                ]);
            }

            $verification->update(['status' => 'submitted', 'submitted_at' => now()]);

            return $verification->refresh()->load('items');
        });
    }
}
