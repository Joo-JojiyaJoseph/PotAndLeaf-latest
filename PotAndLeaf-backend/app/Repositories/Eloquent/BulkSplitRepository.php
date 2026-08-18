<?php

namespace App\Repositories\Eloquent;

use App\Models\BulkSplit;
use App\Repositories\Contracts\BulkSplitRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BulkSplitRepository implements BulkSplitRepositoryInterface
{
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return BulkSplit::query()
            ->forCompany($companyId)
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('split_no', 'like', "%{$filters['search']}%"))
            ->orderByDesc('split_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?BulkSplit
    {
        return BulkSplit::query()
            ->forCompany($companyId)
            ->with(['items', 'sourceProduct:id,sku,name'])
            ->whereKey($id)
            ->first();
    }

    public function create(array $data): BulkSplit
    {
        return BulkSplit::create($data);
    }

    public function nextSplitNo(int|string $companyId): string
    {
        $count = BulkSplit::withTrashed()->forCompany($companyId)->count();

        return 'BS-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
