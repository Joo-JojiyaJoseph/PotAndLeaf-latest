<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical stock verification (stock count) with an HO approval workflow:
 * draft → submitted → approved / rejected. Approval posts the variance to the
 * stock ledger so the system balance matches the physical count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('count_no');
            $table->date('count_date');
            $table->string('location_note')->nullable();     // free text until per-location stock lands (M3)
            $table->string('status')->default('draft');       // draft|submitted|approved|rejected|cancelled
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'count_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_verifications');
    }
};
