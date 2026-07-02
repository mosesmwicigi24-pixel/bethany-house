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
        Schema::create('cash_register_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->onDelete('cascade');
            $table->string('transaction_type', 50); // sale, refund, cash_in, cash_out, opening, closing
            $table->string('payment_method', 50)->nullable(); // cash, card, mpesa
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cash_register_id']);
            $table->index(['transaction_type']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_register_transactions');
    }
};
