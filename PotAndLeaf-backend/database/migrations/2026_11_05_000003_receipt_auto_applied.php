<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Update 62 — money received "On account" now pays the outstanding from
 * before first, then the oldest unpaid bills. Such a receipt is marked
 * auto_applied, so voiding it puts the whole amount back on "Owes you".
 *
 * Earlier on-account receipts are spread the same way (from before first,
 * then oldest bills), so old bills stop showing as unpaid when the money was
 * in fact received. "Owes you" itself does not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_receipts', 'auto_applied')) {
            Schema::table('customer_receipts', function (Blueprint $table) {
                $table->boolean('auto_applied')->default(false)->after('is_advance');
            });
        }

        $service = app(\App\Services\ReceiptService::class);
        $byCustomer = \App\Models\CustomerReceipt::query()
            ->where('is_advance', false)->where('auto_applied', false)
            ->whereNull('sale_id')->whereNull('advance_order_id')
            ->whereDoesntHave('allocations')
            ->orderBy('receipt_date')->orderBy('created_at')
            ->get()->groupBy('customer_id');
        foreach ($byCustomer as $customerId => $receipts) {
            $customer = \App\Models\Customer::find($customerId);
            if (! $customer) {
                continue;
            }
            $openingLeft = max(0, (float) $customer->opening_balance);
            \Illuminate\Support\Facades\DB::transaction(function () use ($receipts, $service, &$openingLeft) {
                foreach ($receipts as $receipt) {
                    $toOpening = min((float) $receipt->amount, $openingLeft);
                    $openingLeft -= $toOpening;
                    $service->applyToOldestBills($receipt, round((float) $receipt->amount - $toOpening, 2));
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_receipts', 'auto_applied')) {
            Schema::table('customer_receipts', function (Blueprint $table) {
                $table->dropColumn('auto_applied');
            });
        }
    }
};
