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
        Schema::create('shipping_zone_countries', function (Blueprint $table) {
            $table->foreignId('shipping_zone_id')->constrained()->onDelete('cascade');
            $table->string('country_code', 2);
            
            $table->primary(['shipping_zone_id', 'country_code']);
            $table->index(['shipping_zone_id']);
            $table->index(['country_code']);
            
            $table->foreign('country_code')->references('code')->on('countries')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_zone_countries');
    }
};
