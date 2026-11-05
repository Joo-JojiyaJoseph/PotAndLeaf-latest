<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer "Owes you" fixes (update 57).
 *  1. A sales return remembers how much it took off "Owes you" and how much it
 *     added to the customer's advance, so cancelling it undoes exactly that.
 *  2. Customers whose "Owes you" went below zero (money received on account
 *     beyond what they owed) get that amount moved to their advance — it is
 *     money they have with you — and "Owes you" set to 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_returns', 'owes_reduced')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->decimal('owes_reduced', 14, 2)->nullable()->after('grand_total');
                $table->decimal('advance_credited', 14, 2)->nullable()->after('owes_reduced');
            });
        }

        DB::table('customers')->where('outstanding', '<', 0)->orderBy('id')->each(function ($c) {
            DB::table('customers')->where('id', $c->id)->update([
                'advance_balance' => round((float) $c->advance_balance + abs((float) $c->outstanding), 2),
                'outstanding'     => 0,
                'updated_at'      => now(),
            ]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_returns', 'owes_reduced')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->dropColumn(['owes_reduced', 'advance_credited']);
            });
        }
    }
};
