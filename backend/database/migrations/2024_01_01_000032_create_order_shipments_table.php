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
        Schema::create('order_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('shipment_number', 50)->unique();
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 255)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->string('status', 50)->default('pending');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['shipment_number']);
            $table->index(['tracking_number']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_shipments');
    }
};
