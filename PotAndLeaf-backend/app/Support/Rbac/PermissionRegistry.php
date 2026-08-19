<?php

namespace App\Support\Rbac;

/**
 * The single source of truth for every permission in the app. Seed it into the
 * permissions table with PermissionSeeder; the Role matrix renders straight
 * from groups(). Add a line here when you add a capability — never hand-type
 * permission strings elsewhere.
 */
class PermissionRegistry
{
    /**
     * module => [ permission name => human label ]
     *
     * @return array<string, array<string,string>>
     */
    public static function groups(): array
    {
        $crud = fn (string $mod, string $label) => [
            "{$mod}.view"   => "View {$label}",
            "{$mod}.create" => "Create {$label}",
            "{$mod}.update" => "Edit {$label}",
            "{$mod}.delete" => "Delete {$label}",
        ];

        return [
            'System'     => ['*' => 'Full access (all modules)'],
            'Suppliers'  => $crud('suppliers', 'suppliers') + ['suppliers.force-delete' => 'Permanently delete suppliers'],
            'Products'   => $crud('products', 'products') + [
                'products.force-delete' => 'Permanently delete products',
                'products.view_cost'    => 'View product cost price (HO)',
            ],
            'Purchases'  => $crud('purchases', 'purchases') + ['purchases.confirm' => 'Confirm purchases'],
            'Purchase Returns' => [
                'purchase_returns.view'    => 'View purchase returns',
                'purchase_returns.create'  => 'Create purchase returns',
                'purchase_returns.confirm' => 'Confirm purchase returns',
                'purchase_returns.delete'  => 'Cancel purchase returns',
            ],
            'Inventory'  => [
                'inventory.view' => 'View inventory, ledger & reports',
                'damage.view'    => 'View damage entries',
                'damage.create'  => 'Record damage / write-off entries',
            ],
            'Advance Orders' => [
                'advance.view'    => 'View advance orders',
                'advance.create'  => 'Create advance orders',
                'advance.fulfill' => 'Fulfil advance orders',
                'advance.delete'  => 'Cancel advance orders',
            ],
            'Backorders' => [
                'backorder.view'    => 'View backorders',
                'backorder.create'  => 'Create backorders',
                'backorder.fulfill' => 'Fulfill backorders',
                'backorder.delete'  => 'Cancel backorders',
            ],
            'Purchase Orders' => [
                'po.view'    => 'View purchase orders',
                'po.create'  => 'Create purchase orders',
                'po.send'    => 'Mark POs as sent',
                'po.convert' => 'Convert POs to GRNs',
                'po.delete'  => 'Cancel purchase orders',
            ],
            'Reports' => [
                'reports.view'   => 'View reports & dashboard',
                'reports.margin' => 'View profit margin analysis (HO)',
                'reports.profit' => 'View approximate profit reports (HO)',
            ],
            'Activity Monitoring' => [
                'activity.view' => 'View HO activity monitoring dashboard',
            ],
            'Backups' => [
                'backup.view'    => 'View & download database backups',
                'backup.run'     => 'Run manual backups',
                'backup.restore' => 'Restore the database from a backup',
            ],
            'Plant Rental' => [
                'rental.view'     => 'View rentals',
                'rental.create'   => 'Create rentals',
                'rental.activate' => 'Activate rentals (issue stock)',
                'rental.return'   => 'Record rental returns',
                'rental.bill'     => 'Generate rental invoices',
                'rental.delete'   => 'Cancel rentals',
            ],
            'Production' => [
                'production.view'       => 'View production & BOMs',
                'production.manage_bom' => 'Manage bills of materials',
                'production.create'     => 'Create production orders',
                'production.complete'   => 'Complete production orders',
                'production.delete'     => 'Cancel production orders',
            ],
            'Stock Transfers' => [
                'transfers.view'     => 'View transfers',
                'transfers.create'   => 'Create transfers',
                'transfers.approve'  => 'Approve/reject transfer requests (HO)',
                'transfers.dispatch' => 'Dispatch transfers',
                'transfers.receive'  => 'Receive transfers',
                'transfers.delete'   => 'Cancel transfers',
            ],
            'Locations'  => [
                'locations.view'   => 'View locations',
                'locations.manage' => 'Manage locations',
            ],
            'Commission' => [
                'commission.view'   => 'View commission rules & payouts',
                'commission.manage' => 'Edit commission rules',
                'commission.pay'    => 'Record commission payouts',
            ],
            'Customer Receipts' => [
                'receipts.view'   => 'View customer receipts',
                'receipts.create' => 'Record customer receipts',
                'receipts.delete' => 'Void customer receipts',
            ],
            'Supplier Payments' => [
                'payments.view'   => 'View supplier payments',
                'payments.create' => 'Record supplier payments',
                'payments.delete' => 'Void supplier payments',
            ],
            'Sales'      => [
                'sales.view'           => 'View sales',
                'sales.create'         => 'Create sales',
                'sales.confirm'        => 'Confirm sales',
                'sales.delete'         => 'Cancel draft sales',
                'sales.cancel_request' => 'Request cancellation of confirmed sales',
                'sales.cancel_approve' => 'Approve/reject sale cancellation requests (HO)',
                'sales.whatsapp'       => 'Send sales invoices via WhatsApp',
            ],
            'Sales Returns' => [
                'sales_returns.view'    => 'View sales returns',
                'sales_returns.create'  => 'Create sales returns',
                'sales_returns.confirm' => 'Confirm sales returns',
                'sales_returns.delete'  => 'Cancel sales returns',
            ],
            'Customers'  => $crud('customers', 'customers'),
            'Supplier Payments' => [
                'payments.view'   => 'View supplier payments',
                'payments.create' => 'Record supplier payments',
                'payments.delete' => 'Delete supplier payments',
            ],
            'Bulk Splitting' => [
                'bulk_splits.view'    => 'View bulk splits',
                'bulk_splits.create'  => 'Create bulk splits',
                'bulk_splits.confirm' => 'Confirm bulk splits',
                'bulk_splits.delete'  => 'Cancel bulk splits',
            ],
            'Stock Verification' => [
                'stock_verifications.view'    => 'View stock counts',
                'stock_verifications.create'  => 'Create & submit stock counts',
                'stock_verifications.approve' => 'Approve or reject stock counts (HO)',
            ],
            'Categories' => $crud('categories', 'categories'),
            'Brands'     => $crud('brands', 'brands'),
            'Units'      => $crud('units', 'units'),
            'Roles'      => $crud('roles', 'roles'),
            'Users'      => [
                'users.view'   => 'View users',
                'users.create' => 'Create users',
                'users.update' => 'Edit users',
                'users.delete' => 'Remove users',
            ],
            'Settings' => [
                'settings.view'   => 'View company settings',
                'settings.update' => 'Update company settings',
            ],
        ];
    }

    /**
     * Flattened catalog for seeding.
     *
     * @return array<int, array{name:string, module:string, label:string}>
     */
    public static function flat(): array
    {
        $rows = [];
        foreach (self::groups() as $module => $permissions) {
            foreach ($permissions as $name => $label) {
                $rows[] = ['name' => $name, 'module' => $module, 'label' => $label];
            }
        }

        return $rows;
    }
}
