<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')
                  ->constrained('purchase_returns')
                  ->cascadeOnDelete();
            $table->foreignId('po_item_id')
                  ->constrained('purchase_order_items')
                  ->restrictOnDelete();
            $table->decimal('quantity', 10, 3);
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};