<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Support\Facades\DB;

class UpdateProduct
{
    public function __construct(private readonly ProductRepositoryInterface $products) {}

    /** @param array<string,mixed> $data */
    public function handle(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $suppliers = $this->pullSuppliers($data);
            $data = $this->normalizeDecimals($data);
            unset($data['sku']);

            // Stock is moved by inventory transactions, never edited directly,
            // so opening_stock changes don't touch current_stock here.
            $updated = $this->products->update($product, $data);
            $updated->suppliers()->sync($suppliers);

            return $updated->load('suppliers');
        });
    }

    /** Coalesce null pricing/stock into 0 — DB columns are NOT NULL. */
    private function normalizeDecimals(array $data): array
    {
        foreach ([
            'gst_rate', 'mrp', 'cost_price', 'dealer_price',
            'wholesale_price', 'retail_price', 'reorder_level', 'opening_stock',
        ] as $field) {
            if (array_key_exists($field, $data) && ($data[$field] === null || $data[$field] === '')) {
                $data[$field] = 0;
            }
        }

        return $data;
    }

    private function pullSuppliers(array &$data): array
    {
        $rows = $data['suppliers'] ?? [];
        unset($data['suppliers']);

        return collect($rows)
            ->filter(fn ($r) => filled($r['supplier_id'] ?? null))
            ->mapWithKeys(fn ($r) => [
                $r['supplier_id'] => [
                    'supplier_price' => $r['supplier_price'] ?? 0,
                    'is_primary'     => (bool) ($r['is_primary'] ?? false),
                ],
            ])->all();
    }
}
