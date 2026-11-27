<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money for returned goods: credited to the customer's / supplier's advance, or really paid back.
 * A refund record is money that actually moved (cash, UPI, card, bank, cheque) and appears in the
 * Cash Book / Bank Book and the customer / supplier statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_refunds')) {
            Schema::create('customer_refunds', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('company_id')->index();
                $table->uuid('customer_id')->nullable()->index();
                $table->uuid('sales_return_id')->nullable()->index();
                $table->string('refund_no', 30);
                $table->date('refund_date');
                $table->decimal('amount', 12, 2);
                $table->string('mode', 20)->default('cash');
                $table->string('reference', 100)->nullable();
                $table->string('notes', 500)->nullable();
                $table->boolean('from_advance')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'refund_no']);
            });
        }

        if (! Schema::hasTable('supplier_refunds')) {
            Schema::create('supplier_refunds', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('company_id')->index();
                $table->uuid('supplier_id')->nullable()->index();
                $table->uuid('purchase_return_id')->nullable()->index();
                $table->string('refund_no', 30);
                $table->date('refund_date');
                $table->decimal('amount', 12, 2);
                $table->string('mode', 20)->default('cash');
                $table->string('reference', 100)->nullable();
                $table->string('notes', 500)->nullable();
                $table->boolean('from_advance')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'refund_no']);
            });
        }

        if (! Schema::hasColumn('sales_returns', 'refund_mode')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->string('refund_mode', 20)->default('advance');
                $table->decimal('advance_credit', 12, 2)->default(0);
                $table->decimal('advance_refunded', 12, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('purchase_returns', 'refund_mode')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->string('refund_mode', 20)->default('advance');
                $table->decimal('advance_refunded', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_refunds');
        Schema::dropIfExists('supplier_refunds');
        if (Schema::hasColumn('sales_returns', 'refund_mode')) {
            Schema::table('sales_returns', fn (Blueprint $t) => $t->dropColumn(['refund_mode', 'advance_credit', 'advance_refunded']));
        }
        if (Schema::hasColumn('purchase_returns', 'refund_mode')) {
            Schema::table('purchase_returns', fn (Blueprint $t) => $t->dropColumn(['refund_mode', 'advance_refunded']));
        }
    }
};
