<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eod_management_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('recipient', 255);
            $table->date('business_date');
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'business_date', 'status'], 'eod_mgmt_co_date_status_idx');
            $table->index(['company_id', 'channel', 'recipient', 'business_date'], 'eod_mgmt_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eod_management_logs');
    }
};
