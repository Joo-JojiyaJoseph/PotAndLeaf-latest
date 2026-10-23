<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The amount of a purchase that is actually owed to the supplier, fixed at
 * confirm time. It equals grand_total, or grand_total minus landed cost when
 * the company setting landed_cost_payable_to_supplier = 0 (freight paid to a
 * separate transporter). NULL on older purchases means "grand_total", which
 * is what was added to the supplier's outstanding before this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('supplier_payable', 14, 2)->nullable()->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('supplier_payable');
        });
    }
};
