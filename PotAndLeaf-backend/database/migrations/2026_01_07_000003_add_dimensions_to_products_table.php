<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Physical dimensions (cm) used for CBM / container planning. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('length_cm', 10, 2)->nullable()->after('reorder_level');
            $table->decimal('width_cm', 10, 2)->nullable()->after('length_cm');
            $table->decimal('height_cm', 10, 2)->nullable()->after('width_cm');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['length_cm', 'width_cm', 'height_cm']);
        });
    }
};
