<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companies are the tenancy anchor for the whole ERP — the Cheerakuzhy group
 * runs several nursery companies, and every master/transaction row is scoped to
 * one via company_id. This replaces the starter kit's teams concept entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('legal_name')->nullable();
            $table->string('gst_number')->nullable();
            $table->string('state')->nullable();
            $table->string('state_code', 2)->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
