<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('inventory_transfers')->onDelete('cascade');
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_variant_id')->nullable()->constrained();
            $table->integer('quantity_requested');
            $table->integer('quantity_received')->default(0);
            $table->timestamps();

            $table->index(['transfer_id']);
            $table->index(['product_id']);
        });
        
        DB::statement('ALTER TABLE inventory_transfer_items ADD CONSTRAINT check_quantities_positive CHECK (quantity_requested > 0 AND quantity_received >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
    }
};
