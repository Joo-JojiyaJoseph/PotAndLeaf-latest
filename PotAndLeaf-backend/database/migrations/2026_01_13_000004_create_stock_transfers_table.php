<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock transfer between two locations. Dispatch removes from the source (stock
 * becomes in-transit); receive adds the accepted quantity to the destination and
 * returns any rejected quantity to the source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignUuid('to_location_id')->constrained('locations')->cascadeOnDelete();

            $table->string('transfer_no');
            $table->date('transfer_date');
            $table->string('status')->default('draft'); // draft | in_transit | received | cancelled
            $table->text('notes')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'transfer_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
