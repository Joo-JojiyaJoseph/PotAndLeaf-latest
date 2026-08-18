<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Running loyalty ledger per customer — earn / redeem / reverse entries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // earn | redeem | reverse
            $table->integer('points');
            $table->integer('balance_after');
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger');
    }
};
