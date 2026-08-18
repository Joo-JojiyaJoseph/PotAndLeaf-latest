<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Plant rental agreement. Activating draws rented stock out; returning brings it back. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rentals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->string('rental_no');
            $table->date('start_date');
            $table->date('expected_end_date')->nullable();
            $table->string('billing_cycle')->default('monthly'); // daily | weekly | monthly
            $table->decimal('deposit', 14, 2)->default(0);
            $table->string('status')->default('draft'); // draft | active | returned | cancelled
            $table->text('notes')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('returned_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'rental_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
