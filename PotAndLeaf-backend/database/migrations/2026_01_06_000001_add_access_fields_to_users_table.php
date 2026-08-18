<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the access fields the ERP needs on top of the framework's users table:
 * a super-admin flag (HO can manage every company), a phone/WhatsApp number,
 * and an active toggle for deactivating logins without deleting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->boolean('is_super_admin')->default(false)->after('phone');
            $table->boolean('is_active')->default(true)->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'is_super_admin', 'is_active']);
        });
    }
};
