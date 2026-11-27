<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landscape work flow: estimates (with revisions), work order, daily labour & expense entries,
 * and staff commission on a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landscape_projects', function (Blueprint $t) {
            $t->string('work_order_no', 30)->nullable();
            $t->date('work_order_date')->nullable();
            $t->text('work_order_terms')->nullable();
            $t->uuid('approved_estimate_id')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('completion_note')->nullable();
        });

        Schema::create('landscape_estimates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_id')->constrained('landscape_projects')->cascadeOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->string('estimate_no', 40);
            $t->decimal('area_sqft', 12, 2)->default(0);
            $t->decimal('rate_per_sqft', 12, 2)->default(0);
            $t->decimal('planting_cost', 14, 2)->default(0);
            $t->decimal('service_charge', 14, 2)->default(0);
            $t->decimal('other_charge', 14, 2)->default(0);
            $t->decimal('gst_percent', 5, 2)->default(0);
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('gst_amount', 14, 2)->default(0);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->string('status', 15)->default('draft'); // draft | sent | approved | superseded
            $t->date('valid_until')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'revision']);
        });

        Schema::create('landscape_estimate_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('estimate_id')->constrained('landscape_estimates')->cascadeOnDelete();
            $t->string('kind', 12)->default('plant'); // plant | material | other
            $t->string('name', 160);
            $t->uuid('product_id')->nullable();
            $t->decimal('qty', 12, 3)->default(0);
            $t->string('unit', 20)->nullable();
            $t->decimal('rate', 14, 2)->default(0);
            $t->decimal('amount', 14, 2)->default(0);
            $t->unsignedInteger('sort')->default(0);
        });

        Schema::create('landscape_work_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_id')->constrained('landscape_projects')->cascadeOnDelete();
            $t->date('work_date');
            $t->string('type', 10); // labour | expense
            $t->string('category', 20)->nullable(); // expenses: plants | material | transport | other
            $t->string('name', 160);
            $t->decimal('qty', 12, 2)->default(1);   // labour: people / days
            $t->decimal('rate', 14, 2)->default(0);  // labour: wage each
            $t->decimal('amount', 14, 2)->default(0);
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'work_date']);
        });

        Schema::create('landscape_commissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_id')->constrained('landscape_projects')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('staff_name', 120);
            $t->string('role', 15)->default('worked'); // brought | worked | supervised
            $t->string('basis', 10)->default('profit'); // profit | value | fixed
            $t->decimal('percent', 6, 2)->nullable();
            $t->decimal('amount', 14, 2)->default(0);   // fixed amount, or frozen when paid
            $t->string('status', 10)->default('pending'); // pending | paid
            $t->timestamp('paid_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landscape_commissions');
        Schema::dropIfExists('landscape_work_entries');
        Schema::dropIfExists('landscape_estimate_items');
        Schema::dropIfExists('landscape_estimates');
        Schema::table('landscape_projects', function (Blueprint $t) {
            $t->dropColumn(['work_order_no', 'work_order_date', 'work_order_terms', 'approved_estimate_id', 'approved_at', 'completed_at', 'completion_note']);
        });
    }
};
