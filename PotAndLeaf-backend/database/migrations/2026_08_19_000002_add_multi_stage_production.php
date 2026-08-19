<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['bom_id', 'sort_order']);
        });

        Schema::table('bom_items', function (Blueprint $table) {
            $table->foreignUuid('bom_stage_id')->nullable()->after('bom_id')->constrained('bom_stages')->cascadeOnDelete();
        });

        Schema::create('production_order_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignUuid('bom_stage_id')->nullable()->constrained('bom_stages')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->string('name');
            $table->string('status')->default('pending'); // pending | in_progress | completed
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('material_cost', 14, 2)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'sort_order']);
            $table->index(['production_order_id', 'status']);
        });

        Schema::table('production_order_items', function (Blueprint $table) {
            $table->foreignUuid('production_order_stage_id')->nullable()->after('production_order_id')
                ->constrained('production_order_stages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_order_stage_id');
        });

        Schema::dropIfExists('production_order_stages');

        Schema::table('bom_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bom_stage_id');
        });

        Schema::dropIfExists('bom_stages');
    }
};
