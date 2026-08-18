<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Periodic rental billing. Generating one raises the customer's outstanding. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rental_id')->constrained('rentals')->cascadeOnDelete();

            $table->string('invoice_no');
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('cycles', 10, 2)->default(1);
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('unpaid'); // unpaid | paid
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_no']);
            $table->index(['company_id', 'rental_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_invoices');
    }
};
