<?php

namespace App\Actions\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CreateRole
{
    public function __construct(private readonly RoleRepositoryInterface $roles) {}

    /** @param array<string,mixed> $data */
    public function handle(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $permissions = $this->pullPermissions($data);

            $role = $this->roles->create([
                'name'        => $data['name'],
                'slug'        => $data['slug'],
                'description' => $data['description'] ?? null,
                'is_system'   => false,
            ]);

            $role->permissions()->sync($this->resolveIds($permissions));

            return $role->load('permissions');
        });
    }

    private function pullPermissions(array &$data): array
    {
        $names = $data['permissions'] ?? [];
        unset($data['permissions']);

        return $names;
    }

    /** Map permission names to ids, ignoring any not in the catalog. */
    private function resolveIds(array $names): array
    {
        if (empty($names)) {
            return [];
        }

        return Permission::whereIn('name', $names)->pluck('id')->all();
    }
}
