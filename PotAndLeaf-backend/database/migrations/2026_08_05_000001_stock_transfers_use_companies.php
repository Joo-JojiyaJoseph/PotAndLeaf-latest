<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Inter-company stock transfers — destination company instead of locations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('to_company_id')->nullable()->after('company_id')->constrained('companies')->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->dropForeign(['from_location_id']);
                $table->dropForeign(['to_location_id']);
            });
        }

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->uuid('from_location_id')->nullable()->change();
            $table->uuid('to_location_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('to_company_id');
        });
    }
};
