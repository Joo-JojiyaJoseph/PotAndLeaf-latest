<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_splits', function (Blueprint $table) {
            $table->string('split_mode', 32)->nullable()->after('source_qty');
            $table->decimal('split_param', 14, 3)->nullable()->after('split_mode');
            $table->decimal('split_total_qty', 14, 3)->nullable()->after('split_param');
        });

        Schema::table('bulk_split_items', function (Blueprint $table) {
            $table->string('split_label', 100)->nullable()->after('product_name');
            $table->unsignedSmallInteger('split_sequence')->nullable()->after('split_label');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bulk_split_items MODIFY product_id CHAR(36) NULL');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->uuid('parent_product_id')->nullable()->after('company_id');
            $table->uuid('bulk_split_id')->nullable()->after('parent_product_id');
            $table->unsignedSmallInteger('split_sequence')->nullable()->after('bulk_split_id');

            $table->foreign('parent_product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('bulk_split_id')->references('id')->on('bulk_splits')->nullOnDelete();
        });

        Schema::table('product_batches', function (Blueprint $table) {
            $table->uuid('bulk_split_id')->nullable()->after('production_order_id');
            $table->foreign('bulk_split_id')->references('id')->on('bulk_splits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropForeign(['bulk_split_id']);
            $table->dropColumn('bulk_split_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['parent_product_id']);
            $table->dropForeign(['bulk_split_id']);
            $table->dropColumn(['parent_product_id', 'bulk_split_id', 'split_sequence']);
        });

        Schema::table('bulk_split_items', function (Blueprint $table) {
            $table->dropColumn(['split_label', 'split_sequence']);
        });

        Schema::table('bulk_splits', function (Blueprint $table) {
            $table->dropColumn(['split_mode', 'split_param', 'split_total_qty']);
        });
    }
};
