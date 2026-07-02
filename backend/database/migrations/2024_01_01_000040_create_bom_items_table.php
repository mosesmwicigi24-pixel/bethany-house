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
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('bills_of_materials')->onDelete('cascade');
            $table->foreignId('material_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->string('unit_of_measure', 20);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['bom_id']);
            $table->index(['material_id']);
        });
        
        DB::statement('ALTER TABLE bom_items ADD CONSTRAINT check_bom_quantity CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bom_items');
    }
};
