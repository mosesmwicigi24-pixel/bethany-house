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
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->string('outlet_type', 50);
            $table->string('email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state_province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_pickup_location')->default(false);
            $table->json('operating_hours')->nullable();
            $table->timestamps();

            $table->index(['code']);
            $table->index(['outlet_type']);
            $table->index(['is_active']);
            $table->index(['is_pickup_location']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outlets');
    }
};
