<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('material_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->onDelete('cascade');
            $table->foreignId('material_id')->constrained();
            $table->decimal('quantity_required', 12, 2);
            $table->decimal('quantity_allocated', 12, 2)->default(0);
            $table->decimal('quantity_used', 12, 2)->default(0);
            $table->decimal('quantity_returned', 12, 2)->default(0);
            $table->timestamp('allocated_at')->nullable();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['production_order_id']);
            $table->index(['material_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_allocations');
    }
};
