<?php

namespace App\Repositories\Contracts;

use App\Models\SalesReturn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface SalesReturnRepositoryInterface
{
    /** @param array<string,mixed> $filters */
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator;

    public function findForCompany(int|string $companyId, string $id): ?SalesReturn;

    /** @param array<string,mixed> $data */
    public function create(array $data): SalesReturn;

    public function nextReturnNo(int|string $companyId): string;

    /** @return Collection<string, float> sale_item_id => returned qty */
    public function returnedQtyBySaleItem(string $saleId, ?string $excludeReturnId = null): Collection;
}
