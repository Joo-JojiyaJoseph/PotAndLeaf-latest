<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Add is_default to company_user when missing (legacy databases). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_user')) {
            return;
        }

        if (! Schema::hasColumn('company_user', 'is_default')) {
            Schema::table('company_user', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('company_user') && Schema::hasColumn('company_user', 'is_default')) {
            Schema::table('company_user', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
