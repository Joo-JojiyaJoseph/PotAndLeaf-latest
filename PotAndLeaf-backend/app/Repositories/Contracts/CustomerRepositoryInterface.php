<?php

namespace App\Repositories\Contracts;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerRepositoryInterface
{
    /** @param array<string,mixed> $filters */
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator;

    public function findForCompany(int|string $companyId, string $id): ?Customer;

    /** @param array<string,mixed> $data */
    public function create(array $data): Customer;

    /** @param array<string,mixed> $data */
    public function update(Customer $customer, array $data): Customer;

    public function nextCustomerCode(int|string $companyId): string;
}
