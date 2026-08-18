<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_super_admin || $user->hasPermission('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->is_super_admin || $user->hasPermission('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->is_super_admin;
    }

    public function update(User $user, Role $role): bool
    {
        return $user->is_super_admin;
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->is_super_admin && ! $role->is_system;
    }

    public function restore(User $user, Role $role): bool
    {
        return $user->is_super_admin;
    }
}
