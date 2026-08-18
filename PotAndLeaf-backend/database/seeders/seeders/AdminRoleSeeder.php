<?php

namespace Database\Seeders;

use App\Actions\Rbac\EnsureCompanyAdminRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Runs after PermissionSeeder so the "*" permission exists. Creates the
 * protected Administrator role in every company and assigns the admin user.
 */
class AdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@potandleaf.test')->first();
        $action = app(EnsureCompanyAdminRole::class);

        Company::all()->each(fn (Company $company) => $action->handle($company, $admin));
    }
}
