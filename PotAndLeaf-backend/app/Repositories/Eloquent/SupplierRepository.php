<?php

namespace App\Repositories\Eloquent;

use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SupplierRepository implements SupplierRepositoryInterface
{
    /** Columns that are safe to sort by (never trust raw client input). */
    private const SORTABLE = ['supplier_code', 'name', 'status', 'outstanding', 'created_at'];

    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? '', self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $dir = strtolower($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return Supplier::query()
            ->forCompany($companyId)
            ->search($filters['search'] ?? null)
            ->when(
                filled($filters['status'] ?? null),
                fn ($q) => $q->where('status', $filters['status'])
            )
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?Supplier
    {
        return Supplier::query()->forCompany($companyId)->whereKey($id)->first();
    }

    public function create(array $data): Supplier
    {
        return Supplier::create($data);
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->update($data);

        return $supplier->refresh();
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }

    public function restore(int|string $companyId, string $id): ?Supplier
    {
        $supplier = Supplier::onlyTrashed()->forCompany($companyId)->whereKey($id)->first();
        $supplier?->restore();

        return $supplier;
    }

    public function forceDelete(int|string $companyId, string $id): void
    {
        Supplier::withTrashed()->forCompany($companyId)->whereKey($id)->first()?->forceDelete();
    }

    public function codeExists(int|string $companyId, string $code, ?string $ignoreId = null): bool
    {
        return Supplier::query()
            ->forCompany($companyId)
            ->where('supplier_code', $code)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }
}
