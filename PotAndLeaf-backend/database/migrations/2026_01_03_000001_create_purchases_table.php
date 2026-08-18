<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained()->nullOnDelete();

            $table->string('purchase_no');
            $table->string('invoice_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('purchase_date');

            // Drives the tax split: inter-state => IGST, intra-state => CGST + SGST.
            $table->boolean('is_interstate')->default(false);

            $table->decimal('subtotal', 14, 2)->default(0);        // net taxable
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('landed_cost_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);      // payable to supplier

            $table->string('status')->default('draft');            // draft | confirmed | cancelled
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'purchase_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
