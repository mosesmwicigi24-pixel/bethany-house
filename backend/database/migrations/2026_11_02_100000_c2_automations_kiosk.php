<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People C2 (owner, 1 Nov 2026) — alerts to the manager, the
 * automations table that switches each one, and the kiosk.
 *
 * automations: one row per alert (`key`), with the switch, its threshold
 * (minutes for the clock alerts, days for documents) and who hears it:
 * `recipients` is managers (users.reports_to + attendance.manage in the
 * person's outlets, App\Support\AttendanceAlerts), hr (hr.edit holders) or
 * owner (super_admin); `extra_user_ids` adds named people. Seeded with the
 * defaults the review agreed, so the alerts work before anyone opens Setup.
 *
 * shifts.missed_alerted_at and time_entries.overtime_alerted_at make the
 * missed-shift and overtime alerts fire once per shift/entry (the jobs run
 * every fifteen minutes). The kiosk punches as clock_in_method 'kiosk' and
 * keeps a photo of the punch beside the entry (private disk, pruned with
 * the trails).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('threshold')->default(0);
            $t->string('recipients', 16)->default('managers'); // managers | hr | owner
            $t->json('extra_user_ids')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        $now = now();
        DB::table('automations')->insert([
            ['key' => 'missed_shift',      'enabled' => true, 'threshold' => 15, 'recipients' => 'managers', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'outside_fence',     'enabled' => true, 'threshold' => 0,  'recipients' => 'managers', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'overtime_soon',     'enabled' => true, 'threshold' => 30, 'recipients' => 'managers', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'document_expiring', 'enabled' => true, 'threshold' => 30, 'recipients' => 'hr',       'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('shifts', fn (Blueprint $t) => $t->timestamp('missed_alerted_at')->nullable()->after('clock_out_nudged_at'));
        Schema::table('time_entries', function (Blueprint $t) {
            $t->timestamp('overtime_alerted_at')->nullable()->after('trail_points');
            $t->string('clock_in_photo_path', 255)->nullable()->after('overtime_alerted_at');
            $t->string('clock_out_photo_path', 255)->nullable()->after('clock_in_photo_path');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_clock_in_method_check');
            DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_clock_in_method_check CHECK (clock_in_method IN ('gps', 'override', 'device', 'kiosk'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_clock_in_method_check');
            DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_clock_in_method_check CHECK (clock_in_method IN ('gps', 'override', 'device'))");
        }
        Schema::table('time_entries', fn (Blueprint $t) => $t->dropColumn(['overtime_alerted_at', 'clock_in_photo_path', 'clock_out_photo_path']));
        Schema::table('shifts', fn (Blueprint $t) => $t->dropColumn('missed_alerted_at'));
        Schema::dropIfExists('automations');
    }
};
