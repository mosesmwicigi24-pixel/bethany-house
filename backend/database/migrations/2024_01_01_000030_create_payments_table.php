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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('payment_number', 50)->unique();
            $table->string('payment_method', 50);
            $table->decimal('amount', 12, 2);
            $table->string('currency_code', 3);
            $table->string('status', 50)->default('pending');
            
            // Provider details
            $table->string('provider', 50)->nullable();
            $table->string('provider_transaction_id', 255)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->json('provider_response')->nullable();
            
            // For M-PESA
            $table->string('phone_number', 20)->nullable();
            
            // Refund info
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->timestamp('refunded_at')->nullable();
            
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['payment_number']);
            $table->index(['status']);
            $table->index(['payment_method']);
            $table->index(['provider_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
