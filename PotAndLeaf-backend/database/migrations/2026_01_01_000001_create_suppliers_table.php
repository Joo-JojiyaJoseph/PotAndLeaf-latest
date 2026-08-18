<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers table.
 *
 * "Audit envelope" — the set of columns every ERP table repeats:
 *   - uuid primary key
 *   - company_id scope (the starter kit is team-scoped; rename to
 *     company_id / branch_id when you split those out)
 *   - created_by / updated_by / deleted_by
 *   - timestamps + soft deletes
 *   - status
 * Copy this block into every master/transaction migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Scope
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Identity
            $table->string('supplier_code');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Statutory (encrypt PAN/GST at the model layer)
            $table->text('gst_number')->nullable();
            $table->text('pan_number')->nullable();

            // Address
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->default('India');
            $table->string('pincode', 12)->nullable();

            // Banking
            $table->string('bank_name')->nullable();
            $table->text('bank_account_no')->nullable();
            $table->string('bank_ifsc')->nullable();

            // Commercials
            $table->unsignedInteger('credit_days')->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('outstanding', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->string('status')->default('active'); // active | inactive | blocked

            // Audit envelope
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // supplier_code is unique within a team (soft-deleted rows are
            // excluded by the application-layer validation rule).
            $table->unique(['company_id', 'supplier_code']);
            $table->index(['company_id', 'status']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
