<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('sku', 100)->unique();
            $table->string('slug')->unique();

            // Product structure
            $table->string('product_type', 50)->default('simple');

            // Inventory / manufacturing
            $table->boolean('is_producible')->default(false);

            $table->string('status', 30)->default('draft'); 
            // draft | active | inactive | archived

            // Storefront control
            $table->timestamp('published_at')->nullable();

            // Merchandising
            $table->boolean('is_featured')->default(false);

            // Physical attributes
            $table->decimal('weight', 10, 2)->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();

            $table->string('brand', 100)->nullable();
            $table->string('tax_class', 50)->default('standard');

            $table->integer('low_stock_threshold')->default(5);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Indexes (Performance Optimized)
            |--------------------------------------------------------------------------
            */

            $table->index('category_id');
            $table->index('product_type');
            $table->index('status');
            $table->index('is_featured');
            $table->index('published_at');
            $table->index(['status', 'published_at']); // storefront query optimization
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
