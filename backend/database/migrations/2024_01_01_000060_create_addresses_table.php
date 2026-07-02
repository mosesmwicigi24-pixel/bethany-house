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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('address_type', 50)->default('shipping'); // shipping, billing, both
            $table->string('label', 100)->nullable(); // Home, Office, etc.
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('company', 255)->nullable();
            $table->string('address_line1', 255);
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 100);
            $table->string('state_province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2);
            $table->string('phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_default')->default(false);
            $table->text('delivery_instructions')->nullable();
            $table->timestamps();

            $table->index(['customer_id']);
            $table->index(['user_id']);
            $table->index(['address_type']);
            $table->index(['is_default']);
            $table->index(['country_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
