<?php

namespace App\Repositories\Eloquent;

use App\Models\Purchase;
use App\Repositories\Contracts\PurchaseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PurchaseRepository implements PurchaseRepositoryInterface
{
    public function paginateForCompany(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return Purchase::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with('supplier:id,name,supplier_code')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['supplier_id'] ?? null), fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->listFilters($filters, 'purchase_date', ['purchase_no', 'invoice_no'], ['supplier' => 'name'])
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
        // Purchase bills (GRN) are numbered GRN-…; purchase orders keep PO-…,
        // so the two documents can no longer share a number. Older bills keep
        // their PO-… numbers; the next free GRN number is used.
        $count = Purchase::forCompany($companyId)->count();
        do {
            $no = 'GRN-'.str_pad((string) (++$count), 6, '0', STR_PAD_LEFT);
        } while (Purchase::forCompany($companyId)->where('purchase_no', $no)->exists());

        return $no;
    }

    public function nextInvoiceNo(int|string $companyId): string
    {
        $last = Purchase::query()
            ->forCompany($companyId)
            ->where('invoice_no', 'like', 'INV-%')
            ->orderByDesc('invoice_no')
            ->value('invoice_no');

        $n = 0;
        if (is_string($last) && preg_match('/INV-(\d+)$/', $last, $m)) {
            $n = (int) $m[1];
        }

        return 'INV-'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }
}
