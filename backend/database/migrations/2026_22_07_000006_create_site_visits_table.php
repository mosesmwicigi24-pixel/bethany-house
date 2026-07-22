<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregate storefront visit analytics — country + device only, NO IP stored
 * (the storefront resolves the country from geo-IP and posts a minimal record;
 * the raw address never reaches or is kept by the hub). Powers the Insights
 * dashboard: visitors by country + device/OS mix (a purchasing-power signal).
 * "Which countries are buying" comes from orders.customer_country_code, so no
 * personal data is needed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->nullable();
            $table->string('device_type', 20)->nullable(); // mobile | tablet | desktop
            $table->string('os', 30)->nullable();
            $table->string('browser', 30)->nullable();
            $table->boolean('is_mobile')->default(false);
            $table->string('path', 300)->nullable();
            $table->string('referrer', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('country_code');
            $table->index('device_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
