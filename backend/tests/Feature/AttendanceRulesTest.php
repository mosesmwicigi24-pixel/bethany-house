<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\LocationPing;
use App\Models\Outlet;
use App\Models\PayGroup;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\TimesheetFixRequest;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\People\TimesheetEngine;
use App\Support\AttendanceRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Bethany People C1 — pay periods ending on the 10th, the attendance rules
 * store, clock-in rules, location on shift, fix requests, the requests
 * queue and the overview. Fixture: Bethany House at (-1.2921, 36.8219),
 * fence 50 m; leaving = further than 250 m for 15 minutes.
 */
class AttendanceRulesTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -1.2921;
    private const LNG = 36.8219;
    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create(['latitude' => self::LAT, 'longitude' => self::LNG, 'geofence_radius_meters' => 50, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $permissions = [], ?int $reportsTo = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff', 'reports_to' => $reportsTo]);
        if ($permissions) {
            $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
            foreach ($permissions as $p) $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
            $u->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => $this->outlet->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        EmployeeProfile::factory()->create(['user_id' => $u->id, 'hire_date' => '2024-01-01']);
        return $u->fresh();
    }

    /** Latitude $metres north of the outlet. */
    private function north(float $metres): float { return self::LAT + $metres / 111_320; }

    private function punch(array $extra = []): array
    {
        return ['outlet_id' => $this->outlet->id, 'latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 8] + $extra;
    }

    public function test_pay_periods_run_from_the_11th_to_the_10th(): void
    {
        $g = PayGroup::defaultGroup();
        $this->assertSame(10, $g->period_end_day, 'the migration seeds the default group with the 10th when nothing is locked');
        [$s, $e] = $g->periodBounds(Carbon::parse('2026-03-05'));
        $this->assertSame(['2026-02-11', '2026-03-10'], [$s->toDateString(), $e->toDateString()]);
        [$s, $e] = $g->periodBounds(Carbon::parse('2026-03-10'));
        $this->assertSame(['2026-02-11', '2026-03-10'], [$s->toDateString(), $e->toDateString()]);
        [$s, $e] = $g->periodBounds(Carbon::parse('2026-03-11'));
        $this->assertSame(['2026-03-11', '2026-04-10'], [$s->toDateString(), $e->toDateString()]);
        [$s, $e] = $g->periodBounds(Carbon::parse('2026-01-31'));
        $this->assertSame(['2026-01-11', '2026-02-10'], [$s->toDateString(), $e->toDateString()]);
        $g->update(['period_end_day' => null]);
        [$s, $e] = $g->fresh()->periodBounds(Carbon::parse('2026-03-05'));
        $this->assertSame(['2026-03-01', '2026-03-31'], [$s->toDateString(), $e->toDateString()]);
        // A manager sets it on the pay group; 29+ is refused because February has no such day.
        $hr = $this->staff(['attendance.view_team', 'hr.edit']);
        Sanctum::actingAs($hr);
        $this->putJson("/api/v1/admin/pay-groups/{$g->id}", ['period_end_day' => 10, 'pay_date_day' => 15, 'hours_by_weekday' => ['6' => 6]])->assertOk()->assertJsonPath('group.period_end_day', 10)->assertJsonPath('group.hours_by_weekday.6', 6);
        $this->putJson("/api/v1/admin/pay-groups/{$g->id}", ['period_end_day' => 31])->assertStatus(422);
    }

    public function test_rules_are_read_by_the_team_and_written_with_settings_edit(): void
    {
        $viewer = $this->staff(['attendance.view_team']);
        $admin = $this->staff(['attendance.view_team', 'settings.edit']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/admin/attendance/rules')->assertOk()->assertJsonPath('rules.location_mode', 'trail')->assertJsonPath('rules.leave_site_action', 'flag')->assertJsonPath('rules.trail_retention_days', 90);
        $this->putJson('/api/v1/admin/attendance/rules', ['daily_limit_hours' => 10])->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/attendance/rules', ['working_days' => [1, 2, 3, 4, 5], 'daily_limit_hours' => 10, 'clock_in_without_shift' => 'refuse'])->assertOk()->assertJsonPath('rules.daily_limit_hours', 10);
        $this->putJson('/api/v1/admin/attendance/rules', ['leave_site_action' => 'teleport'])->assertStatus(422);
        $this->assertSame([1, 2, 3, 4, 5], AttendanceRules::get('working_days'));
        $this->assertSame('refuse', AttendanceRules::get('clock_in_without_shift'));
    }

    public function test_clock_in_without_a_shift_is_flagged_refused_or_allowed_and_not_too_early(): void
    {
        Carbon::setTestNow('2026-03-02 09:00:00'); // Monday
        $u = $this->staff();
        Sanctum::actingAs($u);
        // Default: flag.
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertCreated()->assertJsonPath('entry.flags.0', 'no_shift');
        TimeEntry::query()->delete();
        AttendanceRules::save(['clock_in_without_shift' => 'refuse']);
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertStatus(422);
        AttendanceRules::save(['clock_in_without_shift' => 'allow']);
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertCreated()->assertJsonPath('entry.flags', []);
        TimeEntry::query()->delete();
        // With a 10:00 shift and a 30-minute rule: 09:00 is too early, 09:40 is fine, and there is no flag.
        AttendanceRules::save(['clock_in_without_shift' => 'flag', 'earliest_clock_in_minutes' => 30]);
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $u->id, 'date' => '2026-03-02', 'starts_at' => '10:00', 'ends_at' => '17:00']);
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertStatus(422)->assertJsonFragment(['earliest_at' => Carbon::parse('2026-03-02 09:30:00')->toIso8601String()]);
        Carbon::setTestNow('2026-03-02 09:40:00');
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertCreated()->assertJsonPath('entry.flags', []);
        $this->getJson('/api/v1/admin/time-clock/status')->assertOk()->assertJsonPath('today_shift.starts_at', '10:00')->assertJsonPath('rules.location_mode', 'trail');
    }

    public function test_a_location_free_fixed_device_punches_without_a_position(): void
    {
        AttendanceRules::save(['clock_in_without_shift' => 'allow']);
        $u = $this->staff();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/admin/time-clock/clock-in', ['outlet_id' => $this->outlet->id])->assertStatus(422);
        $key = 'shopcomputer0000000001';
        $deviceId = DB::table('devices')->insertGetId(['key_hash' => hash('sha256', $key), 'kind' => 'windows_pc', 'name' => 'Shop computer', 'location_free' => true, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        DB::table('device_user')->insert(['device_id' => $deviceId, 'user_id' => $u->id, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->withHeader('X-Device-Id', $key)->postJson('/api/v1/admin/time-clock/clock-in', ['outlet_id' => $this->outlet->id])->assertCreated()->assertJsonPath('entry.clock_in_method', 'device');
        $this->withHeader('X-Device-Id', $key)->postJson('/api/v1/admin/time-clock/clock-out', [])->assertOk();
        $this->assertSame('completed', TimeEntry::first()->status);
    }

    public function test_trail_is_kept_leaving_is_flagged_after_the_grace_and_coming_back_clears_it(): void
    {
        AttendanceRules::save(['clock_in_without_shift' => 'allow']);
        Carbon::setTestNow('2026-03-02 09:00:00');
        $u = $this->staff();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertCreated();
        $at = fn (string $t) => Carbon::parse("2026-03-02 {$t}")->toIso8601String();
        $pt = fn (float $north, string $t, int $acc = 10) => ['latitude' => $this->north($north), 'longitude' => self::LNG, 'accuracy' => $acc, 'recorded_at' => $at($t)];

        // Inside and near: kept, not leaving.
        Carbon::setTestNow('2026-03-02 09:11:00');
        $this->postJson('/api/v1/admin/time-clock/trail', ['points' => [$pt(5, '09:05'), $pt(120, '09:10')]])->assertOk()->assertJsonPath('left_site_at', null)->assertJsonPath('tracking', true);
        $this->assertSame(2, LocationPing::count());
        // 400 m out at 09:20: timer starts and the person is told.
        Carbon::setTestNow('2026-03-02 09:21:00');
        $this->postJson('/api/v1/admin/time-clock/trail', ['points' => [$pt(400, '09:20')]])->assertOk()->assertJsonPath('left_site_at', $at('09:20'));
        Notification::assertSentTo($u, InAppNotification::class, fn ($n) => $n->title === 'Still clocked in?');
        // Back inside at 09:30: cleared.
        Carbon::setTestNow('2026-03-02 09:31:00');
        $this->postJson('/api/v1/admin/time-clock/trail', ['points' => [$pt(10, '09:30')]])->assertOk()->assertJsonPath('left_site_at', null);
        // A wide fix far away is not evidence of leaving.
        $this->postJson('/api/v1/admin/time-clock/trail', ['points' => [$pt(400, '09:31', 900)]])->assertOk()->assertJsonPath('left_site_at', null);
        // Out at 09:40, still out at 09:56: flagged (rule: flag), still clocked in.
        Carbon::setTestNow('2026-03-02 09:57:00');
        $this->postJson('/api/v1/admin/time-clock/trail', ['points' => [$pt(400, '09:40'), $pt(500, '09:56')]])->assertOk()->assertJsonPath('closed', false);
        $e = TimeEntry::first();
        $this->assertSame('active', $e->status);
        $this->assertContains('left_site', $e->flags);
        $this->assertSame(7, $e->trail_points);

        // Rule clock_out: a fresh entry is closed at the moment they left.
        $e->forceFill(['status' => 'completed', 'clock_out_at' => now(), 'worked_minutes' => 50])->save();
        AttendanceRules::save(['leave_site_action' => 'clock_out']);
        Carbon::setTestNow('2026-03-02 13:00:00');
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertCreated();
        Carbon::setTestNow('2026-03-02 13:30:00');
        $this->postJson('/api/v1/admin/time-clock/trail', ['points' => [$pt(400, '13:10'), $pt(400, '13:26')]])->assertOk()->assertJsonPath('closed', true)->assertJsonPath('tracking', false);
        $e2 = TimeEntry::orderByDesc('id')->first();
        $this->assertSame('flagged', $e2->status);
        $this->assertSame('13:10', $e2->clock_out_at->format('H:i'));
        $this->assertContains('left_site', $e2->flags);
        $this->assertContains('auto_clock_out', $e2->flags);

        // Mode off: nothing kept. Mocked points flag the entry.
        AttendanceRules::save(['location_mode' => 'off']);
        $before = LocationPing::count();
        $this->postJson('/api/v1/admin/time-clock/trail', ['points' => [$pt(5, '13:40')]])->assertOk()->assertJsonPath('tracking', false);
        $this->assertSame($before, LocationPing::count());

        // The manager sees the trail; retention deletes old points.
        $mgr = $this->staff(['attendance.view_team']);
        Sanctum::actingAs($mgr);
        $this->getJson("/api/v1/admin/attendance/entries/{$e->id}/trail")->assertOk()->assertJsonCount(7, 'points')->assertJsonPath('outlet.radius', 50);
        LocationPing::query()->update(['recorded_at' => now()->subDays(100)]);
        $this->artisan('attendance:prune-trails')->assertExitCode(0);
        $this->assertSame(0, LocationPing::count());
    }

    public function test_weekday_hours_unpaid_lunch_and_over_under_marks_on_the_timesheet(): void
    {
        $g = PayGroup::defaultGroup();
        $g->update(['period_end_day' => null, 'hours_by_weekday' => ['6' => 6], 'overtime_after_daily_hours' => 8, 'unpaid_break_minutes' => 60, 'unpaid_break_after_hours' => 6]);
        AttendanceRules::save(['over_scheduled_minutes' => 60, 'under_scheduled_minutes' => 30]);
        Carbon::setTestNow('2026-03-20 12:00:00');
        $u = $this->staff();
        $mk = function (string $date, int $minutes, int $breaks = 0) use ($u) {
            $in = Carbon::parse("$date 08:00:00");
            TimeEntry::factory()->create(['user_id' => $u->id, 'outlet_id' => $this->outlet->id, 'clock_in_at' => $in, 'clock_out_at' => $in->copy()->addMinutes($minutes + $breaks), 'worked_minutes' => $minutes, 'total_break_minutes' => $breaks, 'status' => 'completed']);
        };
        $mk('2026-03-07', 8 * 60, 60);   // Saturday, 8 h with a 60-min break taken: 6 h regular + 2 h overtime, nothing more deducted
        $mk('2026-03-09', 9 * 60);       // Monday, 9 h, no break taken: lunch 60 comes off → 8 h regular, 0 OT
        $mk('2026-03-10', 5 * 60);       // Tuesday, 5 h: too short for the lunch rule
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $u->id, 'date' => '2026-03-09', 'starts_at' => '08:00', 'ends_at' => '14:00']); // rostered 6 h, worked 8 → over
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $u->id, 'date' => '2026-03-10', 'starts_at' => '08:00', 'ends_at' => '16:00']); // rostered 8 h, worked 5 → under
        $c = TimesheetEngine::compute($u, $g->periodFor(Carbon::parse('2026-03-10')));
        $days = collect($c['days']);
        $sat = $days->firstWhere('date', '2026-03-07');
        $this->assertSame([360, 120, 0], [$sat['regular_minutes'], $sat['overtime_minutes'], $sat['unpaid_break_minutes']]);
        $mon = $days->firstWhere('date', '2026-03-09');
        $this->assertSame([480, 0, 60, 'over', 120], [$mon['regular_minutes'], $mon['overtime_minutes'], $mon['unpaid_break_minutes'], $mon['highlight'], $mon['vs_scheduled_minutes']]);
        $tue = $days->firstWhere('date', '2026-03-10');
        $this->assertSame([300, 0, 'under'], [$tue['regular_minutes'], $tue['unpaid_break_minutes'], $tue['highlight']]);
    }

    public function test_auto_clock_out_after_a_maximum_length_and_the_daily_limit_alert(): void
    {
        AttendanceRules::save(['auto_clock_out_time' => '22:00', 'auto_clock_out_after_hours' => 12, 'daily_limit_hours' => 11]);
        $boss = $this->staff(['attendance.view_team', 'attendance.manage']);
        $u = $this->staff([], $boss->id);
        Carbon::setTestNow('2026-03-02 19:30:00');
        $e = TimeEntry::factory()->create(['user_id' => $u->id, 'outlet_id' => $this->outlet->id, 'clock_in_at' => Carbon::parse('2026-03-02 08:00:00'), 'clock_out_at' => null, 'worked_minutes' => null, 'status' => 'active']);
        $this->artisan('attendance:auto-clock-out')->assertExitCode(0); // 11 h 30 in: limit alert, not closed
        $this->assertSame('active', $e->fresh()->status);
        $this->assertContains('daily_limit', $e->fresh()->flags);
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_contains($n->title, 'worked 11 h'));
        Carbon::setTestNow('2026-03-02 20:10:00');
        $this->artisan('attendance:auto-clock-out')->assertExitCode(0); // 12 h after 08:00 is 20:00, before the 22:00 cut
        $e = $e->fresh();
        $this->assertSame('flagged', $e->status);
        $this->assertSame('20:00', $e->clock_out_at->format('H:i'));
        Notification::assertCount(1);
    }

    public function test_roster_nudges_to_clock_in_and_out(): void
    {
        $u = $this->staff();
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $u->id, 'date' => '2026-03-02', 'starts_at' => '08:00', 'ends_at' => '17:00']);
        Carbon::setTestNow('2026-03-02 08:03:00');
        $this->artisan('roster:remind')->assertExitCode(0);
        Notification::assertNothingSentTo($u);
        Carbon::setTestNow('2026-03-02 08:07:00');
        $this->artisan('roster:remind')->assertExitCode(0);
        Notification::assertSentTo($u, InAppNotification::class, fn ($n) => $n->title === 'Not clocked in yet');
        $this->artisan('roster:remind')->assertExitCode(0); // once only
        Notification::assertCount(1);
        TimeEntry::factory()->create(['user_id' => $u->id, 'outlet_id' => $this->outlet->id, 'clock_in_at' => Carbon::parse('2026-03-02 08:10:00'), 'clock_out_at' => null, 'worked_minutes' => null, 'status' => 'active']);
        Carbon::setTestNow('2026-03-02 17:02:00');
        $this->artisan('roster:remind')->assertExitCode(0);
        Notification::assertSentTo($u, InAppNotification::class, fn ($n) => $n->title === 'Still clocked in');
    }

    public function test_fix_requests_the_queue_and_the_overview(): void
    {
        Carbon::setTestNow('2026-03-02 10:00:00'); // Monday
        $boss = $this->staff(['attendance.view_team', 'attendance.manage', 'leave.approve', 'roster.manage']);
        $u = $this->staff([], $boss->id);
        $late = $this->staff([], $boss->id);
        $absentee = $this->staff([], $boss->id);
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $late->id, 'date' => '2026-03-02', 'starts_at' => '09:00', 'ends_at' => '17:00']);
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $absentee->id, 'date' => '2026-03-02', 'starts_at' => '06:00', 'ends_at' => '09:30']);
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => null, 'date' => '2026-03-04']);
        TimeEntry::factory()->create(['user_id' => $u->id, 'outlet_id' => $this->outlet->id, 'clock_in_at' => Carbon::parse('2026-03-02 08:00:00'), 'clock_out_at' => null, 'worked_minutes' => null, 'status' => 'active']);

        Sanctum::actingAs($u);
        $this->postJson('/api/v1/admin/me/timesheet/fixes', ['date' => '2026-02-27', 'message' => 'I forgot to clock out; I left at 17:10.'])->assertCreated();
        $this->postJson('/api/v1/admin/me/timesheet/fixes', ['date' => '2026-02-27', 'message' => 'again'])->assertStatus(422);
        $this->postJson('/api/v1/admin/me/timesheet/fixes', ['date' => '2026-03-09', 'message' => 'future'])->assertStatus(422);
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_contains($n->title, 'fix my hours'));
        $this->getJson('/api/v1/admin/me/timesheet/fixes')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($boss);
        $q = $this->getJson('/api/v1/admin/people/requests')->assertOk();
        $this->assertSame(1, $q->json('counts.fix'));
        $this->assertSame('fix', $q->json('data.0.kind'));
        $this->getJson('/api/v1/admin/people/requests/count')->assertOk()->assertJsonPath('count', 1);
        $id = TimesheetFixRequest::value('id');
        $this->postJson("/api/v1/admin/people/requests/fixes/{$id}/decline", [])->assertStatus(422); // a decline needs a note
        $this->postJson("/api/v1/admin/people/requests/fixes/{$id}/approve", ['note' => 'Set to 17:10.'])->assertOk()->assertJsonPath('fix.status', 'approved');
        Notification::assertSentTo($u, InAppNotification::class, fn ($n) => str_contains($n->title, 'corrected'));
        $this->getJson('/api/v1/admin/people/requests/count')->assertOk()->assertJsonPath('count', 0);

        $o = $this->getJson('/api/v1/admin/people/overview')->assertOk();
        $this->assertSame(1, $o->json('now.in_count'));
        $this->assertSame($late->id, $o->json('now.late.0.person.id'));
        $this->assertSame($absentee->id, $o->json('now.absent.0.person.id'));
        $this->assertSame(1, $o->json('week.open_shifts'));
        $this->assertSame('2026-03-10', $o->json('period.ends_on'));
        $this->assertSame(0, $o->json('requests.count'));
    }
}
