<?php

namespace App\Actions\Rbac;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ensures the global protected "Administrator" role exists and optionally
 * assigns it to a user within a company. Idempotent.
 */
class EnsureCompanyAdminRole
{
    public function handle(Company $company, ?User $assignTo = null): Role
    {
        return DB::transaction(function () use ($company, $assignTo) {
            $role = Role::withTrashed()->firstOrCreate(
                ['slug' => 'administrator'],
                ['name' => 'Administrator', 'description' => 'Full access to every module.', 'is_system' => true],
            );

            if ($role->trashed()) {
                $role->restore();
            }

            $wildcard = Permission::where('name', '*')->first();
            if ($wildcard) {
                $role->permissions()->syncWithoutDetaching([$wildcard->id]);
            }

            if ($assignTo) {
                DB::table('role_user')->updateOrInsert(
                    ['user_id' => $assignTo->id, 'company_id' => $company->id],
                    ['role_id' => $role->id, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            return $role;
        });
    }
}
