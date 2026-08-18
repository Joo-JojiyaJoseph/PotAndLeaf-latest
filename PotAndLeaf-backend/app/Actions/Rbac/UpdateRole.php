<?php

namespace App\Actions\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Support\Facades\DB;

class UpdateRole
{
    public function __construct(private readonly RoleRepositoryInterface $roles) {}

    /** @param array<string,mixed> $data */
    public function handle(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $names = $data['permissions'] ?? [];
            unset($data['permissions']);

            // System roles keep their name/slug locked; only permissions change.
            if ($role->is_system) {
                unset($data['name'], $data['slug']);
            }

            $updated = $this->roles->update($role, $data);
            $updated->permissions()->sync(
                empty($names) ? [] : Permission::whereIn('name', $names)->pluck('id')->all()
            );

            return $updated->load('permissions');
        });
    }
}
