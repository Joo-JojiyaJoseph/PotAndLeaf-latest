<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts collected from customers. Each receipt reduces the customer's
 * outstanding; when allocated to a sale it counts toward that invoice's balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete();

            $table->string('receipt_no');
            $table->date('receipt_date');
            $table->decimal('amount', 14, 2);
            $table->string('mode')->default('cash'); // cash | bank | upi | cheque | card
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'receipt_no']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipts');
    }
};
