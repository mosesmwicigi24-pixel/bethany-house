<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('description', 255)->nullable();
            $table->string('type', 20)->default('percentage'); // percentage | fixed | free_shipping
            $table->decimal('value', 12, 2);                   // % or flat amount
            $table->decimal('minimum_order_amount', 12, 2)->nullable();
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_limit_per_customer')->nullable();
            $table->integer('times_used')->default(0);
            $table->json('applicable_products')->nullable();   // null = all products
            $table->json('applicable_categories')->nullable(); // null = all categories
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['code']);
            $table->index(['is_active']);
            $table->index(['valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};