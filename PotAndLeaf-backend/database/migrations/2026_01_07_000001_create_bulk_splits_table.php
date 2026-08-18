<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk splitting: convert N units of a bulk product into smaller sellable units,
 * redistributing the bulk's landed cost across the outputs. Confirming posts the
 * stock movements (source out, outputs in) and refreshes output cost prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_splits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('source_product_name');

            $table->string('split_no');
            $table->date('split_date');
            $table->decimal('source_qty', 14, 3);
            $table->decimal('source_unit_cost', 14, 4);
            $table->decimal('total_cost', 14, 2);

            $table->string('status')->default('draft'); // draft|confirmed|cancelled
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'split_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_splits');
    }
};
