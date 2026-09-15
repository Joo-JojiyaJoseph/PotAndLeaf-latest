<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,    // permission catalog (needed for roles)
            CompanySeeder::class,
            UserSeeder::class,          // super-admin login
            AdminRoleSeeder::class,     // Administrator role
            StandardRolesSeeder::class, // Manager, Cashier, etc.
            // BranchUserSeeder::class,
            // LookupSeeder::class,
            // LocationSeeder::class,
            SupplierSeeder::class,
            // ProductSeeder::class,
            CustomerSeeder::class,
            // DemoSeeder::class,
            // SupplementalDataSeeder::class,
        ]);
    }
}
