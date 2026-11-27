<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase bill: agent code, a discount on the final amount, and the selling prices
 * (MRP / retail / wholesale / dealer) to put on each product when the bill is confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'agent_code')) {
                $table->string('agent_code', 60)->nullable()->index();
            }
            if (! Schema::hasColumn('purchases', 'final_discount')) {
                $table->decimal('final_discount', 12, 2)->default(0);
            }
        });
        Schema::table('purchase_items', function (Blueprint $table) {
            foreach (['mrp', 'retail_price', 'wholesale_price', 'dealer_price'] as $col) {
                if (! Schema::hasColumn('purchase_items', $col)) {
                    $table->decimal($col, 12, 2)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            foreach (['agent_code', 'final_discount'] as $col) {
                if (Schema::hasColumn('purchases', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('purchase_items', function (Blueprint $table) {
            foreach (['mrp', 'retail_price', 'wholesale_price', 'dealer_price'] as $col) {
                if (Schema::hasColumn('purchase_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
