<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The landscape "Daily Work Statement" sheet: sites worked, cash paid, materials, receipts and the day's cash balance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landscape_daily_statements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $t->date('statement_date');
            $t->decimal('opening_balance', 14, 2)->default(0);
            $t->json('sites')->nullable();
            $t->json('other_receipts')->nullable();
            $t->json('other_payments')->nullable();
            $t->decimal('total_receipts', 14, 2)->default(0);
            $t->decimal('total_payments', 14, 2)->default(0);
            $t->decimal('closing_balance', 14, 2)->default(0);
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'location_id', 'statement_date'], 'lds_unique_day');
        });

        Schema::table('landscape_work_entries', function (Blueprint $t) {
            $t->uuid('statement_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('landscape_work_entries', function (Blueprint $t) {
            $t->dropColumn('statement_id');
        });
        Schema::dropIfExists('landscape_daily_statements');
    }
};
