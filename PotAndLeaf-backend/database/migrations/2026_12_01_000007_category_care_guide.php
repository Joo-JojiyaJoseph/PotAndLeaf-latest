<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $t) {
            $t->text('care_guide_en')->nullable()->after('description');
            $t->text('care_guide_ml')->nullable()->after('care_guide_en');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $t) {
            $t->dropColumn(['care_guide_en', 'care_guide_ml']);
        });
    }
};
