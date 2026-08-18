<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A production run. Completing it consumes the BOM's input products from stock
 * and produces the output product at a unit cost derived from the inputs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bom_id')->nullable()->constrained('boms')->nullOnDelete();
            $table->foreignUuid('output_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->string('order_no');
            $table->date('order_date');
            $table->decimal('output_quantity', 16, 3);
            $table->decimal('total_input_cost', 14, 2)->default(0);
            $table->decimal('output_unit_cost', 14, 4)->default(0);
            $table->string('status')->default('draft'); // draft | completed | cancelled
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'order_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
