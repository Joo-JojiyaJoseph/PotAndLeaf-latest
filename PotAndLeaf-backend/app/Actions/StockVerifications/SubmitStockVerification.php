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

            foreach ($verification->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::forCompany($verification->company_id)
                    ->find($item->product_id);

                if (! $product) {
                    continue;
                }

                $system = (float) $product->current_stock;
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
