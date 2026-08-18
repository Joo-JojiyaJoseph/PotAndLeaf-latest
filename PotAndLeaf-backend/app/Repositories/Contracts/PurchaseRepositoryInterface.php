<?php

namespace App\Repositories\Contracts;

use App\Models\Purchase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PurchaseRepositoryInterface
{
    /** @param array<string,mixed> $filters */
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator;

    public function findForCompany(int|string $companyId, string $id): ?Purchase;

    /** @param array<string,mixed> $data */
    public function create(array $data): Purchase;

    /** @param array<string,mixed> $data */
    public function update(Purchase $purchase, array $data): Purchase;

    public function nextPurchaseNo(int|string $companyId): string;
}
