<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->onDelete('cascade');
            $table->foreignId('outlet_id')->constrained()->onDelete('cascade');
            $table->decimal('quantity_on_hand', 12, 2)->default(0);
            $table->decimal('quantity_reserved', 12, 2)->default(0);
            $table->timestamp('last_counted_at')->nullable();
            $table->timestamps();

            $table->unique(['material_id', 'outlet_id']);
            $table->index(['material_id']);
            $table->index(['outlet_id']);
        });
        
        DB::statement('ALTER TABLE material_inventory ADD COLUMN quantity_available DECIMAL(12,2) GENERATED ALWAYS AS (quantity_on_hand - quantity_reserved) STORED');
        DB::statement('ALTER TABLE material_inventory ADD CONSTRAINT check_material_quantities CHECK (quantity_on_hand >= 0 AND quantity_reserved >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('material_inventory');
    }
};
