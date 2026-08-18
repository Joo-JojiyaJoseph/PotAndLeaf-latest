<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments made to suppliers. Each payment reduces the supplier's outstanding;
 * when allocated to a purchase it also counts toward that GRN's paid/balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUuid('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();

            $table->string('payment_no');
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('mode')->default('cash'); // cash | bank | upi | cheque
            $table->string('reference')->nullable();  // UTR / cheque no
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'payment_no']);
            $table->index(['company_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
