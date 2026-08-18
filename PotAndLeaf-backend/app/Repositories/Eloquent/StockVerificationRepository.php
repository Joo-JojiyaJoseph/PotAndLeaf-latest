<?php

namespace App\Repositories\Eloquent;

use App\Models\StockVerification;
use App\Repositories\Contracts\StockVerificationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StockVerificationRepository implements StockVerificationRepositoryInterface
{
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return StockVerification::query()
            ->forCompany($companyId)
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('count_no', 'like', "%{$filters['search']}%"))
            ->orderByDesc('count_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?StockVerification
    {
        return StockVerification::query()
            ->forCompany($companyId)
            ->with('items')
            ->whereKey($id)
            ->first();
    }

    public function create(array $data): StockVerification
    {
        return StockVerification::create($data);
    }

    public function nextCountNo(int|string $companyId): string
    {
        $count = StockVerification::withTrashed()->forCompany($companyId)->count();

        return 'SV-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
