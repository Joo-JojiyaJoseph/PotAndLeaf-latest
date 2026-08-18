<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Links a "set" SKU with its "unit" SKU so both draw from one
            // physical stock count. pool_role is null for ordinary products.
            $table->uuid('pool_group_id')->nullable()->after('status');
            $table->string('pool_role')->nullable()->after('pool_group_id'); // set | unit
            $table->decimal('units_per_set', 14, 3)->nullable()->after('pool_role');

            $table->index('pool_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['pool_group_id']);
            $table->dropColumn(['pool_group_id', 'pool_role', 'units_per_set']);
        });
    }
};
