<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the standard global roles (besides the protected Administrator role).
 */
class StandardRolesSeeder extends Seeder
{
    public function run(): void
    {
        $all = Permission::pluck('id', 'name');

        $roles = [
            'Manager'      => ['suppliers.', 'products.', 'purchases.', 'inventory.', 'damage.', 'purchase_returns.', 'sales_returns.', 'stock_verifications.', 'bulk_splits.', 'sales.', 'customers.', 'payments.', 'receipts.', 'commission.', 'transfers.', 'locations.', 'production.', 'rental.', 'reports.', 'activity.', 'backup.', 'po.', 'advance.', 'backorder.', 'categories.', 'brands.', 'units.', 'users.view', 'roles.view'],
            'Cashier'      => ['products.view', 'inventory.view', 'sales.view', 'sales.create', 'sales.confirm', 'sales_returns.view', 'sales_returns.create', 'sales_returns.confirm', 'customers.view', 'customers.create', 'receipts.view', 'receipts.create', 'backorder.view'],
            'Godown Staff' => ['inventory.', 'damage.', 'stock_verifications.', 'transfers.', 'locations.view', 'products.view', 'purchases.view'],
            'Supervisor'   => ['products.view', 'inventory.view', 'damage.view', 'damage.create', 'stock_verifications.view'],
            'Salesman'     => ['products.view', 'inventory.view', 'sales.view', 'sales.create', 'customers.view', 'customers.create', 'backorder.view', 'backorder.create', 'backorder.fulfill'],
        ];

        foreach ($roles as $name => $prefixes) {
            $role = Role::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_system' => false],
            );

            $ids = $all->filter(function ($id, $permName) use ($prefixes) {
                foreach ($prefixes as $p) {
                    if (str_ends_with($p, '.') ? str_starts_with($permName, $p) : $permName === $p) {
                        return true;
                    }
                }

                return false;
            })->values()->all();

            $role->permissions()->sync($ids);
        }
    }
}
