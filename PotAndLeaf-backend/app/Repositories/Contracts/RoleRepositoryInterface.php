<?php

namespace App\Repositories\Contracts;

use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RoleRepositoryInterface
{
    /** @param array<string,mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator;

    public function find(string $id): ?Role;

    /** @param array<string,mixed> $data */
    public function create(array $data): Role;

    /** @param array<string,mixed> $data */
    public function update(Role $role, array $data): Role;

    public function delete(Role $role): void;

    public function restore(string $id): ?Role;

    public function slugExists(string $slug, ?string $ignoreId = null): bool;
}
