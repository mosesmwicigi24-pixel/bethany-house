<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_inventory_id')->constrained('material_inventory')->onDelete('cascade');
            $table->string('transaction_type', 50);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('quantity_change', 12, 2);
            $table->decimal('quantity_before', 12, 2);
            $table->decimal('quantity_after', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['material_inventory_id']);
            $table->index(['transaction_type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_transactions');
    }
};
