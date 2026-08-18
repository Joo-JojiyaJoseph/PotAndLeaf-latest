<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global catalog of capabilities (e.g. "products.create"). Not team-scoped:
 * permissions are application features. Companies grant them through roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();   // products.create, * , products.*
            $table->string('module');           // grouping label for the matrix
            $table->string('label');            // human readable
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
