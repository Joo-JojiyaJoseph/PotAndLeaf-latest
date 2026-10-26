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
    /** Finance role permission prefixes (also used by the 2026_10_23 migration). */
    public const FINANCE = [
        'payments.', 'receipts.', 'accounts.', 'reports.', 'commission.view',
        'suppliers.view', 'customers.view', 'products.view', 'inventory.view',
        'purchases.view', 'purchase_returns.view', 'sales.view', 'sales.view_all', 'sales_returns.view', 'customers.view_all',
        'po.view', 'rental.view', 'loyalty.view', 'backup.view',
    ];

    public function run(): void
    {
        $all = Permission::pluck('id', 'name');

        $roles = [
            'Manager'      => ['suppliers.', 'products.', 'purchases.', 'inventory.', 'damage.', 'purchase_returns.', 'sales_returns.', 'stock_verifications.', 'bulk_splits.', 'sales.', 'customers.', 'payments.', 'receipts.', 'accounts.', 'commission.', 'loyalty.', 'whatsapp.', 'api.', 'transfers.', 'locations.', 'production.', 'rental.', 'reports.', 'activity.', 'backup.', 'po.', 'advance.', 'backorder.', 'categories.', 'subcategories.', 'brands.', 'units.', 'users.view', 'roles.view'],
            'Cashier'      => ['products.view', 'inventory.view', 'sales.view', 'sales.view_all', 'customers.view_all', 'sales.create', 'sales.confirm', 'sales_returns.view', 'sales_returns.create', 'sales_returns.confirm', 'customers.view', 'customers.create', 'loyalty.view', 'receipts.view', 'receipts.create', 'accounts.view', 'accounts.create', 'backorder.view'],
            // HO accounts team: money, books, GST and reports; can view (not change) stock and bills.
            'Finance'      => self::FINANCE,
            'Godown Staff' => ['inventory.', 'damage.', 'stock_verifications.', 'transfers.', 'locations.view', 'products.view', 'purchases.view'],
            'Supervisor'   => ['products.view', 'inventory.view', 'damage.view', 'damage.create', 'stock_verifications.view', 'commission.view_own', 'production.view'],
            'Salesman'     => ['products.view', 'inventory.view', 'sales.view', 'sales.create', 'customers.view', 'customers.create', 'backorder.view', 'backorder.create', 'backorder.fulfill', 'commission.view_own'],
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
