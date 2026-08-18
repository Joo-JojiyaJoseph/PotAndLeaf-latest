<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales / POS. Confirming a sale posts stock out (COGS at product cost) and
 * updates the customer's outstanding (for credit sales) and loyalty points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name')->nullable(); // snapshot / walk-in

            $table->string('sale_no');
            $table->date('sale_date');
            $table->boolean('is_interstate')->default(false);
            $table->string('payment_mode')->default('cash'); // cash | card | upi | credit

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('round_off', 8, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);

            $table->string('status')->default('draft'); // draft | confirmed | cancelled
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sale_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
