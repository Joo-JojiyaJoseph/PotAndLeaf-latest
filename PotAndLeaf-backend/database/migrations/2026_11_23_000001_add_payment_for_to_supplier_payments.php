<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Says what a supplier payment was made for: a purchase bill, the supplier's
 * outstanding (old balance / on account) or an advance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('supplier_payments', 'payment_for')) {
            return;
        }
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->string('payment_for', 20)->nullable()->after('mode');
        });

        // Old rows: work out the answer from what they already hold.
        DB::table('supplier_payments')->where('is_advance', true)->update(['payment_for' => 'advance']);
        DB::table('supplier_payments')->whereNull('payment_for')->whereNotNull('purchase_id')->update(['payment_for' => 'bill']);
        DB::table('supplier_payments')->whereNull('payment_for')
            ->whereIn('id', DB::table('supplier_payment_allocations')->where('auto_applied', false)->select('supplier_payment_id'))
            ->update(['payment_for' => 'bill']);
        DB::table('supplier_payments')->whereNull('payment_for')->update(['payment_for' => 'outstanding']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('supplier_payments', 'payment_for')) {
            return;
        }
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn('payment_for');
        });
    }
};
