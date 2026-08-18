<?php

namespace App\Repositories\Eloquent;

use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Repositories\Contracts\SalesReturnRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class SalesReturnRepository implements SalesReturnRepositoryInterface
{
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return SalesReturn::query()
            ->forCompany($companyId)
            ->with(['customer:id,name,customer_code', 'sale:id,sale_no'])
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('return_no', 'like', "%{$filters['search']}%"))
            ->orderByDesc('return_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?SalesReturn
    {
        return SalesReturn::query()
            ->forCompany($companyId)
            ->with(['customer', 'sale:id,sale_no,payment_mode', 'items'])
            ->whereKey($id)
            ->first();
    }

    public function create(array $data): SalesReturn
    {
        return SalesReturn::create($data);
    }

    public function nextReturnNo(int|string $companyId): string
    {
        $count = SalesReturn::withTrashed()->forCompany($companyId)->count();

        return 'SR-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    public function returnedQtyBySaleItem(string $saleId, ?string $excludeReturnId = null): Collection
    {
        return SalesReturnItem::query()
            ->whereHas('salesReturn', function ($q) use ($saleId, $excludeReturnId) {
                $q->where('sale_id', $saleId)->where('status', 'confirmed');
                if ($excludeReturnId) {
                    $q->whereKeyNot($excludeReturnId);
                }
            })
            ->selectRaw('sale_item_id, SUM(qty) as returned')
            ->groupBy('sale_item_id')
            ->pluck('returned', 'sale_item_id');
    }
}
