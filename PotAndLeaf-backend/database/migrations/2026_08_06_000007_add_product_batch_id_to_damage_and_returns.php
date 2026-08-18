<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-level damage and purchase returns. Damage records the scanned batch;
 * a purchase return derives its batch from the purchase line it returns. Both
 * decrement that batch's remaining_qty so the same units can't be consumed
 * twice across sale / damage / return.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('damage_entries', 'product_batch_id')) {
            Schema::table('damage_entries', function (Blueprint $table) {
                $table->uuid('product_batch_id')->nullable()->after('product_id');
                $table->index('product_batch_id');
            });
        }
        if (! Schema::hasColumn('purchase_return_items', 'product_batch_id')) {
            Schema::table('purchase_return_items', function (Blueprint $table) {
                $table->uuid('product_batch_id')->nullable()->after('product_id');
                $table->index('product_batch_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('damage_entries', function (Blueprint $table) {
            $table->dropIndex(['product_batch_id']);
            $table->dropColumn('product_batch_id');
        });
        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->dropIndex(['product_batch_id']);
            $table->dropColumn('product_batch_id');
        });
    }
};
