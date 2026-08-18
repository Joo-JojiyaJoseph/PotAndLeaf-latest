<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained()->nullOnDelete();

            $table->string('return_no');
            $table->date('return_date');
            $table->boolean('is_interstate')->default(false);
            $table->string('reason')->nullable();

            $table->decimal('subtotal', 14, 2)->default(0);    // returned taxable
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);  // debit-note value

            $table->string('status')->default('draft');         // draft | confirmed | cancelled
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'return_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
