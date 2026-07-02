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
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('delivery_time', 100)->nullable();
            $table->string('cost_type', 50);
            $table->decimal('flat_rate', 12, 2)->nullable();
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['shipping_zone_id']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
