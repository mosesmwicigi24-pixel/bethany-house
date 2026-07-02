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
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_variant_id')->nullable()->constrained();
            $table->foreignId('customer_order_id')->nullable()->constrained('orders')->onDelete('set null');
            $table->foreignId('order_item_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('quantity');
            $table->string('status', 50)->default('pending');
            $table->string('priority', 20)->default('normal');
            $table->date('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('outlet_id')->nullable()->constrained()->onDelete('set null');
            $table->json('specifications')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['order_number']);
            $table->index(['product_id']);
            $table->index(['customer_order_id']);
            $table->index(['status']);
            $table->index(['priority']);
            $table->index(['due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
