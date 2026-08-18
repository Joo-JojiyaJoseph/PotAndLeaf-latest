<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Photos for suppliers/customers/companies, a supplier account-name + address line,
 *  and a company logo/description — for the media & detail screens. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('name');
            $table->string('bank_account_name')->nullable()->after('bank_name');
            $table->string('address')->nullable()->after('pincode');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('name');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('name');
            $table->text('description')->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn(['photo', 'bank_account_name', 'address']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('photo'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['logo', 'description']));
    }
};
