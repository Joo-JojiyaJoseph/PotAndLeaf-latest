<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-level sales: each POS line records the exact batch (scanned barcode)
 * it was sold from, so confirming decrements that batch's remaining_qty and
 * the sale is traceable to its purchase lot. Plain indexed UUID (related at the
 * application layer) to avoid cross-table FK surprises on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sale_items', 'product_batch_id')) {
            return;
        }
        Schema::table('sale_items', function (Blueprint $table) {
            $table->uuid('product_batch_id')->nullable()->after('product_id');
            $table->index('product_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['product_batch_id']);
            $table->dropColumn('product_batch_id');
        });
    }
};
