<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-staff commission rule: base % of their sales, plus an optional
 *  monthly-target bonus. Commission is computed from confirmed sales they billed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->decimal('base_percent', 6, 3)->default(0);   // % of billed sales
            $table->decimal('monthly_target', 14, 2)->default(0);
            $table->decimal('target_bonus', 14, 2)->default(0);   // flat bonus if target met
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
