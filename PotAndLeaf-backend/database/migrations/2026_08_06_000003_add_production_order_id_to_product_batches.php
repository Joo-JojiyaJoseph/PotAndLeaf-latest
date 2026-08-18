<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a batch originate from a production order (finished-goods barcode), not
 * only a purchase. Plain indexed UUID — related at the application layer — to
 * stay consistent with the purchase link and avoid cross-table FK surprises.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_batches') || Schema::hasColumn('product_batches', 'production_order_id')) {
            return;
        }

        Schema::table('product_batches', function (Blueprint $table) {
            $table->uuid('production_order_id')->nullable()->after('purchase_item_id');
            $table->index('production_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropIndex(['production_order_id']);
            $table->dropColumn('production_order_id');
        });
    }
};
