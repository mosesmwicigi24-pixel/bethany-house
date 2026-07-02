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
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('name', 100);
            $table->string('phone_code', 10)->nullable();
            $table->string('default_currency_code', 3)->nullable();
            $table->boolean('is_shipping_enabled')->default(false);
            $table->timestamps();

            $table->index(['code']);
            $table->index(['is_shipping_enabled']);
            
            $table->foreign('default_currency_code')->references('code')->on('currencies')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
