<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landscape division: a branch can be marked as a landscaping division. Such a branch also handles
 * landscaping projects (site, service, area, quote, schedule, status).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'is_landscape')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->boolean('is_landscape')->default(false);
            });
        }

        if (! Schema::hasTable('landscape_projects')) {
            Schema::create('landscape_projects', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();
                $table->uuid('customer_id')->nullable()->index();
                $table->string('project_no', 30);
                $table->string('title', 160);
                $table->string('customer_name', 160)->nullable();
                $table->string('customer_phone', 30)->nullable();
                $table->string('service_type', 30)->default('design'); // design | installation | lawn | irrigation | maintenance | other
                $table->string('site_address', 255)->nullable();
                $table->decimal('site_area_sqft', 12, 2)->nullable();
                $table->date('visit_date')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->decimal('quoted_amount', 14, 2)->default(0);
                $table->decimal('advance_received', 14, 2)->default(0);
                $table->string('status', 20)->default('enquiry'); // enquiry | quoted | approved | in_progress | completed | cancelled
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'project_no']);
                $table->index(['company_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('landscape_projects');
        if (Schema::hasColumn('locations', 'is_landscape')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('is_landscape');
            });
        }
    }
};
