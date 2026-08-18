<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('customer_code');
            $table->string('name');
            $table->string('type')->default('retail');   // retail | wholesale | dealer
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('gst_number')->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode')->nullable();

            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('outstanding', 14, 2)->default(0);
            $table->integer('loyalty_points')->default(0);

            $table->text('notes')->nullable();
            $table->string('status')->default('active'); // active | inactive | blocked

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'customer_code']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
