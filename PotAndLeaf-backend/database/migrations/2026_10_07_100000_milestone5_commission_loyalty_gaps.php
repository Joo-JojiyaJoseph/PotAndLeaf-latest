<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5 gaps: tier mode/period, branch targets, supervisor product rates,
 * ledger dimensions, loyalty expiry, and seasonal-care opt-in metadata.
 * Does not alter rows already accrued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->string('tier_period', 20)->default('monthly')->after('eligible_bill_kinds');
            $table->string('tier_mode', 20)->default('marginal')->after('tier_period');
            $table->string('target_scope', 20)->default('salesman')->after('tier_mode');
        });

        Schema::table('commission_daily_target_tiers', function (Blueprint $table) {
            $table->decimal('bonus_percent', 6, 3)->default(0)->after('bonus_amount');
        });

        Schema::table('commission_transactions', function (Blueprint $table) {
            $table->string('role', 30)->nullable()->after('user_id');
            $table->foreignUuid('branch_id')->nullable()->after('role')->constrained('locations')->nullOnDelete();
            $table->uuid('rule_id')->nullable()->after('branch_id');
            $table->string('period', 10)->nullable()->after('transaction_date');
            $table->index(['company_id', 'role', 'transaction_date'], 'commission_tx_role_date_idx');
        });

        Schema::table('supervisor_commission_entries', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('reversal_of_id');
            $table->unique('idempotency_key', 'sce_idempotency_unique');
        });

        Schema::create('supervisor_commission_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('rate_type', 20)->default('percent');
            $table->decimal('rate', 14, 4)->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'is_active'], 'sup_rate_co_user_idx');
        });

        Schema::table('loyalty_rules', function (Blueprint $table) {
            $table->decimal('points_multiplier', 6, 3)->default(1)->after('bonus_points_per_unit');
        });

        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->integer('remaining_points')->nullable()->after('points');
            $table->date('expires_at')->nullable()->after('rule_snapshot');
            $table->timestamp('expires_processed_at')->nullable()->after('expires_at');
            $table->index(['type', 'expires_at'], 'loyalty_ledger_expire_idx');
        });

        DB::table('loyalty_ledger')->where('type', 'earn')->whereNull('remaining_points')->update([
            'remaining_points' => DB::raw('points'),
        ]);

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(true)->after('loyalty_tier');
        });

        Schema::table('seasonal_care_rules', function (Blueprint $table) {
            $table->string('season', 20)->nullable()->after('category_id');
            $table->string('timing', 30)->default('days_after_purchase')->after('days_after_purchase');
        });

        Schema::table('seasonal_care_sends', function (Blueprint $table) {
            $table->foreignUuid('product_id')->nullable()->after('sale_id')->constrained('products')->nullOnDelete();
            $table->string('season', 20)->nullable()->after('product_id');
            $table->unsignedSmallInteger('season_year')->nullable()->after('season');
            $table->index(['customer_id', 'product_id', 'season', 'season_year'], 'seasonal_care_customer_season_idx');
        });

        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->dropColumn('next_retry_at');
        });
        Schema::table('seasonal_care_sends', function (Blueprint $table) {
            $table->dropIndex('seasonal_care_customer_season_idx');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['season', 'season_year']);
        });
        Schema::table('seasonal_care_rules', function (Blueprint $table) {
            $table->dropColumn(['season', 'timing']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('whatsapp_opt_in');
        });
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->dropIndex('loyalty_ledger_expire_idx');
            $table->dropColumn(['remaining_points', 'expires_at', 'expires_processed_at']);
        });
        Schema::table('loyalty_rules', function (Blueprint $table) {
            $table->dropColumn('points_multiplier');
        });
        Schema::dropIfExists('supervisor_commission_rates');
        Schema::table('supervisor_commission_entries', function (Blueprint $table) {
            $table->dropUnique('sce_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
        Schema::table('commission_transactions', function (Blueprint $table) {
            $table->dropIndex('commission_tx_role_date_idx');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['role', 'rule_id', 'period']);
        });
        Schema::table('commission_daily_target_tiers', function (Blueprint $table) {
            $table->dropColumn('bonus_percent');
        });
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropColumn(['tier_period', 'tier_mode', 'target_scope']);
        });
    }
};
