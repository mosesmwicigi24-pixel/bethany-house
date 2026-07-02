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
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->string('register_number', 50)->unique();
            $table->foreignId('outlet_id')->constrained();
            $table->string('register_name', 100);
            $table->string('status', 50)->default('closed'); // open, closed, suspended
            $table->string('currency_code', 3)->default('KES');
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('closing_balance', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('actual_cash', 12, 2)->default(0);
            $table->decimal('cash_difference', 12, 2)->storedAs('actual_cash - expected_cash');
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_cash_sales', 12, 2)->default(0);
            $table->decimal('total_card_sales', 12, 2)->default(0);
            $table->decimal('total_mpesa_sales', 12, 2)->default(0);
            $table->decimal('total_refunds', 12, 2)->default(0);
            $table->integer('transaction_count')->default(0);
            $table->foreignId('opened_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('closed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->json('denomination_count')->nullable(); // Count of each denomination
            $table->timestamps();

            $table->index(['outlet_id']);
            $table->index(['status']);
            $table->index(['register_number']);
            $table->index(['opened_at']);
            $table->index(['closed_at']);
        });

        // Add check constraint
        DB::statement('ALTER TABLE cash_registers ADD CONSTRAINT check_register_balances CHECK (opening_balance >= 0 AND closing_balance >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
