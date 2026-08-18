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
            AdminRoleSeeder::class,    // Administrator role per company, assigned to admin
            StandardRolesSeeder::class, // branch roles: Manager, Cashier, Godown, Supervisor, Salesman
            BranchUserSeeder::class,    // sample per-company logins (manager/cashier)
            LookupSeeder::class,
            LocationSeeder::class,     // default godown + shop per company
            SupplierSeeder::class,     // sample suppliers per company
            ProductSeeder::class,      // sample products per company
            CustomerSeeder::class,
            DemoSeeder::class,         // live demo activity (purchases, sales, payments) — must run last
        ]);
    }
}
