<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval workflow for transfers: a branch-initiated transfer starts as
 * "requested" and HO approves (→ draft, dispatchable) or rejects it. These
 * columns record who approved/rejected and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_transfers', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('stock_transfers', 'rejection_reason')) {
                $table->string('rejection_reason', 500)->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'rejection_reason']);
        });
    }
};
