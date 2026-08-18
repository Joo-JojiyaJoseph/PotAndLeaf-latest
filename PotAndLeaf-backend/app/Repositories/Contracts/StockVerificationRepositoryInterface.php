<?php

namespace App\Repositories\Contracts;

use App\Models\StockVerification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StockVerificationRepositoryInterface
{
    /** @param array<string,mixed> $filters */
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator;

    public function findForCompany(int|string $companyId, string $id): ?StockVerification;

    /** @param array<string,mixed> $data */
    public function create(array $data): StockVerification;

    public function nextCountNo(int|string $companyId): string;
}
