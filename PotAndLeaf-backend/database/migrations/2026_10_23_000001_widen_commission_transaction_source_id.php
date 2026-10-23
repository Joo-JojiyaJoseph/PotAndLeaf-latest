<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * commission_transactions.source_id was 64 characters, but the engine writes
 * longer keys:
 *   promotion bonus   "{sale uuid}:{sale item uuid}:{promotion uuid}" = 110
 *   partial reversal  "{sales return uuid}:{transaction uuid}"        = 73
 * MySQL in strict mode rejects those inserts ("Data too long"), which made a
 * sale confirm or a sales return fail whenever a promotion or a commission
 * reversal was involved. 191 keeps the unique index within MySQL's limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_transactions', function (Blueprint $table) {
            $table->string('source_id', 191)->change();
        });
    }

    public function down(): void
    {
        Schema::table('commission_transactions', function (Blueprint $table) {
            $table->string('source_id', 64)->change();
        });
    }
};
