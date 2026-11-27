<?php

namespace App\Actions\StockVerifications;

use App\Models\Product;
use App\Models\StockVerification;
use App\Services\ActivityLogService;
use App\Services\LocationStockService;
use Illuminate\Support\Facades\DB;

/**
 * Submitting a count changes the stock AT ONCE (no approval first), so a product that is physically on
 * the shelf can be billed straight away. Each line's system_qty is frozen to live stock at that moment,
 * the adjustment is posted, and the count is flagged "applied" for head office / admin to check later.
 */
class SubmitStockVerification
{
    public function __construct(
        private readonly ApproveStockVerification $adjuster,
        private readonly ActivityLogService $activity,
        private readonly LocationStockService $locationStock,
    ) {}

    public function handle(StockVerification $verification, ?int $userId = null): StockVerification
    {
        if (! $verification->isDraft()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'Only draft counts can be submitted.']);
        }

        return DB::transaction(function () use ($verification, $userId) {
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

                $atLocation = $verification->location_id && $this->locationStock->isMultiLocation($verification->company_id);
                $system = $batchedProducts->has($product->id)
                    ? max(0, round((float) $product->current_stock - (float) \App\Models\ProductBatch::where('product_id', $product->id)->sum('remaining_qty'), 3))
                    : ($atLocation ? $this->locationStock->available($verification->location_id, $product->id) : (float) $product->current_stock);
                $item->update([
                    'system_qty' => $system,
                    'variance'   => round((float) $item->counted_qty - $system, 3),
                ]);
            }

            $verification->update(['status' => 'submitted', 'submitted_at' => now()]);

            // Stock changes now; the check by head office / admin comes afterwards.
            $this->adjuster->apply($verification->refresh(), $userId);
            $verification->update(['status' => 'applied', 'applied_at' => now(), 'applied_by' => $userId]);

            $verification->refresh()->load('items');
            $changed = $verification->items->filter(fn ($i) => abs((float) $i->variance) > 1e-6)->count();
            $this->activity->log(
                $verification->company_id, $userId, 'stock_count_applied', 'stock_verifications', 'stock_verification', $verification->id,
                "Stock count {$verification->count_no} changed the stock of {$changed} item(s); waiting to be checked",
                ['quick' => (bool) $verification->is_quick],
            );

            return $verification;
        });
    }
}
