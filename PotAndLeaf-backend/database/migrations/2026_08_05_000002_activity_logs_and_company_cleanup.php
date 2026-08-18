<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('module', 60);
            $table->string('entity_type', 60)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'module']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_protected')->default(false)->after('is_active');
        });

        if (Schema::hasColumn('companies', 'username')) {
            // SQLite cannot drop a column while its unique index still references it.
            try {
                Schema::table('companies', function (Blueprint $table) {
                    $table->dropUnique(['username']);
                });
            } catch (\Throwable) {
                // Index name may differ by driver; fall through to dropColumn.
            }

            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn(['username', 'password']);
            });
        }

        \DB::table('companies')->where('code', 'CHK-HO')->update(['is_protected' => true]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('is_protected');
            $table->string('username')->nullable();
            $table->string('password')->nullable();
        });

        Schema::dropIfExists('activity_logs');
    }
};
