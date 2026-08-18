<?php

namespace App\Services;

use App\Actions\Suppliers\CreateSupplier;
use App\Actions\Suppliers\DeleteSupplier;
use App\Actions\Suppliers\UpdateSupplier;
use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The service is the single entry point the controller talks to. Reads go
 * straight to the repository; writes are delegated to Action classes so the
 * transactional / side-effect logic stays reusable and testable.
 */
class SupplierService
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly CreateSupplier $createSupplier,
        private readonly UpdateSupplier $updateSupplier,
        private readonly DeleteSupplier $deleteSupplier,
    ) {}

    /** @param array<string,mixed> $filters */
    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return $this->suppliers->paginateForCompany($companyId, $filters);
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data): Supplier
    {
        return $this->createSupplier->handle($companyId, $data);
    }

    /** @param array<string,mixed> $data */
    public function update(Supplier $supplier, array $data): Supplier
    {
        return $this->updateSupplier->handle($supplier, $data);
    }

    public function delete(Supplier $supplier): void
    {
        $this->deleteSupplier->handle($supplier);
    }

    public function restore(int|string $companyId, string $id): ?Supplier
    {
        return $this->suppliers->restore($companyId, $id);
    }

    public function forceDelete(int|string $companyId, string $id): void
    {
        $this->suppliers->forceDelete($companyId, $id);
    }
}
