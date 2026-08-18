<?php

namespace App\Repositories\Contracts;

use App\Models\Sale;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SaleRepositoryInterface
{
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator;

    public function findForCompany(int|string $companyId, string $id): ?Sale;

    public function create(array $data): Sale;

    public function nextSaleNo(int|string $companyId): string;
}
