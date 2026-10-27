<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a purchase return's credit went to the supplier's advance
 * (the part beyond what was owed), so the supplier page can show where each
 * rupee of advance came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_returns', 'advance_credit')) {
            Schema::table('purchase_returns', fn (Blueprint $t) => $t->decimal('advance_credit', 14, 2)->default(0));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_returns', 'advance_credit')) {
            Schema::table('purchase_returns', fn (Blueprint $t) => $t->dropColumn('advance_credit'));
        }
    }
};
