<?php

namespace App\Support\Lookup;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Thin orchestration over LookupRepository. Writes are transactional so a
 * lookup master behaves the same way as the rich modules, just without the
 * per-use-case Action classes it doesn't need.
 */
class LookupService
{
    public function __construct(private readonly LookupRepository $repository) {}

    /** @param array<string,mixed> $filters */
    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return $this->repository->paginateForCompany($companyId, $filters);
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data): Model
    {
        return DB::transaction(fn () => $this->repository->create($companyId, $data));
    }

    /** @param array<string,mixed> $data */
    public function update(Model $model, array $data): Model
    {
        return DB::transaction(fn () => $this->repository->update($model, $data));
    }

    public function delete(Model $model): void
    {
        DB::transaction(fn () => $this->repository->delete($model));
    }

    public function restore(int|string $companyId, string $id): ?Model
    {
        return $this->repository->restore($companyId, $id);
    }
}
