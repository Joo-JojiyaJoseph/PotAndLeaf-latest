<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('transfer_type')->default('inter_company')->after('to_company_id');
            $table->foreignId('redirected_from_company_id')->nullable()->after('rejection_reason')->constrained('companies')->nullOnDelete();
            $table->timestamp('redirected_at')->nullable()->after('redirected_from_company_id');
            $table->foreignId('redirected_by')->nullable()->after('redirected_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->decimal('approved_qty', 16, 3)->nullable()->after('qty');
            $table->decimal('rejected_qty', 16, 3)->default(0)->after('approved_qty');
            $table->string('rejection_reason')->nullable()->after('rejected_qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn(['approved_qty', 'rejected_qty', 'rejection_reason']);
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('redirected_by');
            $table->dropConstrainedForeignId('redirected_from_company_id');
            $table->dropColumn(['transfer_type', 'redirected_at']);
        });
    }
};
