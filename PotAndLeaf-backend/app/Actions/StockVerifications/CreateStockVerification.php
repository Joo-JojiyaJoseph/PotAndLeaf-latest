<?php

namespace App\Actions\StockVerifications;

use App\Models\Product;
use App\Models\StockVerification;
use App\Repositories\Contracts\StockVerificationRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Opens a draft count. Each line snapshots the product's current system stock
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

            $rows = [];
            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);
                if (! $product) {
                    continue;
                }
                $system = (float) $product->current_stock;
                $counted = (float) $item['counted_qty'];
                $rows[] = [
                    'product_id'   => $product->id,
                    'product_name' => $product->name,
                    'system_qty'   => $system,
                    'counted_qty'  => $counted,
                    'variance'     => round($counted - $system, 3),
                    'unit_cost'    => (float) $product->cost_price,
                ];
            }
            $verification->items()->createMany($rows);

            return $verification->load('items');
        });
    }
}
