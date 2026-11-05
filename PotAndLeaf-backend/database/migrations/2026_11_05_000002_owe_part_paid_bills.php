<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Update 60 — part-paid cash / card / UPI bills.
 *
 * Before, only a Credit bill added its unpaid part to the customer's
 * "Owes you". A cash/card/UPI bill where only part was paid showed "Partial"
 * but the customer owed ₹0. From now on confirming such a bill adds the unpaid
 * part. This repairs bills already confirmed: for each one that has had no
 * later payment against it (only the receipt made at the counter), the part
 * still unpaid (less goods returned) is added to the customer's "Owes you".
 * Bills that had later payments are left as they are (adjust by hand).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sales = DB::table('sales')
            ->where('status', 'confirmed')
            ->whereNotNull('customer_id')
            ->where('payment_mode', '!=', 'credit')
            ->whereRaw('(grand_total - COALESCE(loyalty_discount, 0) - COALESCE(amount_paid, 0)) > 0.005')
            ->get(['id', 'customer_id', 'grand_total', 'loyalty_discount', 'amount_paid']);

        $add = [];
        foreach ($sales as $s) {
            $receipts = DB::table('customer_receipts')->where('sale_id', $s->id)->count();
            $allocated = DB::table('customer_receipt_allocations')->where('sale_id', $s->id)->exists();
            if ($receipts > 1 || $allocated) {
                continue; // paid again later — that payment already changed "Owes you"
            }
            $returned = (float) DB::table('sales_returns')->where('sale_id', $s->id)->where('status', 'confirmed')->sum('grand_total');
            $unpaid = round((float) $s->grand_total - (float) $s->loyalty_discount - (float) $s->amount_paid - $returned, 2);
            if ($unpaid > 0.005) {
                $add[$s->customer_id] = ($add[$s->customer_id] ?? 0) + $unpaid;
            }
        }

        foreach ($add as $customerId => $amount) {
            $c = DB::table('customers')->where('id', $customerId)->first(['outstanding']);
            if ($c) {
                DB::table('customers')->where('id', $customerId)->update([
                    'outstanding' => round(max(0, (float) $c->outstanding) + $amount, 2),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data repair only.
    }
};
