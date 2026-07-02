<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 30)->unique();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained('expense_categories');
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3)->default('KES');
            $table->decimal('exchange_rate', 12, 6)->default(1.000000);
            $table->decimal('amount_kes', 14, 2);        // always stored in KES for reporting
            $table->date('expense_date');
            $table->string('payment_method', 30);         // cash|bank_transfer|mpesa|card|cheque|other
            $table->string('payment_reference', 100)->nullable();
            $table->string('vendor_name', 255)->nullable();
            $table->string('vendor_contact', 255)->nullable();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('department', 100)->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_frequency', 20)->nullable(); // weekly|monthly|quarterly|annually
            $table->date('recurrence_end_date')->nullable();
            $table->foreignId('parent_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->string('status', 30)->default('draft');
            // draft | pending_approval | approved | rejected | paid | cancelled
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('receipt_path')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            // Optional linkage to other records
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for common query patterns
            $table->index(['status', 'expense_date']);
            $table->index(['category_id', 'expense_date']);
            $table->index(['outlet_id', 'expense_date']);
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};