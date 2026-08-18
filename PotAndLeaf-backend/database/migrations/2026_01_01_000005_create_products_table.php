<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Identity
            $table->string('sku');
            $table->string('name');
            $table->string('barcode')->nullable();
            $table->string('hsn_code')->nullable();
            $table->text('description')->nullable();

            // Classification (nullOnDelete keeps products if a lookup is removed)
            $table->uuid('category_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->uuid('unit_id')->nullable();
            $table->foreign('category_id')->references('id')->on('product_categories')->nullOnDelete();
            $table->foreign('brand_id')->references('id')->on('product_brands')->nullOnDelete();
            $table->foreign('unit_id')->references('id')->on('product_units')->nullOnDelete();

            // Tax + pricing
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('mrp', 15, 2)->default(0);
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->decimal('dealer_price', 15, 2)->default(0);
            $table->decimal('wholesale_price', 15, 2)->default(0);
            $table->decimal('retail_price', 15, 2)->default(0);

            // Stock
            $table->decimal('reorder_level', 15, 2)->default(0);
            $table->decimal('opening_stock', 15, 2)->default(0);
            $table->decimal('current_stock', 15, 2)->default(0);

            // Media (array of stored paths; primary is first)
            $table->json('images')->nullable();

            $table->string('status')->default('active'); // active | inactive | discontinued

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'status']);
            $table->index('barcode');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
