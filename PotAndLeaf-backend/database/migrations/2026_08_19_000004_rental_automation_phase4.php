<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->boolean('auto_bill')->default(true)->after('billing_cycle');
            $table->date('last_billed_to')->nullable()->after('auto_bill');
            $table->date('next_bill_at')->nullable()->after('last_billed_to');
        });

        Schema::table('rental_invoices', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('amount');
            $table->timestamp('sent_at')->nullable()->after('due_date');
            $table->unsignedSmallInteger('reminder_count')->default(0)->after('sent_at');
        });

        Schema::create('rental_notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rental_id')->nullable()->constrained('rentals')->nullOnDelete();
            $table->foreignUuid('rental_invoice_id')->nullable()->constrained('rental_invoices')->nullOnDelete();
            $table->string('channel')->default('whatsapp');
            $table->string('event');
            $table->string('recipient')->nullable();
            $table->string('status');
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'event', 'created_at']);
            $table->index(['rental_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_notification_logs');

        Schema::table('rental_invoices', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'sent_at', 'reminder_count']);
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['auto_bill', 'last_billed_to', 'next_bill_at']);
        });
    }
};
