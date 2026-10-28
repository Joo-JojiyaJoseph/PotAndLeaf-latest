<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user can ask for extra permissions; an admin approves or rejects.
 * Approved permissions are given to that one user in that one company
 * (user_permissions), on top of what their role allows — the role itself,
 * shared by everyone, is not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->json('permissions');
            $t->text('reason')->nullable();
            $t->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $t->json('granted')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
            $t->index(['user_id', 'status']);
        });

        Schema::create('user_permissions', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->uuid('permission_id');
            $t->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('access_request_id')->nullable();
            $t->timestamps();
            $t->primary(['user_id', 'company_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('access_requests');
    }
};
