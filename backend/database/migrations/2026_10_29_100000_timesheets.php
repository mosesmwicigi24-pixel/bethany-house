<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People, PR 5 — timesheets: the bridge from hours to pay
 * (owner, 24 Oct 2026).
 *
 * pay_groups: who is paid on which rhythm and under which overtime rule.
 * Kenya's floor is written in as the default group: overtime at 1.5× on a
 * working day beyond the standard hours, 2× on a rest day or public
 * holiday. The multipliers are RATES FOR PR 6; here they only label the
 * minutes.
 *
 * pay_periods: one row per group per period, created on demand. A period
 * is open until finance LOCKS it; a locked period's timesheets never
 * change (a correction after lock is a payroll adjustment, PR 6).
 *
 * timesheets: one per person per period. Live totals are DERIVED from
 * time_entries and approved leave until approval; at approval the totals
 * are SNAPSHOT so what the manager signed is what payroll reads, whatever
 * happens to the underlying punches later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('frequency', 12)->default('monthly'); // monthly | weekly
            $table->decimal('standard_hours_per_day', 4, 2)->default(8);
            $table->decimal('overtime_after_daily_hours', 4, 2)->nullable()->default(8);
            $table->decimal('overtime_after_weekly_hours', 5, 2)->nullable();
            $table->decimal('overtime_multiplier', 4, 2)->default(1.5);
            $table->decimal('rest_day_multiplier', 4, 2)->default(2);
            $table->decimal('holiday_multiplier', 4, 2)->default(2);
            $table->unsignedSmallInteger('round_to_minutes')->default(1); // 1 = no rounding; 5, 15
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->foreignId('pay_group_id')->nullable()->after('employment_type')->constrained('pay_groups')->nullOnDelete();
        });

        Schema::create('pay_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_group_id')->constrained('pay_groups')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 12)->default('open'); // open | locked
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->string('unlock_reason', 300)->nullable();
            $table->timestamps();
            $table->unique(['pay_group_id', 'starts_on']);
        });

        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_period_id')->constrained('pay_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('draft'); // draft | submitted | sent_back | approved
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('note', 500)->nullable();          // the person's note
            $table->string('sent_back_note', 500)->nullable(); // the manager's
            // Snapshot at approval (minutes), the figures payroll reads.
            $table->unsignedInteger('regular_minutes')->nullable();
            $table->unsignedInteger('overtime_minutes')->nullable();
            $table->unsignedInteger('rest_day_minutes')->nullable();
            $table->unsignedInteger('holiday_minutes')->nullable();
            $table->decimal('paid_leave_days', 5, 2)->nullable();
            $table->decimal('unpaid_leave_days', 5, 2)->nullable();
            $table->unsignedSmallInteger('days_worked')->nullable();
            $table->json('snapshot')->nullable(); // the day-by-day table as approved
            $table->timestamps();
            $table->unique(['pay_period_id', 'user_id']);
        });

        $now = now();
        DB::table('pay_groups')->insert([
            'name' => 'Monthly staff', 'frequency' => 'monthly', 'standard_hours_per_day' => 8, 'overtime_after_daily_hours' => 8,
            'overtime_after_weekly_hours' => null, 'overtime_multiplier' => 1.5, 'rest_day_multiplier' => 2, 'holiday_multiplier' => 2,
            'round_to_minutes' => 1, 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheets');
        Schema::dropIfExists('pay_periods');
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pay_group_id');
        });
        Schema::dropIfExists('pay_groups');
    }
};
