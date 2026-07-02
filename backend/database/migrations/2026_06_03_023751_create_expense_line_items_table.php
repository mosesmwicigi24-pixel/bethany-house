<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->string('description');
            $table->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->decimal('quantity', 10, 3)->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_line_items');
    }
};