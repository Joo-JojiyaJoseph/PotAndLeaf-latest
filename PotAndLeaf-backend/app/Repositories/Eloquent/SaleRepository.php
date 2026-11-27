<?php

namespace App\Repositories\Eloquent;

use App\Models\Sale;
use App\Repositories\Contracts\SaleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SaleRepository implements SaleRepositoryInterface
{
    public function paginateForCompany(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return Sale::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with('customer:id,name')
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['bill_kind'] ?? null), fn ($q) => $q->where('bill_kind', $filters['bill_kind']))
            ->when(filled($filters['created_by'] ?? null), fn ($q) => $q->where('created_by', $filters['created_by']))
            // Own-only roles: bills credited to them or typed in by them.
            ->when(filled($filters['mine'] ?? null), fn ($q) => $q->where(fn ($w) => $w->where('created_by', $filters['mine'])->orWhere('entered_by', $filters['mine'])))
            ->listFilters($filters, 'sale_date', ['sale_no', 'customer_name'])
            ->when(filled($filters['customer_id'] ?? null), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(filled($filters['payment_mode'] ?? null), fn ($q) => $q->where('payment_mode', $filters['payment_mode']))
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
        return \App\Support\Numbering\DocumentNumber::next(Sale::query()->where('company_id', $companyId), 'sale_no', 'INV-', 6);
    }
}
