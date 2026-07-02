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
        Schema::create('shipment_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('order_shipments')->onDelete('cascade');
            $table->string('status', 50);
            $table->string('location', 255)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('event_time');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shipment_id']);
            $table->index(['event_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking');
    }
};
