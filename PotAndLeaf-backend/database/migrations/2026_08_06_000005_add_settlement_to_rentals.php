<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Return-settlement fields: at return, rental + damage + missing charges are
 * deducted from the security deposit and the balance refunded (or billed).
 * Missing/damaged units are tracked per line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            foreach ([
                'rental_charge', 'damage_charge', 'missing_charge', 'refund_amount', 'balance_due',
            ] as $col) {
                if (! Schema::hasColumn('rentals', $col)) {
                    $table->decimal($col, 14, 2)->default(0)->after('deposit');
                }
            }
            if (! Schema::hasColumn('rentals', 'return_date')) {
                $table->date('return_date')->nullable()->after('returned_at');
            }
            if (! Schema::hasColumn('rentals', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('return_date');
            }
        });

        Schema::table('rental_items', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_items', 'damaged_qty')) {
                $table->decimal('damaged_qty', 14, 3)->default(0)->after('returned_qty');
            }
            if (! Schema::hasColumn('rental_items', 'missing_qty')) {
                $table->decimal('missing_qty', 14, 3)->default(0)->after('damaged_qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['rental_charge', 'damage_charge', 'missing_charge', 'refund_amount', 'balance_due', 'return_date', 'settled_at']);
        });
        Schema::table('rental_items', function (Blueprint $table) {
            $table->dropColumn(['damaged_qty', 'missing_qty']);
        });
    }
};
