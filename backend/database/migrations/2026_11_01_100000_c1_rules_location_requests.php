<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People C1 (owner approval 26 Oct 2026).
 *
 * 1. Pay periods can end on a day of the month (Bethany pays 11th → 10th);
 *    hours per weekday and an unpaid lunch rule move onto the pay group.
 * 2. Location on shift: a trail of pings between clock-in and clock-out,
 *    kept 90 days; "left the site" marked on the entry.
 * 3. Timesheet fix requests from staff; clock-in / clock-out nudges from
 *    the roster; location-free fixed devices (the shop computer, a kiosk).
 *
 * The attendance rules themselves live in the `settings` table under
 * `attendance.*` keys (App\Support\AttendanceRules) so they are editable
 * on the Hub rather than in a server file.
 *
 * The default pay group gets period_end_day = 10 only when it has no
 * locked period and no timesheet yet: re-cutting a period that already
 * carries approved hours is the owner's call on the Pay groups screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_groups', function (Blueprint $table) {
            $table->unsignedTinyInteger('period_end_day')->nullable()->after('frequency');   // 1–28; null = calendar month
            $table->unsignedTinyInteger('pay_date_day')->nullable()->after('period_end_day'); // informational
            $table->json('hours_by_weekday')->nullable()->after('standard_hours_per_day');    // {"1":12,…,"6":6}; null = standard every day
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(0)->after('round_to_minutes');
            $table->decimal('unpaid_break_after_hours', 4, 2)->nullable()->after('unpaid_break_minutes');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('location_free')->default(false)->after('name'); // a fixed device may punch without a position
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->timestamp('left_site_at')->nullable()->after('auto_closed_at');
            $table->unsignedInteger('trail_points')->default(0)->after('left_site_at');
        });
        // clock_in_method was an enum (gps | override); a location-free fixed device punches as 'device'.
        if (DB::getDriverName() === 'pgsql') {
            // A location-free fixed device punches without a position.
            DB::statement('ALTER TABLE time_entries ALTER COLUMN clock_in_latitude DROP NOT NULL');
            DB::statement('ALTER TABLE time_entries ALTER COLUMN clock_in_longitude DROP NOT NULL');
            DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_clock_in_method_check');
            DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_clock_in_method_check CHECK (clock_in_method IN ('gps', 'override', 'device'))");
        }

        Schema::create('location_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->unsignedInteger('distance_meters')->nullable(); // from the entry's outlet, when known
            $table->boolean('mocked')->default(false);
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['time_entry_id', 'recorded_at']);
            $table->index('recorded_at'); // retention
        });

        Schema::create('timesheet_fix_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('message', 300);
            $table->string('status', 12)->default('requested'); // requested | approved | declined
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('clock_in_nudged_at')->nullable()->after('reminded_at');
            $table->timestamp('clock_out_nudged_at')->nullable()->after('clock_in_nudged_at');
        });

        $default = DB::table('pay_groups')->where('is_default', true)->first() ?? DB::table('pay_groups')->orderBy('id')->first();
        if ($default) {
            $locked = DB::table('pay_periods')->where('pay_group_id', $default->id)->where('status', 'locked')->exists();
            $sheets = DB::table('timesheets')->whereIn('pay_period_id', DB::table('pay_periods')->where('pay_group_id', $default->id)->pluck('id'))->exists();
            if (!$locked && !$sheets) {
                DB::table('pay_groups')->where('id', $default->id)->update(['period_end_day' => 10, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('shifts', fn (Blueprint $t) => $t->dropColumn(['clock_in_nudged_at', 'clock_out_nudged_at']));
        Schema::dropIfExists('timesheet_fix_requests');
        Schema::dropIfExists('location_pings');
        Schema::table('time_entries', fn (Blueprint $t) => $t->dropColumn(['left_site_at', 'trail_points']));
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('location_free'));
        Schema::table('pay_groups', fn (Blueprint $t) => $t->dropColumn(['period_end_day', 'pay_date_day', 'hours_by_weekday', 'unpaid_break_minutes', 'unpaid_break_after_hours']));
    }
};
