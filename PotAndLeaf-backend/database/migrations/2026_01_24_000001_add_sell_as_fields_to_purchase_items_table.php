<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            // Bulk lines only: how the received stock should be made sellable.
            $table->boolean('is_bulk')->default(false)->after('product_id');
            $table->string('sell_as')->nullable()->after('is_bulk'); // set_only | split_only | both
            $table->decimal('units_per_set', 14, 3)->nullable()->after('sell_as');

            // Target products for the split (unit) and set SKUs. set_product_id is
            // nullable — when null, the purchased product itself is the set.
            $table->uuid('split_product_id')->nullable()->after('units_per_set');
            $table->uuid('set_product_id')->nullable()->after('split_product_id');
            $table->uuid('shared_pool_group')->nullable()->after('set_product_id');

            $table->foreign('split_product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('set_product_id')->references('id')->on('products')->nullOnDelete();
            $table->index('shared_pool_group');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropForeign(['split_product_id']);
            $table->dropForeign(['set_product_id']);
            $table->dropIndex(['shared_pool_group']);
            $table->dropColumn([
                'is_bulk', 'sell_as', 'units_per_set',
                'split_product_id', 'set_product_id', 'shared_pool_group',
            ]);
        });
    }
};
