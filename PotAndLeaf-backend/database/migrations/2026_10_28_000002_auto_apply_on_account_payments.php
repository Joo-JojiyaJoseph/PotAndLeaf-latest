<?php

use App\Models\Supplier;
use App\Services\PaymentService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money paid to a supplier "on account" (not against a bill) clears the
 * opening balance first, then the oldest bills. Those automatic links are
 * marked auto_applied so they can be worked out again at any time.
 * This also corrects suppliers whose bills still showed unpaid after such payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('supplier_payment_allocations', 'auto_applied')) {
            Schema::table('supplier_payment_allocations', function (Blueprint $table) {
                $table->boolean('auto_applied')->default(false)->after('amount');
            });
        }

        $service = app(PaymentService::class);
        Supplier::query()->orderBy('id')->each(fn (Supplier $s) => $service->settleOnAccount($s));
    }

    public function down(): void
    {
        if (Schema::hasColumn('supplier_payment_allocations', 'auto_applied')) {
            \Illuminate\Support\Facades\DB::table('supplier_payment_allocations')->where('auto_applied', true)->delete();
            Schema::table('supplier_payment_allocations', fn (Blueprint $t) => $t->dropColumn('auto_applied'));
        }
    }
};
