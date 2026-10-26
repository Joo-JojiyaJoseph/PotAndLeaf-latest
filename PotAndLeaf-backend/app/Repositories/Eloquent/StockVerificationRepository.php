<?php

namespace App\Repositories\Eloquent;

use App\Models\StockVerification;
use App\Repositories\Contracts\StockVerificationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StockVerificationRepository implements StockVerificationRepositoryInterface
{
    public function paginateForCompany(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return StockVerification::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->listFilters($filters, 'count_date', ['count_no'])
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
        return \App\Support\Numbering\DocumentNumber::next(StockVerification::query()->where('company_id', $companyId), 'count_no', 'SV-', 6);
    }
}
