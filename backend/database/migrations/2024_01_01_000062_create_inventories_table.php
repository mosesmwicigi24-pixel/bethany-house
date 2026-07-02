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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_type', 50)->default('product'); // product, material
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('material_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('outlet_id')->constrained();
            $table->string('sku', 100)->nullable();
            $table->decimal('quantity_on_hand', 12, 2)->default(0);
            $table->decimal('quantity_reserved', 12, 2)->default(0);
            $table->decimal('quantity_available', 12, 2)->storedAs('quantity_on_hand - quantity_reserved');
            $table->decimal('quantity_damaged', 12, 2)->default(0);
            $table->decimal('quantity_in_transit', 12, 2)->default(0);
            $table->decimal('reorder_point', 12, 2)->nullable();
            $table->decimal('reorder_quantity', 12, 2)->nullable();
            $table->decimal('minimum_stock_level', 12, 2)->default(0);
            $table->decimal('maximum_stock_level', 12, 2)->nullable();
            $table->decimal('cost_per_unit', 12, 2)->nullable();
            $table->string('unit_of_measure', 20)->default('unit'); // unit, kg, meter, liter, etc.
            $table->string('bin_location', 100)->nullable(); // Warehouse location
            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status', 50)->default('available'); // available, low_stock, out_of_stock, discontinued
            $table->timestamp('last_counted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'outlet_id']);
            $table->index(['material_id', 'outlet_id']);
            $table->index(['sku']);
            $table->index(['status']);
            $table->index(['inventory_type']);
            $table->unique(['product_id', 'product_variant_id', 'outlet_id', 'batch_number'], 'unique_product_inventory');
        });

        // Add check constraints
        DB::statement('ALTER TABLE inventories ADD CONSTRAINT check_inventory_quantities CHECK (quantity_on_hand >= 0 AND quantity_reserved >= 0)');
        DB::statement('ALTER TABLE inventories ADD CONSTRAINT check_inventory_type CHECK ((inventory_type = \'product\' AND product_id IS NOT NULL) OR (inventory_type = \'material\' AND material_id IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
