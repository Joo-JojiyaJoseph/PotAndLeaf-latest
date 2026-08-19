<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('user_id')->constrained('locations')->nullOnDelete();
            $table->date('effective_from')->nullable()->after('is_supervisor');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->decimal('max_commission', 14, 2)->nullable()->after('effective_to');
            $table->json('eligible_bill_kinds')->nullable()->after('max_commission');
        });

        Schema::table('commission_tiers', function (Blueprint $table) {
            $table->foreignUuid('product_id')->nullable()->after('commission_rule_id')->constrained('products')->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->after('product_id')->constrained('product_categories')->nullOnDelete();
        });

        Schema::table('manager_commission_rules', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'user_id']);
            $table->foreignId('location_id')->nullable()->after('user_id')->constrained('locations')->nullOnDelete();
            $table->unique(['company_id', 'user_id', 'location_id'], 'manager_rule_branch_unique');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('loyalty_tier', 30)->nullable()->after('loyalty_points');
        });

        Schema::create('loyalty_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('rule_type', 20)->default('spend');
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('customer_tier', 30)->nullable();
            $table->decimal('earn_rupees', 14, 2)->default(100);
            $table->unsignedInteger('earn_points')->default(1);
            $table->unsignedInteger('bonus_points_per_unit')->default(0);
            $table->decimal('min_purchase', 14, 2)->default(0);
            $table->unsignedInteger('max_points_per_transaction')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'rule_type']);
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 40);
            $table->string('name');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->json('rule_snapshot')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->dropColumn('rule_snapshot');
        });
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('loyalty_rules');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('loyalty_tier');
        });
        Schema::table('manager_commission_rules', function (Blueprint $table) {
            $table->dropUnique('manager_rule_branch_unique');
            $table->dropConstrainedForeignId('location_id');
            $table->unique(['company_id', 'user_id']);
        });
        Schema::table('commission_tiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['effective_from', 'effective_to', 'max_commission', 'eligible_bill_kinds']);
        });
    }
};
