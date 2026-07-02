<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('outlet_id')->constrained()->onDelete('cascade');
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('quantity_reserved')->default(0);
            $table->integer('reorder_point')->default(5);
            $table->integer('reorder_quantity')->default(10);
            $table->timestamp('last_counted_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'product_variant_id', 'outlet_id'], 'unique_inventory_item');
            $table->index(['product_id']);
            $table->index(['product_variant_id']);
            $table->index(['outlet_id']);
        });
        
        // Add computed column for available quantity
        DB::statement('ALTER TABLE inventory_items ADD COLUMN quantity_available INTEGER GENERATED ALWAYS AS (quantity_on_hand - quantity_reserved) STORED');
        
        // Add check constraints
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT check_quantities_non_negative CHECK (quantity_on_hand >= 0 AND quantity_reserved >= 0)');
        
        // Add index for low stock alerts
        DB::statement('CREATE INDEX idx_inventory_items_low_stock ON inventory_items(outlet_id) WHERE quantity_available <= reorder_point');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
