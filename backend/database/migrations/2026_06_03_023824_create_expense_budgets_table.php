<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('expense_categories');
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period_type', 20);      // monthly | quarterly | annual
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_number'); // 1-12 for month, 1-4 for quarter, 1 for annual
            $table->decimal('budgeted_amount', 14, 2);
            $table->string('currency_code', 3)->default('KES');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['category_id', 'outlet_id', 'period_type', 'period_year', 'period_number'], 'unique_budget');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_budgets');
    }
};