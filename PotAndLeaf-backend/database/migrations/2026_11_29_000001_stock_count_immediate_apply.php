<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stock count now changes the stock at once (no approval first) and is then checked later:
 *   draft -> applied (stock updated, waiting for head office / admin to check) -> verified.
 * Older counts keep their old statuses (submitted / approved / rejected).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_verifications', 'applied_at')) {
            Schema::table('stock_verifications', function (Blueprint $table) {
                $table->uuid('location_id')->nullable()->index();
                $table->timestamp('applied_at')->nullable();
                $table->unsignedBigInteger('applied_by')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->boolean('is_quick')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_verifications', 'applied_at')) {
            Schema::table('stock_verifications', function (Blueprint $table) {
                $table->dropColumn(['location_id', 'applied_at', 'applied_by', 'verified_at', 'verified_by', 'is_quick']);
            });
        }
    }
};
