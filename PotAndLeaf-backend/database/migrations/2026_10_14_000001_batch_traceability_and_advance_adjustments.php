<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-level splits, damaged-stock returns, and per-advance adjustment
 * tracking. Existing balances are not rewritten; adjusted_amount is a
 * FIFO view of advance already consumed so remaining advance stays
 * consistent with suppliers.advance_balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bulk_splits', 'source_batch_id')) {
            Schema::table('bulk_splits', function (Blueprint $table) {
                $table->uuid('source_batch_id')->nullable()->after('source_product_id');
                $table->foreign('source_batch_id')->references('id')->on('product_batches')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('damage_entries', 'returned_qty')) {
            Schema::table('damage_entries', function (Blueprint $table) {
                $table->decimal('returned_qty', 14, 3)->default(0)->after('qty');
            });
        }

        if (! Schema::hasColumn('purchase_return_items', 'damage_entry_id')) {
            Schema::table('purchase_return_items', function (Blueprint $table) {
                $table->uuid('damage_entry_id')->nullable()->after('product_batch_id');
                $table->foreign('damage_entry_id')->references('id')->on('damage_entries')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('supplier_payments', 'adjusted_amount')) {
            Schema::table('supplier_payments', function (Blueprint $table) {
                $table->decimal('adjusted_amount', 14, 2)->default(0)->after('amount');
            });
        }

        $this->backfillAdvanceAdjustments();
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_return_items', 'damage_entry_id')) {
            Schema::table('purchase_return_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('damage_entry_id');
            });
        }
        if (Schema::hasColumn('damage_entries', 'returned_qty')) {
            Schema::table('damage_entries', function (Blueprint $table) {
                $table->dropColumn('returned_qty');
            });
        }
        if (Schema::hasColumn('bulk_splits', 'source_batch_id')) {
            Schema::table('bulk_splits', function (Blueprint $table) {
                $table->dropConstrainedForeignId('source_batch_id');
            });
        }
        if (Schema::hasColumn('supplier_payments', 'adjusted_amount')) {
            Schema::table('supplier_payments', function (Blueprint $table) {
                $table->dropColumn('adjusted_amount');
            });
        }
    }

    /**
     * Allocate already-consumed advance (sum of advance payments minus the
     * supplier's current advance balance) onto the oldest advance rows.
     * Payment amounts and supplier balances are left unchanged.
     */
    private function backfillAdvanceAdjustments(): void
    {
        if (! Schema::hasColumn('supplier_payments', 'adjusted_amount')) {
            return;
        }

        $suppliers = DB::table('suppliers')->select('id', 'advance_balance')->get();
        foreach ($suppliers as $supplier) {
            $advances = DB::table('supplier_payments')
                ->where('supplier_id', $supplier->id)
                ->where('is_advance', true)
                ->whereNull('deleted_at')
                ->orderBy('payment_date')
                ->orderBy('created_at')
                ->get(['id', 'amount']);

            if ($advances->isEmpty()) {
                continue;
            }

            $consumed = round((float) $advances->sum('amount') - (float) $supplier->advance_balance, 2);
            if ($consumed < 0) {
                $consumed = 0;
            }

            foreach ($advances as $advance) {
                $take = round(min((float) $advance->amount, max(0, $consumed)), 2);
                DB::table('supplier_payments')->where('id', $advance->id)->update(['adjusted_amount' => $take]);
                $consumed = round($consumed - $take, 2);
            }
        }
    }
};
