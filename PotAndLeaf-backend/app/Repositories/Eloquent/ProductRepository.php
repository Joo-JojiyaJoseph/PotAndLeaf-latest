<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductRepository implements ProductRepositoryInterface
{
    private const SORTABLE = ['sku', 'name', 'status', 'current_stock', 'retail_price', 'created_at'];

    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $dir = strtolower($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return Product::query()
            ->with(['category:id,name', 'brand:id,name', 'unit:id,name,short_name'])
            ->forCompany($companyId)
            ->search($filters['search'] ?? null)
            ->when(filled($filters['category_id'] ?? null), fn ($q) => $q->where('category_id', $filters['category_id']))
            ->when(filled($filters['brand_id'] ?? null), fn ($q) => $q->where('brand_id', $filters['brand_id']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['low_stock'] ?? null) === '1',
                fn ($q) => $q->whereColumn('current_stock', '<=', 'reorder_level'))
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?Product
    {
        return Product::query()
            ->with(['category', 'brand', 'unit', 'suppliers'])
            ->forCompany($companyId)->whereKey($id)->first();
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->refresh();
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    public function restore(int|string $companyId, string $id): ?Product
    {
        $product = Product::onlyTrashed()->forCompany($companyId)->whereKey($id)->first();
        $product?->restore();

        return $product;
    }

    public function skuExists(int|string $companyId, string $sku, ?string $ignoreId = null): bool
    {
        return Product::query()->forCompany($companyId)->where('sku', $sku)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
    }
}
