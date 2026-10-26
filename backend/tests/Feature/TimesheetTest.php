<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Outlet;
use App\Models\PayGroup;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\People\TimesheetEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Bethany People PR 5 — timesheets, from an independent fixture.
 * The month under test is March 2026: Mon 2 Mar … Tue 31 Mar; Sunday 8 Mar
 * is a rest day; a test holiday is placed on Wed 4 Mar.
 */
class TimesheetTest extends TestCase
{
    use RefreshDatabase;
    use StepsUp;

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);
        // These cases are written against calendar months; C1 seeds the default group with period_end_day = 10 (C1 suite covers that).
        \App\Models\PayGroup::query()->update(['period_end_day' => null]);
        Carbon::setTestNow('2026-03-20 12:00:00');
        $this->shop = Outlet::factory()->create();
        PublicHoliday::create(['date' => '2026-03-04', 'name' => 'Test Day', 'country' => 'KE']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $permissions = []): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        if ($permissions) {
            $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
            foreach ($permissions as $p) $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
            $u->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => $this->shop->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        EmployeeProfile::factory()->create(['user_id' => $u->id, 'hire_date' => '2024-01-01']);
        return $u->fresh();
    }

    /** A completed punch of $minutes on $date starting 08:00. */
    private function punch(User $u, string $date, int $minutes, string $status = 'completed', array $flags = []): TimeEntry
    {
        $in = Carbon::parse("$date 08:00:00");
        return TimeEntry::factory()->create([
            'user_id' => $u->id, 'outlet_id' => $this->shop->id, 'clock_in_at' => $in, 'clock_out_at' => $in->copy()->addMinutes($minutes),
            'worked_minutes' => $minutes, 'total_break_minutes' => 0, 'status' => $status, 'flags' => $flags ?: null,
        ]);
    }

    public function test_engine_classifies_regular_overtime_rest_and_holiday_minutes_and_leave(): void
    {
        $u = $this->staff();
        $period = PayGroup::defaultGroup()->periodFor(Carbon::parse('2026-03-10'));
        $this->assertSame('2026-03-01', $period->starts_on->toDateString());
        $this->assertSame('2026-03-31', $period->ends_on->toDateString());

        $this->punch($u, '2026-03-02', 9 * 60 + 30);        // Mon: 8h regular + 1h30 OT
        $this->punch($u, '2026-03-03', 7 * 60);             // Tue: 7h regular
        $this->punch($u, '2026-03-04', 5 * 60);             // Wed holiday: 5h holiday
        $this->punch($u, '2026-03-08', 4 * 60);             // Sun rest: 4h rest
        LeaveRequest::factory()->create(['user_id' => $u->id, 'leave_type_id' => LeaveType::where('code', 'annual')->value('id'), 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-10', 'days' => 2, 'status' => 'approved']);
        LeaveRequest::factory()->create(['user_id' => $u->id, 'leave_type_id' => LeaveType::where('code', 'unpaid')->value('id'), 'starts_on' => '2026-03-11', 'ends_on' => '2026-03-11', 'days' => 1, 'status' => 'approved']);
        Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $u->id, 'date' => '2026-03-12']); // rostered, no punch → absence
        Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $u->id, 'date' => '2026-03-25']); // future: not an absence yet

        $c = TimesheetEngine::compute($u, $period);
        $this->assertSame(['regular_minutes' => 900, 'overtime_minutes' => 90, 'rest_day_minutes' => 240, 'holiday_minutes' => 300,
            'worked_minutes' => 1530, 'paid_leave_days' => 2.0, 'unpaid_leave_days' => 1.0, 'days_worked' => 4], $c['totals']);
        $this->assertSame([], $c['flags']);
        $this->assertSame(['2026-03-12'], array_column($c['absences'], 'date'));
        $day = collect($c['days'])->firstWhere('date', '2026-03-02');
        $this->assertSame(480, $day['regular_minutes']);
        $this->assertSame(90, $day['overtime_minutes']);
        $this->assertSame('Annual leave', collect($c['days'])->firstWhere('date', '2026-03-09')['leave']);
        $this->assertSame('Test Day', collect($c['days'])->firstWhere('date', '2026-03-04')['holiday']);
    }

    public function test_weekly_threshold_rounding_and_no_daily_cap(): void
    {
        $g = PayGroup::create(['name' => 'Weekly 45', 'frequency' => 'weekly', 'overtime_after_daily_hours' => null, 'overtime_after_weekly_hours' => 45, 'round_to_minutes' => 15]);
        $u = $this->staff();
        $u->employeeProfile->update(['pay_group_id' => $g->id]);
        $period = $g->periodFor(Carbon::parse('2026-03-04'));
        $this->assertSame('2026-03-02', $period->starts_on->toDateString());
        $this->assertSame('2026-03-08', $period->ends_on->toDateString());
        foreach (['2026-03-02', '2026-03-03', '2026-03-05', '2026-03-06', '2026-03-07'] as $d) $this->punch($u, $d, 9 * 60 + 8); // 9h08 → rounds to 9h15
        $c = TimesheetEngine::compute($u->fresh(), $period);
        // 5 × 9h15 = 46h15 = 2775 min; weekly cap 45h = 2700 → 75 overtime.
        $this->assertSame(2700, $c['totals']['regular_minutes']);
        $this->assertSame(75, $c['totals']['overtime_minutes']);
    }

    public function test_flags_block_approval_and_approval_snapshots_the_figures(): void
    {
        $u = $this->staff(); $m = $this->staff(['attendance.view_team', 'attendance.manage']);
        $this->punch($u, '2026-03-02', 8 * 60);
        $open = $this->punch($u, '2026-03-03', 0, 'active');
        Sanctum::actingAs($m);
        $this->postJson("/api/v1/admin/timesheets/person/{$u->id}/approve", ['date' => '2026-03-10'])->assertStatus(422);
        $open->update(['status' => 'completed', 'clock_out_at' => Carbon::parse('2026-03-03 16:00'), 'worked_minutes' => 480]);

        $r = $this->postJson("/api/v1/admin/timesheets/person/{$u->id}/approve", ['date' => '2026-03-10'])->assertOk();
        $this->assertSame('approved', $r->json('status'));
        $this->assertSame(960, $r->json('totals.regular_minutes'));
        $this->assertTrue($r->json('snapshot'));
        // A punch corrected after approval does not move the approved figure.
        TimeEntry::where('user_id', $u->id)->update(['worked_minutes' => 60]);
        $this->assertSame(960, $this->getJson("/api/v1/admin/timesheets/person/{$u->id}?date=2026-03-10")->json('totals.regular_minutes'));
        $this->postJson("/api/v1/admin/timesheets/person/{$u->id}/approve", ['date' => '2026-03-10'])->assertStatus(422);
        // Send back clears the snapshot; the live figure shows.
        $this->postJson("/api/v1/admin/timesheets/person/{$u->id}/send-back", ['date' => '2026-03-10', 'note' => 'Check Tuesday'])->assertOk()->assertJsonPath('status', 'sent_back');
        $this->assertSame(120, $this->getJson("/api/v1/admin/timesheets/person/{$u->id}?date=2026-03-10")->json('totals.regular_minutes'));
        // Nobody approves their own.
        Sanctum::actingAs($m);
        $this->postJson("/api/v1/admin/timesheets/person/{$m->id}/approve", ['date' => '2026-03-10'])->assertStatus(403);
    }

    public function test_index_scope_submit_lock_unlock_and_export(): void
    {
        $a = $this->staff(); $b = $this->staff();
        $this->punch($a, '2026-03-02', 8 * 60);
        $m = $this->staff(['attendance.view_team', 'attendance.manage']);
        $fin = $this->staff(['attendance.view_team', 'timesheets.lock']);

        Sanctum::actingAs($a);
        $mine = $this->getJson('/api/v1/admin/me/timesheet?date=2026-03-10')->assertOk();
        $this->assertSame(480, $mine->json('totals.regular_minutes'));
        $this->assertTrue($mine->json('can_submit'));
        $this->postJson('/api/v1/admin/me/timesheet/submit', ['date' => '2026-03-10', 'note' => 'All good'])->assertOk()->assertJsonPath('status', 'submitted');
        $this->getJson('/api/v1/admin/timesheets?date=2026-03-10')->assertStatus(403);

        Sanctum::actingAs($m);
        $idx = $this->getJson('/api/v1/admin/timesheets?date=2026-03-10')->assertOk();
        $this->assertSame(1, $idx->json('summary.submitted'));
        $this->assertTrue($idx->json('can_manage'));
        $this->assertFalse($idx->json('can_lock'));
        $periodId = $idx->json('period.id');

        // Lock refuses while sheets are unapproved; force locks anyway.
        Sanctum::actingAs($fin);
        $this->postJson("/api/v1/admin/pay-periods/{$periodId}/lock")->assertStatus(422);
        Sanctum::actingAs($m);
        foreach ([$a, $b, $m, $fin] as $p) { if ($p->id !== $m->id) $this->postJson("/api/v1/admin/timesheets/person/{$p->id}/approve", ['date' => '2026-03-10'])->assertOk(); }
        Sanctum::actingAs($fin);
        $this->postJson("/api/v1/admin/timesheets/person/{$m->id}/approve", ['date' => '2026-03-10'])->assertStatus(403); // no attendance.manage
        $this->postJson("/api/v1/admin/pay-periods/{$periodId}/lock", ['force' => true])->assertOk()->assertJsonPath('period.status', 'locked');
        Sanctum::actingAs($m);
        $this->postJson("/api/v1/admin/timesheets/person/{$a->id}/send-back", ['date' => '2026-03-10', 'note' => 'x'])->assertStatus(422);
        Sanctum::actingAs($a);
        $this->postJson('/api/v1/admin/me/timesheet/submit', ['date' => '2026-03-10'])->assertStatus(422);

        // Export needs timesheets.lock and a step-up; the CSV carries the approved hours.
        Sanctum::actingAs($m);
        $this->get("/api/v1/admin/timesheets/export?pay_period_id={$periodId}")->assertStatus(403);
        Sanctum::actingAs($fin);
        $this->get("/api/v1/admin/timesheets/export?pay_period_id={$periodId}")->assertStatus(403);
        $this->stepUp($fin);
        $csv = $this->get("/api/v1/admin/timesheets/export?pay_period_id={$periodId}")->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString("{$a->first_name} {$a->last_name}", $csv->getContent());
        $this->assertStringContainsString(',8.00,0.00,', $csv->getContent());

        // Unlock: owner only, with a reason.
        $this->postJson("/api/v1/admin/pay-periods/{$periodId}/unlock", ['reason' => 'late correction'])->assertStatus(403);
        $owner = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $owner->assignRole(Role::findByName('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($owner->fresh());
        $this->stepUp($owner);
        $this->postJson("/api/v1/admin/pay-periods/{$periodId}/unlock", ['reason' => 'late correction'])->assertOk()->assertJsonPath('period.status', 'open');
    }

    public function test_pay_groups_are_managed_by_hr_edit_and_one_is_default(): void
    {
        Sanctum::actingAs($this->staff(['attendance.view_team']));
        $this->assertCount(1, $this->getJson('/api/v1/admin/pay-groups')->assertOk()->json('data'));
        $this->postJson('/api/v1/admin/pay-groups', ['name' => 'Casuals', 'frequency' => 'weekly'])->assertStatus(403);

        Sanctum::actingAs($this->staff(['attendance.view_team', 'hr.edit', 'hr.view']));
        $g = $this->postJson('/api/v1/admin/pay-groups', ['name' => 'Casuals', 'frequency' => 'weekly', 'is_default' => true, 'round_to_minutes' => 15])->assertStatus(201)->json('group');
        $this->assertTrue($g['is_default']);
        $this->assertFalse(PayGroup::where('name', 'Monthly staff')->first()->is_default);
        $this->putJson("/api/v1/admin/pay-groups/{$g['id']}", ['round_to_minutes' => 7])->assertStatus(422);
        $this->putJson("/api/v1/admin/pay-groups/{$g['id']}", ['overtime_multiplier' => 1.75])->assertOk()->assertJsonPath('group.overtime_multiplier', 1.75);

        // The employee file carries the pay group.
        $p = $this->staff();
        $this->putJson("/api/v1/admin/people/{$p->id}", ['pay_group_id' => $g['id']])->assertOk()->assertJsonPath('person.profile.pay_group_id', $g['id']);
    }
}
