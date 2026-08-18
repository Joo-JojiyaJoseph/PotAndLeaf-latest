<?php

namespace App\Repositories\Eloquent;

use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Repositories\Contracts\PurchaseReturnRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PurchaseReturnRepository implements PurchaseReturnRepositoryInterface
{
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return PurchaseReturn::query()
            ->forCompany($companyId)
            ->with(['supplier:id,name,supplier_code', 'purchase:id,purchase_no'])
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('return_no', 'like', "%{$filters['search']}%"))
            ->orderByDesc('return_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?PurchaseReturn
    {
        return PurchaseReturn::query()
            ->forCompany($companyId)
            ->with(['supplier', 'purchase:id,purchase_no', 'items'])
            ->whereKey($id)
            ->first();
    }

    public function create(array $data): PurchaseReturn
    {
        return PurchaseReturn::create($data);
    }

    public function nextReturnNo(int|string $companyId): string
    {
        $count = PurchaseReturn::withTrashed()->forCompany($companyId)->count();

        return 'PR-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    public function returnedQtyByPurchaseItem(string $purchaseId, ?string $excludeReturnId = null): Collection
    {
        return PurchaseReturnItem::query()
            ->whereHas('purchaseReturn', function ($q) use ($purchaseId, $excludeReturnId) {
                $q->where('purchase_id', $purchaseId)->where('status', 'confirmed');
                if ($excludeReturnId) {
                    $q->whereKeyNot($excludeReturnId);
                }
            })
            ->selectRaw('purchase_item_id, SUM(qty) as returned')
            ->groupBy('purchase_item_id')
            ->pluck('returned', 'purchase_item_id');
    }
}
