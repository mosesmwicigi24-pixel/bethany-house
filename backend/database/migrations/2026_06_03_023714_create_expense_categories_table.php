<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 20)->unique();
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('color', 7)->nullable();       // hex color, e.g. #3B82F6
            $table->string('icon', 50)->nullable();
            $table->decimal('requires_approval_above', 12, 2)->nullable(); // KES threshold
            $table->decimal('budget_monthly', 12, 2)->nullable();
            $table->decimal('budget_annual', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_tax_deductible')->default(false);
            $table->string('gl_code', 20)->nullable();    // General Ledger code
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};