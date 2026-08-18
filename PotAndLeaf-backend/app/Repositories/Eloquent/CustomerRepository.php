<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerRepository implements CustomerRepositoryInterface
{
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return Customer::query()
            ->forCompany($companyId)
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['type'] ?? null), fn ($q) => $q->where('type', $filters['type']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->search($filters['search']))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?Customer
    {
        return Customer::query()->forCompany($companyId)->whereKey($id)->first();
    }

    public function create(array $data): Customer
    {
        return Customer::create($data);
    }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer->refresh();
    }

    public function nextCustomerCode(int|string $companyId): string
    {
        $count = Customer::withTrashed()->forCompany($companyId)->count();

        return 'CUST-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }
}
