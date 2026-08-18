<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@potandleaf.test'],
            ['name' => 'Pot & Leaf Admin', 'password' => Hash::make('password'), 'is_super_admin' => true, 'is_active' => true],
        );

        // Give the admin access to every company; default to the HO.
        $companies = Company::orderBy('id')->get();
        foreach ($companies as $i => $company) {
            $admin->companies()->syncWithoutDetaching([
                $company->id => ['is_default' => $i === 0],
            ]);
        }
    }
}
