<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('country_code', 2)->nullable();
            $table->string('state_province', 100)->nullable();
            $table->decimal('rate', 5, 4);
            $table->string('tax_type', 50)->default('vat');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['country_code']);
            $table->index(['is_active']);
            
            $table->foreign('country_code')->references('code')->on('countries')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
