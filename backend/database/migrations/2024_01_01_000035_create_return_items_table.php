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
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('order_returns')->onDelete('cascade');
            $table->foreignId('order_item_id')->constrained();
            $table->integer('quantity');
            $table->string('reason', 255)->nullable();
            $table->string('condition', 50)->nullable();
            $table->boolean('restock')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['return_id']);
            $table->index(['order_item_id']);
        });
        
        DB::statement('ALTER TABLE return_items ADD CONSTRAINT check_return_quantity CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};
