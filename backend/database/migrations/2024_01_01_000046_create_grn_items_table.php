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
        Schema::create('grn_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_id')->constrained('goods_received_notes')->onDelete('cascade');
            $table->foreignId('po_item_id')->constrained('purchase_order_items');
            $table->decimal('quantity_received', 12, 2);
            $table->decimal('quantity_rejected', 12, 2)->default(0);
            $table->string('condition', 50)->default('good');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['grn_id']);
            $table->index(['po_item_id']);
        });
        
        DB::statement('ALTER TABLE grn_items ADD CONSTRAINT check_grn_quantities CHECK (quantity_received > 0 AND quantity_rejected >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grn_items');
    }
};
