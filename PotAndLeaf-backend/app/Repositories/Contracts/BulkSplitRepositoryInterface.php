<?php

namespace App\Repositories\Contracts;

use App\Models\BulkSplit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BulkSplitRepositoryInterface
{
    /** @param array<string,mixed> $filters */
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator;

    public function findForCompany(int|string $companyId, string $id): ?BulkSplit;

    /** @param array<string,mixed> $data */
    public function create(array $data): BulkSplit;

    public function nextSplitNo(int|string $companyId): string;
}
