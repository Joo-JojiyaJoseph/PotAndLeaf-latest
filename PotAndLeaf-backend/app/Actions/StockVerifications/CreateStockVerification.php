<?php

namespace App\Actions\StockVerifications;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockVerification;
use App\Repositories\Contracts\StockVerificationRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Opens a draft count. Each line snapshots the system stock of the product, or
 * of one batch when the line names a batch
 * (so the variance is meaningful) alongside the physically counted quantity.
 */
class CreateStockVerification
{
    public function __construct(private readonly StockVerificationRepositoryInterface $verifications) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data, ?int $userId = null): StockVerification
    {
        $productIds = collect($data['items'])->pluck('product_id')->filter()->all();
        $products = Product::forCompany($companyId)
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'current_stock', 'cost_price'])
            ->keyBy('id');

        return DB::transaction(function () use ($companyId, $data, $products) {
            $verification = $this->verifications->create([
                'company_id'    => $companyId,
                'count_no'      => $this->verifications->nextCountNo($companyId),
                'count_date'    => $data['count_date'],
                'location_note' => $data['location_note'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'status'        => 'draft',
            ]);

            $batchIds = collect($data['items'])->pluck('product_batch_id')->filter()->all();
            $batches = ProductBatch::query()->whereIn('id', $batchIds)->get()->keyBy('id');
            // Products counted batch by batch in this sheet.
            $batchedProducts = $batches->pluck('product_id')->unique()->flip();

            $rows = [];
            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);
                if (! $product) {
                    continue;
                }
                $batch = ! empty($item['product_batch_id']) ? $batches->get($item['product_batch_id']) : null;
                if ($batch) {
                    // One batch: its own stock and cost.
                    $system = (float) $batch->remaining_qty;
                    $cost = (float) $batch->cost_price > 0 ? (float) $batch->cost_price : (float) $product->cost_price;
                } elseif ($batchedProducts->has($product->id)) {
                    // "Not in a batch" line beside batch lines: stock outside every batch.
                    $system = max(0, round((float) $product->current_stock - (float) ProductBatch::where('product_id', $product->id)->sum('remaining_qty'), 3));
                    $cost = (float) $product->cost_price;
                } else {
                    $system = (float) $product->current_stock;
                    $cost = (float) $product->cost_price;
                }
                $counted = (float) $item['counted_qty'];
                $rows[] = [
                    'product_id'       => $product->id,
                    'product_batch_id' => $batch?->id,
                    'batch_no'         => $batch?->batch_no,
                    'product_name'     => $product->name,
                    'system_qty'       => $system,
                    'counted_qty'      => $counted,
                    'variance'         => round($counted - $system, 3),
                    'unit_cost'        => $cost,
                ];
            }
            $verification->items()->createMany($rows);

            return $verification->load('items');
        });
    }
}
