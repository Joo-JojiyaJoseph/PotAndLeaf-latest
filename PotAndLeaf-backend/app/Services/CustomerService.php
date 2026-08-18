<?php

namespace App\Services;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerService
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly CreateCustomer $createCustomer,
        private readonly UpdateCustomer $updateCustomer,
    ) {}

    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return $this->customers->paginateForCompany($companyId, $filters);
    }

    public function find(int|string $companyId, string $id): ?Customer
    {
        return $this->customers->findForCompany($companyId, $id);
    }

    public function create(int|string $companyId, array $data): Customer
    {
        return $this->createCustomer->handle($companyId, $data);
    }

    public function update(Customer $customer, array $data): Customer
    {
        return $this->updateCustomer->handle($customer, $data);
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }
}
