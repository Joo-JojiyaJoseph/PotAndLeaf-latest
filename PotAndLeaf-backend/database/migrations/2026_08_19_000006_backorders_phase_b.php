<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shortage orders when stock is unavailable. Partial fulfillment supported. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backorders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->string('order_no');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status')->default('open'); // open | partial | fulfilled | cancelled
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'order_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('backorder_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('backorder_id')->constrained('backorders')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->string('product_name');
            $table->decimal('ordered_qty', 16, 3);
            $table->decimal('fulfilled_qty', 16, 3)->default(0);
            $table->decimal('cancelled_qty', 16, 3)->default(0);
            $table->decimal('rate', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backorder_items');
        Schema::dropIfExists('backorders');
    }
};
