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
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('session_id', 255)->nullable();
            $table->string('cart_type', 50)->default('shopping'); // shopping, wishlist, saved
            $table->string('currency_code', 3)->default('KES');
            $table->string('status', 50)->default('active'); // active, abandoned, converted, expired
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('shipping_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('coupon_code', 50)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id']);
            $table->index(['user_id']);
            $table->index(['session_id']);
            $table->index(['cart_type']);
            $table->index(['status']);
            $table->index(['expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
