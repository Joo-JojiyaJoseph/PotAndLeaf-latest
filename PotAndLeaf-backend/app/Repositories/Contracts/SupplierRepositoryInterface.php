<?php

namespace App\Repositories\Contracts;

use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * All database access for suppliers goes through this contract.
 * Controllers and services never touch Eloquent directly — that keeps
 * data access swappable and testable.
 */
interface SupplierRepositoryInterface
{
    /** @param array{search?:string,status?:string,sort?:string,dir?:string,per_page?:int} $filters */
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator;

    public function findForCompany(int|string $companyId, string $id): ?Supplier;

    /** @param array<string,mixed> $data */
    public function create(array $data): Supplier;

    /** @param array<string,mixed> $data */
    public function update(Supplier $supplier, array $data): Supplier;

    public function delete(Supplier $supplier): void;

    public function restore(int|string $companyId, string $id): ?Supplier;

    public function forceDelete(int|string $companyId, string $id): void;

    /** True when a supplier_code is already taken in the team (excluding $ignoreId). */
    public function codeExists(int|string $companyId, string $code, ?string $ignoreId = null): bool;
}
