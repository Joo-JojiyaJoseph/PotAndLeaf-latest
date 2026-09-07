<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,   // global permission catalog (incl. "*")
            CompanySeeder::class,      // the nursery companies (tenancy)
            UserSeeder::class,         // admin user + company access
            AdminRoleSeeder::class,    // global Administrator role, assigned to admin per company
            StandardRolesSeeder::class, // global branch roles: Manager, Cashier, etc.
            BranchUserSeeder::class,    // sample per-company logins (manager/cashier)
            LookupSeeder::class,
            LocationSeeder::class,
            SupplierSeeder::class,
            ProductSeeder::class,
            CustomerSeeder::class,
            DemoSeeder::class,
            SupplementalDataSeeder::class,
        ]);
    }
}
