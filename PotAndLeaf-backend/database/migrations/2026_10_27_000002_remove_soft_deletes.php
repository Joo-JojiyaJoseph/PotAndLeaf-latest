<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Remove soft delete: deleting is now permanent.
 *
 * Rows that were already "deleted" (deleted_at set) are cleared up first:
 *  - bills and other documents are removed for good;
 *  - master records (customers, suppliers, products, categories, units,
 *    brands, roles, companies) are removed if nothing uses them. If they are
 *    still linked to bills or stock, they are kept and made Inactive, so no
 *    history is lost.
 * Then the deleted_at column is dropped from every table.
 */
return new class extends Migration
{
    /** Master table => [column that marks it inactive, inactive value, link-column test]. */
    private function masters(): array
    {
        return [
            'customers'          => ['status', 'inactive', fn ($c) => $c === 'customer_id'],
            'suppliers'          => ['status', 'inactive', fn ($c) => str_ends_with($c, 'supplier_id')],
            'products'           => ['status', 'inactive', fn ($c) => (bool) preg_match('/(^|_)product_id$/', $c)],
            'product_categories' => ['status', 'inactive', fn ($c) => in_array($c, ['category_id', 'subcategory_id'], true)],
            'product_units'      => ['status', 'inactive', fn ($c) => in_array($c, ['unit_id', 'base_unit_id'], true)],
            'product_brands'     => ['status', 'inactive', fn ($c) => $c === 'brand_id'],
            'roles'              => [null, null, fn ($c) => $c === 'role_id'],
            'companies'          => ['is_active', false, fn ($c) => $c === 'company_id'],
        ];
    }

    public function up(): void
    {
        $tables = collect(Schema::getTables())->pluck('name')
            ->filter(fn ($t) => Schema::hasColumn($t, 'deleted_at'))
            ->values();
        $columns = collect(Schema::getTables())->pluck('name')
            ->mapWithKeys(fn ($t) => [$t => Schema::getColumnListing($t)]);
        $masters = $this->masters();

        foreach ($tables as $table) {
            $deleted = DB::table($table)->whereNotNull('deleted_at')->pluck('id');
            foreach ($deleted as $id) {
                if (isset($masters[$table])) {
                    [$flag, $off, $isLink] = $masters[$table];
                    $used = false;
                    foreach ($columns as $other => $cols) {
                        $links = $other === $table
                            ? array_values(array_intersect($cols, $table === 'product_categories' ? ['parent_id'] : []))
                            : array_values(array_filter($cols, $isLink));
                        if ($other === 'permission_role' || ! $links) {
                            continue;
                        }
                        if (DB::table($other)->where(fn ($q) => collect($links)->each(fn ($c) => $q->orWhere($c, $id)))->exists()) {
                            $used = true;
                            break;
                        }
                    }
                    if ($used) {
                        $keep = ['deleted_at' => null];
                        if ($flag) {
                            $keep[$flag] = $off;
                        }
                        DB::table($table)->where('id', $id)->update($keep);
                        continue;
                    }
                }
                try {
                    DB::table($table)->where('id', $id)->delete();
                } catch (\Throwable $e) {
                    // Still linked somewhere: keep it rather than lose history.
                    Log::warning("remove_soft_deletes: kept {$table} {$id}: ".$e->getMessage());
                    DB::table($table)->where('id', $id)->update(['deleted_at' => null]);
                }
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('deleted_at');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'purchase_returns', 'purchases', 'sales', 'product_units', 'product_categories', 'roles', 'rentals',
            'supplier_payments', 'advance_orders', 'stock_verifications', 'accounting_transactions', 'commission_payouts',
            'purchase_orders', 'stock_transfers', 'products', 'customer_receipts', 'damage_entries', 'bulk_splits',
            'suppliers', 'backorders', 'customers', 'product_brands', 'companies', 'production_orders', 'sales_returns',
        ] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
            }
        }
    }
};
