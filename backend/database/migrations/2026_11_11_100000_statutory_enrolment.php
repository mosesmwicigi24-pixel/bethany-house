<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Government deductions per person (owner, 2026-11-03): "My staff are in
 * contracts. Allow accounts to add all government deductions or stop them.
 * When one becomes permanently employed, an add button adds all deductibles."
 *
 * One switch per person for all four — PAYE, NSSF, SHIF (SHA, formerly NHIF)
 * and the Housing Levy — kept as dated rows like pay_structures: a change is
 * a new row from a date, never an edit, so an earlier payslip keeps what
 * applied then. No row means OFF: a contract hire has no deductions until
 * accounts add them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('effective_from');
            $table->boolean('applies');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_enrolments');
    }
};
