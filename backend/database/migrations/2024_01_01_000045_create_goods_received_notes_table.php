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
        Schema::create('goods_received_notes', function (Blueprint $table) {
            $table->id();
            $table->string('grn_number', 50)->unique();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('outlet_id')->nullable()->constrained();
            $table->date('received_date');
            $table->string('invoice_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['grn_number']);
            $table->index(['purchase_order_id']);
            $table->index(['received_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_received_notes');
    }
};
