<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * HO access is the Admin user (is_super_admin), not a dummy company.
 * Remove the seeded "Pot & Leaf _ Super Admin" / POTLEAF company from the
 * workspace switcher and company list.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->where('email', 'admin@potandleaf.test')
            ->update(['name' => 'Admin', 'is_super_admin' => true, 'is_active' => true]);

        Company::query()
            ->where(function ($q) {
                $q->where('code', 'POTLEAF')
                    ->orWhere('name', 'like', '%Super Admin%');
            })
            ->get()
            ->each(function (Company $company) {
                $company->users()->detach();
                $company->forceFill([
                    'is_active'    => false,
                    'is_protected' => false,
                ])->save();
                $company->delete();
            });
    }

    public function down(): void
    {
        // Intentionally empty — restoring a dummy HO company is not needed.
    }
};
