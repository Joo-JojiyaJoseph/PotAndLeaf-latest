<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteProduct
{
    public function __construct(private readonly ProductRepositoryInterface $products) {}

    public function handle(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            $dependencies = $this->dependencies($locked);
            if ($dependencies !== []) {
                throw ValidationException::withMessages([
                    'product' => 'This product cannot be deleted because it has '.implode(', ', $dependencies).'. Deactivate it instead so historical records stay intact.',
                ]);
            }

            $this->products->delete($locked);
        });
    }

    /** @return list<string> */
    private function dependencies(Product $product): array
    {
        $id = $product->id;
        $checks = [
            'purchases' => DB::table('purchase_items')->where('product_id', $id)->exists(),
            'sales' => DB::table('sale_items')->where('product_id', $id)->exists(),
            'stock movements' => DB::table('stock_ledger_entries')->where('product_id', $id)->exists(),
            'batches' => DB::table('product_batches')->where('product_id', $id)->exists(),
            'purchase returns' => DB::table('purchase_return_items')->where('product_id', $id)->exists(),
            'sales returns' => DB::table('sales_return_items')->where('product_id', $id)->exists(),
            'damage records' => DB::table('damage_entries')->where('product_id', $id)->whereNull('deleted_at')->exists(),
            'transfers' => DB::table('stock_transfer_items')->where('product_id', $id)->exists(),
            'bulk splits' => DB::table('bulk_splits')->where('source_product_id', $id)->whereNull('deleted_at')->exists(),
        ];

        return array_keys(array_filter($checks));
    }
}
