<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_items', 'price_level')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->string('price_level', 20)->default('retail')->after('rate');
            });
        }

        if (! Schema::hasColumn('bulk_split_items', 'suggested_retail')) {
            Schema::table('bulk_split_items', function (Blueprint $table) {
                $table->decimal('suggested_retail', 14, 2)->nullable()->after('unit_cost');
                $table->decimal('retail_price', 14, 2)->nullable()->after('suggested_retail');
            });
        }

        if (! Schema::hasColumn('bulk_splits', 'source_purchase_id')) {
            Schema::table('bulk_splits', function (Blueprint $table) {
                $table->foreignUuid('source_purchase_id')->nullable()->after('source_product_id')
                    ->constrained('purchases')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('bulk_split_units')) {
            Schema::create('bulk_split_units', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('bulk_split_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('bulk_split_item_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
                $table->string('barcode')->unique();
                $table->unsignedSmallInteger('unit_no')->default(1);
                $table->timestamps();

                $table->index(['bulk_split_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_split_units');

        if (Schema::hasColumn('bulk_splits', 'source_purchase_id')) {
            Schema::table('bulk_splits', function (Blueprint $table) {
                $table->dropConstrainedForeignId('source_purchase_id');
            });
        }

        if (Schema::hasColumn('bulk_split_items', 'suggested_retail')) {
            Schema::table('bulk_split_items', function (Blueprint $table) {
                $table->dropColumn(['suggested_retail', 'retail_price']);
            });
        }

        if (Schema::hasColumn('sale_items', 'price_level')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropColumn('price_level');
            });
        }
    }
};
