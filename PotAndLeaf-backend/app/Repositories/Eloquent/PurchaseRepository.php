<?php

namespace App\Repositories\Eloquent;

use App\Models\Purchase;
use App\Repositories\Contracts\PurchaseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PurchaseRepository implements PurchaseRepositoryInterface
{
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return Purchase::query()
            ->forCompany($companyId)
            ->with('supplier:id,name,supplier_code')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['supplier_id'] ?? null), fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where(function ($inner) use ($filters) {
                $inner->where('purchase_no', 'like', "%{$filters['search']}%")
                    ->orWhere('invoice_no', 'like', "%{$filters['search']}%");
            }))
            ->orderByDesc('purchase_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?Purchase
    {
        return Purchase::query()
            ->forCompany($companyId)
            ->with(['supplier', 'items'])
            ->whereKey($id)
            ->first();
    }

    public function create(array $data): Purchase
    {
        return Purchase::create($data);
    }

    public function update(Purchase $purchase, array $data): Purchase
    {
        $purchase->update($data);

        return $purchase->refresh();
    }

    public function nextPurchaseNo(int|string $companyId): string
    {
        $count = Purchase::withTrashed()->forCompany($companyId)->count();

        return 'PO-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
