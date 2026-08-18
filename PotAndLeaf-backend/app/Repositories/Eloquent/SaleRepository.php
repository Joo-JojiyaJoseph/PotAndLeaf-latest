<?php

namespace App\Repositories\Eloquent;

use App\Models\Sale;
use App\Repositories\Contracts\SaleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SaleRepository implements SaleRepositoryInterface
{
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return Sale::query()
            ->forCompany($companyId)
            ->with('customer:id,name')
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('sale_no', 'like', "%{$filters['search']}%"))
            ->orderByDesc('sale_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?Sale
    {
        return Sale::query()
            ->forCompany($companyId)
            ->with(['items', 'customer:id,name,type'])
            ->whereKey($id)
            ->first();
    }

    public function create(array $data): Sale
    {
        return Sale::create($data);
    }

    public function nextSaleNo(int|string $companyId): string
    {
        $count = Sale::withTrashed()->forCompany($companyId)->count();

        return 'INV-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
