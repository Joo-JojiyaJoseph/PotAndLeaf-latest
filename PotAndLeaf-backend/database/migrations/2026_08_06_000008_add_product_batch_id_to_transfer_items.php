<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-level transfers: each transfer line records the source batch scanned at
 * the sending shop, so dispatch decrements that batch and the receiving shop
 * can trace the stock back to its purchase/product/qty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stock_transfer_items', 'product_batch_id')) {
            return;
        }
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('product_batch_id')->nullable()->after('product_id');
            $table->index('product_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropIndex(['product_batch_id']);
            $table->dropColumn('product_batch_id');
        });
    }
};
