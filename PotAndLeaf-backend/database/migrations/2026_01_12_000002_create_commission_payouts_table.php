<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('period', 7);           // YYYY-MM
            $table->decimal('sales_total', 14, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->string('mode')->default('cash'); // cash | bank | upi
            $table->date('payment_date')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('paid'); // draft | paid

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payouts');
    }
};
