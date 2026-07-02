<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('outlet_id')->nullable()->constrained()->onDelete('set null');
            $table->string('order_type', 50)->default('online'); // online, pos
            $table->string('status', 50)->default('pending');
            $table->string('currency_code', 3);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('shipping_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            
            // Customer info (denormalized for guests)
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_first_name', 100)->nullable();
            $table->string('customer_last_name', 100)->nullable();
            
            // Billing address
            $table->string('billing_address_line1', 255)->nullable();
            $table->string('billing_address_line2', 255)->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_state', 100)->nullable();
            $table->string('billing_postal_code', 20)->nullable();
            $table->string('billing_country_code', 2)->nullable();
            
            // Shipping address
            $table->string('shipping_address_line1', 255)->nullable();
            $table->string('shipping_address_line2', 255)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
            $table->string('shipping_country_code', 2)->nullable();
            
            $table->string('shipping_method', 100)->nullable();
            $table->string('delivery_type', 50)->nullable(); // delivery, pickup
            $table->foreignId('pickup_outlet_id')->nullable()->constrained('outlets')->onDelete('set null');
            
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_status', 50)->default('pending');
            
            $table->text('notes')->nullable();
            $table->text('customer_notes')->nullable();
            
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->index(['order_number']);
            $table->index(['user_id']);
            $table->index(['outlet_id']);
            $table->index(['status']);
            $table->index(['order_type']);
            $table->index(['payment_status']);
            $table->index(['created_at']);
            $table->index(['customer_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};