<?php

namespace App\Support\Deletion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Deleting is permanent (there is no recycle bin), and some database links
 * would delete linked history with the record. So a record that is used
 * anywhere cannot be deleted — the person is told where it is used and asked
 * to make it Inactive instead.
 */
final class InUseGuard
{
    /** @var array<string, list<string>>|null table => columns */
    private static ?array $columns = null;

    /**
     * @param  string        $label        e.g. 'customer "Anu Traders"'
     * @param  string        $ownTable     the record's own table (links inside it are ignored, except $selfColumns)
     * @param  string        $id           the record's id
     * @param  callable      $isLink       fn (string $column): bool — which column names point at this kind of record
     * @param  list<string>  $ignoreTables link tables that may simply be cleared with the record
     * @param  list<string>  $selfColumns  columns in the own table that also count (e.g. parent_id)
     */
    public static function assertUnused(string $label, string $ownTable, string $id, callable $isLink, array $ignoreTables = [], array $selfColumns = []): void
    {
        $uses = [];
        foreach (self::columns() as $table => $cols) {
            if (in_array($table, $ignoreTables, true)) {
                continue;
            }
            $links = $table === $ownTable
                ? array_values(array_intersect($cols, $selfColumns))
                : array_values(array_filter($cols, $isLink));
            if (! $links) {
                continue;
            }
            $count = DB::table($table)->where(function ($q) use ($links, $id) {
                foreach ($links as $c) {
                    $q->orWhere($c, $id);
                }
            })->count();
            if ($count > 0) {
                $uses[] = $count.' '.self::nice($table, $count);
            }
        }

        if ($uses) {
            throw ValidationException::withMessages([
                'delete' => "Cannot delete {$label}: it is used in ".implode(', ', array_slice($uses, 0, 6))
                    .(count($uses) > 6 ? ' and more' : '').'. Make it Inactive instead.',
            ]);
        }
    }

    /** @return array<string, list<string>> */
    private static function columns(): array
    {
        if (self::$columns === null) {
            self::$columns = [];
            foreach (Schema::getTables() as $t) {
                $name = $t['name'];
                if (in_array($name, ['migrations', 'jobs', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'job_batches', 'password_reset_tokens', 'personal_access_tokens', 'activity_logs'], true)) {
                    continue;
                }
                self::$columns[$name] = Schema::getColumnListing($name);
            }
        }

        return self::$columns;
    }

    private static function nice(string $table, int $count): string
    {
        $plain = [
            'loyalty_ledger' => ['points entry', 'points entries'], 'stock_ledger_entries' => ['stock movement', 'stock movements'],
            'customer_receipts' => ['receipt', 'receipts'], 'supplier_payments' => ['supplier payment', 'supplier payments'],
            'sale_items' => ['sale line', 'sale lines'], 'purchase_items' => ['purchase line', 'purchase lines'],
            'product_batches' => ['stock batch', 'stock batches'], 'location_stock' => ['stock record', 'stock records'],
            'role_user' => ['user', 'users'], 'accounting_transactions' => ['account entry', 'account entries'],
            'products' => ['product', 'products'], 'product_categories' => ['subcategory', 'subcategories'],
        ];
        if (isset($plain[$table])) {
            return $plain[$table][$count === 1 ? 0 : 1];
        }
        $words = str_replace('_', ' ', $table);

        return $count === 1 ? Str::singular($words) : $words;
    }

    /** For tests: forget the cached table list. */
    public static function flush(): void
    {
        self::$columns = null;
    }
}
