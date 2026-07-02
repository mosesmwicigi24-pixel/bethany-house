<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->onDelete('cascade');
            $table->string('item_type', 50);
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('product_variant_id')->nullable()->constrained();
            $table->foreignId('material_id')->nullable()->constrained();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 2);
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2);
            $table->timestamps();

            $table->index(['purchase_order_id']);
            $table->index(['product_id']);
            $table->index(['material_id']);
        });
        
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT check_item_type CHECK ((item_type = \'product\' AND product_id IS NOT NULL) OR (item_type = \'material\' AND material_id IS NOT NULL))');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT check_po_quantities CHECK (quantity > 0 AND quantity_received >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
