<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commission_rule_id')->constrained('commission_rules')->cascadeOnDelete();
            $table->decimal('min_amount', 14, 2)->default(0);
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->decimal('percent', 6, 3);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('commission_daily_target_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commission_rule_id')->constrained('commission_rules')->cascadeOnDelete();
            $table->decimal('min_amount', 14, 2);
            $table->decimal('bonus_amount', 14, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('commission_promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('min_qty', 14, 3)->default(1);
            $table->decimal('bonus_per_unit', 14, 2)->default(0);
            $table->decimal('bonus_fixed', 14, 2)->default(0);
            $table->decimal('bonus_percent', 6, 3)->default(0);
            $table->json('eligible_user_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('manager_commission_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('percent', 6, 3);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('commission_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('commission_type', 40);
            $table->string('source_type', 40);
            $table->string('source_id', 64);
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('calculation_base', 14, 2)->default(0);
            $table->decimal('rate_percent', 6, 3)->nullable();
            $table->decimal('fixed_bonus', 14, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->json('rule_snapshot')->nullable();
            $table->date('transaction_date');
            $table->string('status', 20)->default('accrued');
            $table->uuid('reversal_of_id')->nullable()->after('status');
            $table->timestamps();

            $table->unique(
                ['company_id', 'user_id', 'commission_type', 'source_type', 'source_id'],
                'commission_tx_dedupe',
            );
            $table->index(['company_id', 'user_id', 'transaction_date']);
        });

        Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_type', 20);
            $table->string('recipient_id', 64)->nullable();
            $table->string('recipient_phone', 30)->nullable();
            $table->string('message_type', 40);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->date('business_date')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'message_type', 'business_date']);
        });

        Schema::create('seasonal_care_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->unsignedSmallInteger('days_after_purchase')->default(15);
            $table->json('season_months')->nullable();
            $table->text('message_template');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('max_sends_per_customer')->default(1);
            $table->timestamps();
        });

        Schema::create('seasonal_care_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('seasonal_care_rule_id')->constrained('seasonal_care_rules')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['seasonal_care_rule_id', 'customer_id', 'sale_id'], 'seasonal_care_send_unique');
        });

        Schema::table('supervisor_commission_entries', function (Blueprint $table) {
            $table->string('status', 20)->default('accrued')->after('accrued_date');
            $table->uuid('reversal_of_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('supervisor_commission_entries', function (Blueprint $table) {
            $table->dropColumn(['status', 'reversal_of_id']);
        });
        Schema::dropIfExists('seasonal_care_sends');
        Schema::dropIfExists('seasonal_care_rules');
        Schema::dropIfExists('whatsapp_message_logs');
        Schema::dropIfExists('commission_transactions');
        Schema::dropIfExists('manager_commission_rules');
        Schema::dropIfExists('commission_promotions');
        Schema::dropIfExists('commission_daily_target_tiers');
        Schema::dropIfExists('commission_tiers');
    }
};
