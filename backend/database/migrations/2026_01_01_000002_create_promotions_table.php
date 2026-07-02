<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('type', 30)->default('product_discount');
            // product_discount | category_discount | bundle | buy_x_get_y | flash_sale
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->string('discount_type', 20)->default('percentage'); // percentage | fixed
            $table->json('conditions')->nullable();   // flexible rules storage
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('priority')->default(0);  // higher = evaluated first
            $table->boolean('is_exclusive')->default(false); // cannot stack with others
            $table->integer('max_uses')->nullable();
            $table->integer('times_used')->default(0);
            $table->string('banner_image', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};