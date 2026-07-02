<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_variant_id')->nullable()->constrained();
            $table->string('sku', 100);
            $table->string('product_name', 255);
            $table->string('variant_name', 255)->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2);
            $table->boolean('requires_production')->default(false);
            $table->foreignId('production_order_id')->nullable();
            // $table->foreignId('production_order_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['product_id']);
            $table->index(['product_variant_id']);
            $table->index(['production_order_id']);
        });
        
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT check_quantity CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};