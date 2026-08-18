<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CreateProduct
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data): Product
    {
        return DB::transaction(function () use ($companyId, $data) {
            $suppliers = $this->pullSuppliers($data);
            $data = $this->normalizeDecimals($data);

            if (empty($data['sku'])) {
                $count = Product::withTrashed()->forCompany($companyId)->count();
                $data['sku'] = 'SKU-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            }

            $product = $this->products->create([
                ...$data,
                'company_id'    => $companyId,
                'current_stock' => $data['opening_stock'] ?? 0,
            ]);

            $product->suppliers()->sync($suppliers);

            return $product->load('suppliers');
        });
    }

    /** Coalesce null pricing/stock into 0 — DB columns are NOT NULL. */
    private function normalizeDecimals(array $data): array
    {
        foreach ([
            'gst_rate', 'mrp', 'cost_price', 'dealer_price',
            'wholesale_price', 'retail_price', 'reorder_level', 'opening_stock',
        ] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $data[$field] = 0;
            }
        }

        return $data;
    }

    /**
     * Convert the form's supplier rows into a sync payload:
     * [supplier_id => ['supplier_price' => x, 'is_primary' => bool]]
     */
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
