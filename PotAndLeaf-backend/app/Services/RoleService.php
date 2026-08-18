<?php

namespace App\Services;

use App\Actions\Rbac\CreateRole;
use App\Actions\Rbac\DeleteRole;
use App\Actions\Rbac\UpdateRole;
use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RoleService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly CreateRole $createRole,
        private readonly UpdateRole $updateRole,
        private readonly DeleteRole $deleteRole,
    ) {}

    /** @param array<string,mixed> $filters */
    public function list(array $filters): LengthAwarePaginator
    {
        return $this->roles->paginate($filters);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): Role
    {
        return $this->createRole->handle($data);
    }

    /** @param array<string,mixed> $data */
    public function update(Role $role, array $data): Role
    {
        return $this->updateRole->handle($role, $data);
    }

    public function delete(Role $role): void
    {
        $this->deleteRole->handle($role);
    }

    public function restore(string $id): ?Role
    {
        return $this->roles->restore($id);
    }
}
