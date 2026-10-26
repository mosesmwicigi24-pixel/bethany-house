<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People, PR 4 — time off (owner, 24 Oct 2026).
 *
 * leave_types: the policy. days_per_year null means no balance is kept
 * (unpaid). accrual 'monthly' earns the year's days a twelfth at a time
 * (Kenya's annual leave: 21 working days per year of service); 'front'
 * grants them on 1 January (prorated in the hire year). counts_calendar_days
 * is for statutory leave measured in calendar days (maternity, paternity);
 * the rest count working days (config attendance.working_days) and skip
 * public holidays.
 *
 * leave_requests: one row per request; `days` is computed by the server
 * from the dates and the type, never typed. Balances are never stored:
 * they are derived at read time from the type, the hire date, approved
 * requests and leave_adjustments (the same rule as every report figure).
 *
 * Seeded types follow the Employment Act 2007 floor; the owner edits them
 * in Settings › Leave. Nothing here is a payroll figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 60);
            $table->decimal('days_per_year', 5, 2)->nullable();
            $table->string('accrual', 12)->default('front'); // front | monthly
            $table->boolean('is_paid')->default(true);
            $table->boolean('counts_calendar_days')->default(false);
            $table->unsignedSmallInteger('evidence_after_days')->nullable(); // sick: a note after this many days
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('days', 5, 2);
            $table->string('reason', 500)->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | declined | cancelled
            $table->foreignId('evidence_document_id')->nullable()->constrained('staff_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'starts_on']);
            $table->index(['status', 'starts_on']);
        });

        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types');
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 5, 2); // + grants, − takes
            $table->string('reason', 300);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'leave_type_id', 'year']);
        });

        $now = now();
        DB::table('leave_types')->insert([
            ['code' => 'annual',        'name' => 'Annual leave',        'days_per_year' => 21, 'accrual' => 'monthly', 'is_paid' => true,  'counts_calendar_days' => false, 'evidence_after_days' => null, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'sick',          'name' => 'Sick leave',          'days_per_year' => 14, 'accrual' => 'front',   'is_paid' => true,  'counts_calendar_days' => false, 'evidence_after_days' => 2,    'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'maternity',     'name' => 'Maternity leave',     'days_per_year' => 90, 'accrual' => 'front',   'is_paid' => true,  'counts_calendar_days' => true,  'evidence_after_days' => null, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'paternity',     'name' => 'Paternity leave',     'days_per_year' => 14, 'accrual' => 'front',   'is_paid' => true,  'counts_calendar_days' => true,  'evidence_after_days' => null, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'compassionate', 'name' => 'Compassionate leave', 'days_per_year' => 5,  'accrual' => 'front',   'is_paid' => true,  'counts_calendar_days' => false, 'evidence_after_days' => null, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'unpaid',        'name' => 'Unpaid leave',        'days_per_year' => null, 'accrual' => 'front', 'is_paid' => false, 'counts_calendar_days' => false, 'evidence_after_days' => null, 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_adjustments');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};
