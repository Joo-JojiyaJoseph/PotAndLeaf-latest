<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opening-stock batches counted every raise of opening stock as new stock
 * "received", but a lowering only reduced what was left. Editing opening stock
 * 150 → 0 → 150 showed "150 of 300 received". Set each opening batch's
 * received figure back to the product's opening stock (never below what is
 * still left in the batch). Stock on hand is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('product_batches as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->where('b.batch_no', 'like', 'OPEN-%')
            ->get(['b.id', 'b.batch_no', 'b.qty', 'b.remaining_qty', 'p.sku', 'p.opening_stock']);

        foreach ($rows as $r) {
            if ($r->batch_no !== 'OPEN-'.$r->sku) {
                continue;
            }
            $fixed = round(max((float) $r->opening_stock, (float) $r->remaining_qty), 3);
            if ($fixed < (float) $r->qty - 0.0005) {
                DB::table('product_batches')->where('id', $r->id)->update(['qty' => $fixed]);
            }
        }
    }

    public function down(): void
    {
        // Data correction only.
    }
};
