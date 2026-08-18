<?php

namespace App\Repositories\Contracts;

use App\Models\PurchaseReturn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface PurchaseReturnRepositoryInterface
{
    /** @param array<string,mixed> $filters */
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator;

    public function findForCompany(int|string $companyId, string $id): ?PurchaseReturn;

    /** @param array<string,mixed> $data */
    public function create(array $data): PurchaseReturn;

    public function nextReturnNo(int|string $companyId): string;

    /** Confirmed returned qty per purchase_item_id for a purchase. */
    public function returnedQtyByPurchaseItem(string $purchaseId, ?string $excludeReturnId = null): Collection;
}
