<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('production_orders', 'supervisor_id')) {
            Schema::table('production_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('supervisor_id')->nullable()->after('location_id');
                $table->foreign('supervisor_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('production_orders', 'commission_pending_qty')) {
            Schema::table('production_orders', function (Blueprint $table) {
                $table->decimal('commission_pending_qty', 14, 3)->default(0)->after('output_quantity');
            });
        }

        if (! Schema::hasColumn('commission_rules', 'rate_type')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->string('rate_type', 20)->default('percent')->after('user_id');
                $table->decimal('per_unit_amount', 12, 4)->default(0)->after('base_percent');
                $table->boolean('is_supervisor')->default(false)->after('is_active');
            });
        }

        if (! Schema::hasTable('supervisor_commission_entries')) {
            Schema::create('supervisor_commission_entries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('user_id');
                $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('production_order_id')->nullable()->constrained()->nullOnDelete();
                $table->string('trigger_event', 20);
                $table->string('reference_type', 40);
                $table->uuid('reference_id')->nullable();
                $table->decimal('qty', 14, 3);
                $table->decimal('unit_value', 14, 4)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->date('accrued_date');
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['company_id', 'user_id', 'accrued_date'], 'sce_company_user_date_idx');
                $table->index(['company_id', 'trigger_event'], 'sce_company_event_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_commission_entries');

        if (Schema::hasColumn('commission_rules', 'rate_type')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->dropColumn(['rate_type', 'per_unit_amount', 'is_supervisor']);
            });
        }

        if (Schema::hasColumn('production_orders', 'supervisor_id')) {
            Schema::table('production_orders', function (Blueprint $table) {
                $table->dropForeign(['supervisor_id']);
                $table->dropColumn(['supervisor_id']);
            });
        }

        if (Schema::hasColumn('production_orders', 'commission_pending_qty')) {
            Schema::table('production_orders', function (Blueprint $table) {
                $table->dropColumn(['commission_pending_qty']);
            });
        }
    }
};
