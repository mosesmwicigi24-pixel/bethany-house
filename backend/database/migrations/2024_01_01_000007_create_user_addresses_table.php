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
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('address_type', 20);
            $table->string('label', 50)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('address_line1', 255);
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 100);
            $table->string('state_province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2);
            $table->string('phone', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id']);
            $table->index(['country_code']);
            $table->index(['user_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
