<?php

namespace App\Services;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\DeleteProduct;
use App\Actions\Products\UpdateProduct;
use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly CreateProduct $createProduct,
        private readonly UpdateProduct $updateProduct,
        private readonly DeleteProduct $deleteProduct,
    ) {}

    /** @param array<string,mixed> $filters */
    public function list(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        return $this->products->paginateForCompany($companyId, $filters);
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data, ?int $userId = null): Product
    {
        return $this->createProduct->handle($companyId, $data, $userId);
    }

    /** @param array<string,mixed> $data */
    public function update(Product $product, array $data, ?int $userId = null): Product
    {
        return $this->updateProduct->handle($product, $data, $userId);
    }

    public function delete(Product $product): void
    {
        $this->deleteProduct->handle($product);
    }

    public function restore(int|string $companyId, string $id): ?Product
    {
        return $this->products->restore($companyId, $id);
    }
}
