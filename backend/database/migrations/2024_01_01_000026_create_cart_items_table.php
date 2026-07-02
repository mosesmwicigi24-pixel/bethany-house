<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('shopping_carts')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->timestamps();
            
            $table->unique(['cart_id', 'product_id', 'product_variant_id'], 'unique_cart_item');
            $table->index(['cart_id']);
            $table->index(['product_id']);
        });
        
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT check_quantity_positive CHECK (quantity > 0)');
    }
    public function down(): void {
        Schema::dropIfExists('cart_items');
    }
};
