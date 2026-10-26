<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People PR 7 — advances and loans, repaid through payroll.
 *
 * An advance paid out is cash leaving and a receivable from the person: it is
 * NOT an expense (the wage it is drawn against is expensed when the run that
 * earns it is approved). Each approved run takes the instalment off net pay
 * as an "other deduction" and writes an advance_repayments row per line, so
 * the balance rebuilds from the rows: balance = amount − Σ repayments.
 *
 * payroll_lines.deductions holds the per-line list the run took, each with
 * the advance id, so the payslip and the ledger agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 12)->default('advance');   // advance (one period) | loan (instalments)
            $table->decimal('amount', 12, 2);
            $table->decimal('instalment', 12, 2);              // taken per run; an advance's instalment = its amount
            $table->decimal('balance', 12, 2);                 // amount − repayments; derived, kept for listing
            $table->string('reason', 300)->nullable();
            $table->string('status', 12)->default('requested'); // requested | approved | paid | settled | declined | cancelled
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 12)->nullable(); // mpesa | bank | cash
            $table->string('payment_reference', 80)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_advance_id')->constrained('staff_advances')->cascadeOnDelete();
            $table->foreignId('payroll_line_id')->nullable()->constrained('payroll_lines')->nullOnDelete();
            $table->string('kind', 12)->default('payroll');    // payroll | cash
            $table->decimal('amount', 12, 2);
            $table->date('recorded_on');
            $table->string('reference', 80)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // One repayment per advance per payroll line: approving twice cannot double-take.
            $table->unique(['staff_advance_id', 'payroll_line_id']);
        });

        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->json('deductions')->nullable()->after('items'); // [{advance_id, label, amount}]
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', fn (Blueprint $t) => $t->dropColumn('deductions'));
        Schema::dropIfExists('advance_repayments');
        Schema::dropIfExists('staff_advances');
    }
};
