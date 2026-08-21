<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProduct
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryService $inventory,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(Product $product, array $data, ?int $userId = null): Product
    {
        return DB::transaction(function () use ($product, $data, $userId) {
            $suppliers = $this->pullSuppliers($data);
            $data = $this->normalizeDecimals($data);
            unset($data['sku']);

            if (! empty($data['company_id']) && (string) $data['company_id'] !== (string) $product->company_id) {
                $data['company_id'] = (int) $data['company_id'];
            } else {
                unset($data['company_id']);
            }

            $previousOpening = (float) $product->opening_stock;
            $nextOpening = array_key_exists('opening_stock', $data)
                ? (float) $data['opening_stock']
                : $previousOpening;
            $adjustment = round($nextOpening - $previousOpening, 3);

            if ($adjustment < 0 && (float) $product->current_stock + $adjustment < -0.0001) {
                throw ValidationException::withMessages([
                    'opening_stock' => 'Cannot reduce opening stock by more than the current stock on hand.',
                ]);
            }

            $updated = $this->products->update($product, $data);
            $updated->suppliers()->sync($suppliers);

            if (abs($adjustment) > 0.0001) {
                $this->inventory->post(
                    $updated,
                    $adjustment > 0 ? 'in' : 'out',
                    abs($adjustment),
                    (float) ($updated->cost_price ?? 0),
                    'opening_stock_adjustment',
                    $updated->id,
                    'Opening stock adjustment',
                    $userId,
                );
                $updated->save();
            }

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
