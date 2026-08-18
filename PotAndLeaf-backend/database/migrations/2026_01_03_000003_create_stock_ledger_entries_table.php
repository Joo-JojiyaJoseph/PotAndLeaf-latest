<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only stock movement ledger. Every in/out writes one row with the
 * running balance after the movement, so stock is auditable and reconstructable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();

            $table->string('direction');            // in | out
            $table->decimal('qty', 14, 3);          // always positive
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('balance_after', 14, 3);

            $table->string('reference_type')->nullable();  // purchase, adjustment, ...
            $table->uuid('reference_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('occurred_at');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'product_id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger_entries');
    }
};
