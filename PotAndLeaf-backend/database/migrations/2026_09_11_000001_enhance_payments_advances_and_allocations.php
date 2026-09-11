<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier advance balance, advance/allocation flags on existing payment
 * and receipt rows, and optional multi-invoice allocation lines.
 *
 * Cash movement stays on the parent payment/receipt. Allocation rows and
 * applied_from_advance rows do not create a second cash/bank entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suppliers') && ! Schema::hasColumn('suppliers', 'advance_balance')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->decimal('advance_balance', 14, 2)->default(0)->after('outstanding');
            });
        }

        if (Schema::hasTable('customer_receipts')) {
            Schema::table('customer_receipts', function (Blueprint $table) {
                if (! Schema::hasColumn('customer_receipts', 'is_advance')) {
                    $table->boolean('is_advance')->default(false)->after('notes');
                }
                if (! Schema::hasColumn('customer_receipts', 'applied_from_advance')) {
                    $table->boolean('applied_from_advance')->default(false)->after('is_advance');
                }
            });
        }

        if (Schema::hasTable('supplier_payments')) {
            Schema::table('supplier_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('supplier_payments', 'is_advance')) {
                    $table->boolean('is_advance')->default(false)->after('notes');
                }
                if (! Schema::hasColumn('supplier_payments', 'applied_from_advance')) {
                    $table->boolean('applied_from_advance')->default(false)->after('is_advance');
                }
            });
        }

        if (! Schema::hasTable('customer_receipt_allocations')) {
            Schema::create('customer_receipt_allocations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('customer_receipt_id')->constrained('customer_receipts')->cascadeOnDelete();
                $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
                $table->decimal('amount', 14, 2);
                $table->timestamps();
                $table->index(['sale_id']);
            });
        }

        if (! Schema::hasTable('supplier_payment_allocations')) {
            Schema::create('supplier_payment_allocations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
                $table->foreignUuid('purchase_id')->constrained('purchases')->cascadeOnDelete();
                $table->decimal('amount', 14, 2);
                $table->timestamps();
                $table->index(['purchase_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipt_allocations');
        Schema::dropIfExists('supplier_payment_allocations');

        if (Schema::hasColumn('customer_receipts', 'applied_from_advance')) {
            Schema::table('customer_receipts', function (Blueprint $table) {
                $table->dropColumn(['is_advance', 'applied_from_advance']);
            });
        }

        if (Schema::hasColumn('supplier_payments', 'applied_from_advance')) {
            Schema::table('supplier_payments', function (Blueprint $table) {
                $table->dropColumn(['is_advance', 'applied_from_advance']);
            });
        }

        if (Schema::hasColumn('suppliers', 'advance_balance')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropColumn('advance_balance');
            });
        }
    }
};
