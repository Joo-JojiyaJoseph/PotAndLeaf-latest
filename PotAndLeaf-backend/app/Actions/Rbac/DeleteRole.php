<?php

namespace App\Actions\Rbac;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteRole
{
    public function __construct(private readonly RoleRepositoryInterface $roles) {}

    public function handle(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'System roles cannot be deleted.',
            ]);
        }

        DB::transaction(fn () => $this->roles->delete($role));
    }
}
