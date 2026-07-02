<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop and recreate if table exists with wrong schema
        // Otherwise create fresh
        if (!Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table) {
                $table->string('code', 3)->primary();   // ISO 3166-1 alpha-2 (KE, US, GB)
                $table->string('name', 100);
                $table->string('native_name', 100)->nullable();
                $table->string('phone_code', 10)->nullable();       // +254
                $table->string('flag', 10)->nullable();              // 🇰🇪
                $table->string('region', 50)->nullable();            // Africa, Europe, etc.
                $table->string('subregion', 50)->nullable();         // Eastern Africa
                $table->string('default_currency_code', 10)->nullable();   // KES
                $table->boolean('is_active')->default(true);
                $table->boolean('is_shipping_enabled')->default(false);
                // Shipping settings per country
                $table->decimal('free_shipping_threshold', 10, 2)->nullable();
                $table->decimal('standard_shipping_cost', 10, 2)->nullable();
                $table->decimal('express_shipping_cost', 10, 2)->nullable();
                $table->integer('estimated_delivery_days')->nullable();
                $table->timestamps();
            });
        } else {
            // Table exists - add any missing columns
            Schema::table('countries', function (Blueprint $table) {
                if (!Schema::hasColumn('countries', 'native_name'))
                    $table->string('native_name', 100)->nullable()->after('name');
                if (!Schema::hasColumn('countries', 'flag'))
                    $table->string('flag', 10)->nullable()->after('phone_code');
                if (!Schema::hasColumn('countries', 'region'))
                    $table->string('region', 50)->nullable()->after('flag');
                if (!Schema::hasColumn('countries', 'subregion'))
                    $table->string('subregion', 50)->nullable()->after('region');
                if (!Schema::hasColumn('countries', 'is_active'))
                    $table->boolean('is_active')->default(true)->after('subregion');
                if (!Schema::hasColumn('countries', 'is_shipping_enabled'))
                    $table->boolean('is_shipping_enabled')->default(false)->after('is_active');
                if (!Schema::hasColumn('countries', 'free_shipping_threshold'))
                    $table->decimal('free_shipping_threshold', 10, 2)->nullable();
                if (!Schema::hasColumn('countries', 'standard_shipping_cost'))
                    $table->decimal('standard_shipping_cost', 10, 2)->nullable();
                if (!Schema::hasColumn('countries', 'express_shipping_cost'))
                    $table->decimal('express_shipping_cost', 10, 2)->nullable();
                if (!Schema::hasColumn('countries', 'estimated_delivery_days'))
                    $table->integer('estimated_delivery_days')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};