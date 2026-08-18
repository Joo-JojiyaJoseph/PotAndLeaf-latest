<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a stock movement reference the batch it belongs to. An inbound (purchase)
 * movement is always exactly one batch, so this is populated at purchase
 * confirmation. Outbound movements stay null in Phase 1 (aggregate costing).
 *
 * Kept as a plain indexed UUID rather than a hard foreign key: it avoids any
 * cross-table charset/collation mismatch on MySQL (errno 150) and the link is
 * enforced at the application layer via the models.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stock_ledger_entries', 'product_batch_id')) {
            return;
        }

        Schema::table('stock_ledger_entries', function (Blueprint $table) {
            $table->uuid('product_batch_id')->nullable()->after('product_id');
            $table->index('product_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['product_batch_id']);
            $table->dropColumn('product_batch_id');
        });
    }
};
