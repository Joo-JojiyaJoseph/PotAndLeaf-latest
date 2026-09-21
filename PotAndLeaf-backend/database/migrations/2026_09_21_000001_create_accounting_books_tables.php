<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('code', 20);
            $table->string('name');
            $table->string('account_type', 20); // asset | liability | income | expense | equity
            $table->string('system_key', 40)->nullable();
            $table->decimal('opening_balance', 16, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'system_key']);
            $table->index(['company_id', 'account_type']);
        });

        Schema::create('accounting_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('voucher_no');
            $table->date('voucher_date');
            $table->string('voucher_type', 40); // cash_receipt, cash_payment, bank_receipt, bank_payment, contra, journal
            $table->string('book', 20); // cash | bank | journal
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('narration')->nullable();
            $table->string('status', 20)->default('posted'); // posted | cancelled
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'voucher_no']);
            $table->index(['company_id', 'book', 'voucher_date']);
            $table->index(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('accounting_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('accounting_transaction_id')->constrained('accounting_transactions')->cascadeOnDelete();
            $table->foreignUuid('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->string('narration')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'ledger_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_entries');
        Schema::dropIfExists('accounting_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
