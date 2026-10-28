<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock count batch by batch: a count line may name the batch that was counted,
 * so the sheet shows "GRN-000001-01: system 50, counted 48" per batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stock_verification_items', 'product_batch_id')) {
            return;
        }
        Schema::table('stock_verification_items', function (Blueprint $table) {
            $table->uuid('product_batch_id')->nullable()->after('product_id');
            $table->string('batch_no', 60)->nullable()->after('product_batch_id');
            $table->index('product_batch_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('stock_verification_items', 'product_batch_id')) {
            return;
        }
        Schema::table('stock_verification_items', function (Blueprint $table) {
            $table->dropIndex(['product_batch_id']);
            $table->dropColumn(['product_batch_id', 'batch_no']);
        });
    }
};
