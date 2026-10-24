<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which batches a stock-count adjustment came off (or went into), so the
 * count sheet shows "B-0012: −2, B-0015: −1" instead of only a product total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_verification_items', function (Blueprint $table) {
            $table->json('batch_adjustments')->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_verification_items', function (Blueprint $table) {
            $table->dropColumn('batch_adjustments');
        });
    }
};
