<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BranchUserSeeder extends Seeder
{
    public function run(): void
    {
        $people = [
            ['name' => 'Branch Manager', 'role' => 'Manager', 'prefix' => 'manager'],
            ['name' => 'Shop Cashier',   'role' => 'Cashier', 'prefix' => 'cashier'],
        ];

        Company::all()->each(function (Company $company) use ($people) {
            $slug = Str::lower($company->code);
            foreach ($people as $p) {
                $user = User::firstOrCreate(
                    ['email' => "{$p['prefix']}.{$slug}@potandleaf.test"],
                    ['name' => "{$p['name']} ({$company->code})", 'password' => Hash::make('password'), 'is_active' => true],
                );
                $user->companies()->syncWithoutDetaching([$company->id => ['is_default' => true]]);

                $role = Role::where('slug', Str::slug($p['role']))->first();
                if ($role) {
                    DB::table('role_user')->updateOrInsert(
                        ['user_id' => $user->id, 'company_id' => $company->id],
                        ['role_id' => $role->id, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        });
    }
}
