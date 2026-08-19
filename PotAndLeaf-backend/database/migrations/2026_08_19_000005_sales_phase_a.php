<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('bill_kind')->default('tax_invoice')->after('payment_mode');
            $table->foreignUuid('converted_from_sale_id')->nullable()->after('bill_kind')
                ->constrained('sales')->nullOnDelete();

            $table->timestamp('cancel_requested_at')->nullable()->after('confirmed_at');
            $table->foreignId('cancel_requested_by')->nullable()->after('cancel_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancel_requested_by');
            $table->timestamp('cancel_reviewed_at')->nullable()->after('cancel_reason');
            $table->foreignId('cancel_reviewed_by')->nullable()->after('cancel_reviewed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancel_rejection_reason')->nullable()->after('cancel_reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_from_sale_id');
            $table->dropForeign(['cancel_requested_by']);
            $table->dropForeign(['cancel_reviewed_by']);
            $table->dropColumn([
                'bill_kind', 'cancel_requested_at', 'cancel_requested_by', 'cancel_reason',
                'cancel_reviewed_at', 'cancel_reviewed_by', 'cancel_rejection_reason',
            ]);
        });
    }
};
