<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('damage_entries');

        Schema::create('damage_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('entry_no', 32);
            $table->date('entry_date');
            $table->decimal('qty', 14, 3);
            $table->string('reason', 120);
            $table->text('notes')->nullable();
            $table->string('photo')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'entry_no']);
            $table->index(['company_id', 'entry_date']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_entries');
    }
};
