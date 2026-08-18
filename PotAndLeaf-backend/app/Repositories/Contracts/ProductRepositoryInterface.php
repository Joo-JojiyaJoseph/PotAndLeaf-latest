<?php

namespace App\Repositories\Contracts;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductRepositoryInterface
{
    /** @param array<string,mixed> $filters */
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator;

    public function findForCompany(int|string $companyId, string $id): ?Product;

    /** @param array<string,mixed> $data */
    public function create(array $data): Product;

    /** @param array<string,mixed> $data */
    public function update(Product $product, array $data): Product;

    public function delete(Product $product): void;

    public function restore(int|string $companyId, string $id): ?Product;

    public function skuExists(int|string $companyId, string $sku, ?string $ignoreId = null): bool;
}
