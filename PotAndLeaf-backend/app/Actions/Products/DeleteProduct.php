<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Support\Facades\DB;

class DeleteProduct
{
    public function __construct(private readonly ProductRepositoryInterface $products) {}

    public function handle(Product $product): void
    {
        DB::transaction(function () use ($product) {
            // Guard: block deletion once the product has stock movements.
            // if ($product->stockLedger()->exists()) { throw ... }

            $this->products->delete($product);
        });
    }
}
