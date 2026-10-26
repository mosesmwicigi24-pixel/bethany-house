<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Outlet;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\People\Leave;
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

/** Bethany People PR 4 — time off: days, balances, requests, decisions. */
class LeaveTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;
    private Outlet $other;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);
        $this->shop  = Outlet::factory()->create();
        $this->other = Outlet::factory()->create();
    }

    private function staff(array $permissions = [], ?Outlet $outlet = null, ?string $hired = '2024-01-01'): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        if ($permissions) {
            $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
            foreach ($permissions as $p) $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
            $u->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => ($outlet ?? $this->shop)->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        if ($hired) EmployeeProfile::factory()->create(['user_id' => $u->id, 'hire_date' => $hired]);
        return $u->fresh();
    }

    private function type(string $code): LeaveType
    {
        return LeaveType::where('code', $code)->firstOrFail();
    }

    public function test_days_count_working_days_and_skip_holidays_unless_calendar(): void
    {
        // Mon 2 Mar 2026 – Sun 8 Mar 2026: Mon–Sat = 6 working days; a holiday on the Wednesday makes 5.
        PublicHoliday::create(['date' => '2026-03-04', 'name' => 'Test Day', 'country' => 'KE']);
        $m = Leave::measure($this->type('annual'), '2026-03-02', '2026-03-08');
        $this->assertSame(5.0, $m['days']);
        $this->assertSame(['Test Day'], $m['holidays']);
        $this->assertSame(7.0, Leave::measure($this->type('maternity'), '2026-03-02', '2026-03-08')['days']);
        $this->assertSame(0.0, Leave::measure($this->type('annual'), '2026-03-08', '2026-03-08')['days'], 'a Sunday alone is no leave');
        config(['attendance.working_days' => [1, 2, 3, 4, 5]]);
        $this->assertSame(4.0, Leave::measure($this->type('annual'), '2026-03-02', '2026-03-08')['days']);
    }

    public function test_balances_accrue_monthly_or_front_loaded_and_prorate_the_hire_year(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $old = $this->staff([], null, '2024-01-01');
        $new = $this->staff([], null, '2026-04-10');
        $annual = $this->type('annual'); $sick = $this->type('sick');

        // Monthly: 21 × completed months / 12. Old hand: Jan 1 → Jul 15 = 6 months → 10.5.
        $this->assertSame(10.5, Leave::entitled($old, $annual, 2026));
        // New hire 10 Apr: 3 completed months → 5.25.
        $this->assertSame(5.25, Leave::entitled($new, $annual, 2026));
        // Last year, whole year.
        $this->assertSame(21.0, Leave::entitled($old, $annual, 2025));
        // Front-loaded sick: 14 for the old hand; new hire from April gets 9 months' worth = 10.5.
        $this->assertSame(14.0, Leave::entitled($old, $sick, 2026));
        $this->assertSame(10.5, Leave::entitled($new, $sick, 2026));
        // Unpaid tracks nothing.
        $this->assertNull(Leave::balance($old, $this->type('unpaid'), 2026)['available']);

        LeaveRequest::factory()->create(['user_id' => $old->id, 'leave_type_id' => $annual->id, 'starts_on' => '2026-02-02', 'ends_on' => '2026-02-04', 'days' => 3, 'status' => 'approved']);
        LeaveRequest::factory()->create(['user_id' => $old->id, 'leave_type_id' => $annual->id, 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-04', 'days' => 2, 'status' => 'pending']);
        LeaveRequest::factory()->create(['user_id' => $old->id, 'leave_type_id' => $annual->id, 'starts_on' => '2026-09-03', 'ends_on' => '2026-09-04', 'days' => 2, 'status' => 'declined']);
        \App\Models\LeaveAdjustment::create(['user_id' => $old->id, 'leave_type_id' => $annual->id, 'year' => 2026, 'days' => 4, 'reason' => 'carried over from 2025']);
        $b = Leave::balance($old, $annual, 2026);
        $this->assertSame(['entitled' => 10.5, 'used' => 3.0, 'requested' => 2.0, 'adjusted' => 4.0, 'available' => 11.5],
            ['entitled' => $b['entitled'], 'used' => $b['used'], 'requested' => $b['requested'], 'adjusted' => $b['adjusted'], 'available' => $b['available']]);
        Carbon::setTestNow();
    }

    public function test_request_goes_to_the_manager_and_colleagues_with_the_key_and_is_decided_once(): void
    {
        Carbon::setTestNow('2026-03-02 09:00:00');
        $boss = $this->staff([], $this->other);                    // reports_to, at another outlet, no key
        $keyholder = $this->staff(['leave.approve']);              // same outlet, holds the key
        $this->staff(['leave.approve'], $this->other);             // other outlet: not an approver
        $me = $this->staff();
        $me->update(['reports_to' => $boss->id]);
        $annual = $this->type('annual');

        Sanctum::actingAs($me);
        $this->assertEquals(3, $this->getJson('/api/v1/admin/me/leave/preview?leave_type_id=' . $annual->id . '&starts_on=2026-04-13&ends_on=2026-04-15')->json('days'));
        $r = $this->postJson('/api/v1/admin/me/leave', ['leave_type_id' => $annual->id, 'starts_on' => '2026-04-13', 'ends_on' => '2026-04-15', 'reason' => 'Family'])->assertStatus(201);
        $id = $r->json('request.id');
        $this->assertEquals(3, $r->json('request.days'));
        Notification::assertSentTo($boss, InAppNotification::class);
        Notification::assertSentTo($keyholder, InAppNotification::class);
        Notification::assertCount(2);
        // Overlap refused; past dates refused.
        $this->postJson('/api/v1/admin/me/leave', ['leave_type_id' => $annual->id, 'starts_on' => '2026-04-15', 'ends_on' => '2026-04-16'])->assertStatus(422);
        $this->postJson('/api/v1/admin/me/leave', ['leave_type_id' => $annual->id, 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-02'])->assertStatus(422);
        // Not enough balance: 2 months accrued = 3.5 days, 3 pending not deducted, asking 4 more in May.
        $this->postJson('/api/v1/admin/me/leave', ['leave_type_id' => $annual->id, 'starts_on' => '2026-05-04', 'ends_on' => '2026-05-07'])->assertStatus(422);
        // Nobody decides their own.
        $this->postJson("/api/v1/admin/leave/requests/{$id}/approve")->assertStatus(403);

        // A stranger with the key at another outlet cannot decide.
        Sanctum::actingAs($this->staff(['attendance.view_team', 'leave.approve'], $this->other));
        $this->postJson("/api/v1/admin/leave/requests/{$id}/approve")->assertStatus(403);

        // The manager (no key) sees it in the queue and approves; a published shift in the span is flagged.
        Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $me->id, 'date' => '2026-04-14']);
        $boss->syncRoles([]); $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum'); $role->givePermissionTo(Permission::findOrCreate('attendance.view_team', 'sanctum')); $boss->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($boss->fresh());
        $q = $this->getJson('/api/v1/admin/leave/requests?status=pending')->assertOk();
        $this->assertSame($id, $q->json('data.0.id'));
        $this->assertTrue($q->json('data.0.can_decide'));
        $this->assertSame('2026-04-14', $q->json('data.0.roster_clash.0.date'));
        $a = $this->postJson("/api/v1/admin/leave/requests/{$id}/approve", ['note' => 'Enjoy'])->assertOk();
        $this->assertSame('approved', $a->json('request.status'));
        $this->assertCount(1, $a->json('roster_clash'));
        Notification::assertSentTo($me, InAppNotification::class, fn ($n) => $n->title === 'Leave approved');
        $this->postJson("/api/v1/admin/leave/requests/{$id}/approve")->assertStatus(422);

        Sanctum::actingAs($me);
        $mine = $this->getJson('/api/v1/admin/me/leave?year=2026')->assertOk();
        $annualBal = collect($mine->json('balances'))->firstWhere('type.code', 'annual');
        $this->assertEquals(3, $annualBal['used']);
        $this->assertEquals(0.5, $annualBal['available']);
        Carbon::setTestNow();
    }

    public function test_cancel_rules_and_manager_entered_leave(): void
    {
        Carbon::setTestNow('2026-06-01 09:00:00');
        $me = $this->staff(); $mgr = $this->staff(['attendance.view_team', 'leave.approve']);
        $sick = $this->type('sick');

        // The manager records sick leave on my behalf (backdated): approved at once, I am told.
        Sanctum::actingAs($mgr);
        $r = $this->postJson('/api/v1/admin/leave/requests', ['user_id' => $me->id, 'leave_type_id' => $sick->id, 'starts_on' => '2026-05-27', 'ends_on' => '2026-05-28', 'reason' => 'Flu'])->assertStatus(201);
        $this->assertSame('approved', $r->json('request.status'));
        Notification::assertSentTo($me, InAppNotification::class, fn ($n) => $n->title === 'Leave recorded for you');

        // I cannot cancel approved leave that has started; I can cancel a future pending one.
        Sanctum::actingAs($me);
        $this->deleteJson('/api/v1/admin/me/leave/' . $r->json('request.id'))->assertStatus(422);
        $p = $this->postJson('/api/v1/admin/me/leave', ['leave_type_id' => $this->type('annual')->id, 'starts_on' => '2026-06-15', 'ends_on' => '2026-06-16'])->assertStatus(201);
        $this->deleteJson('/api/v1/admin/me/leave/' . $p->json('request.id'))->assertOk()->assertJsonPath('request.status', 'cancelled');
        // The manager cancels the sick leave: balance returns.
        Sanctum::actingAs($mgr);
        $this->deleteJson('/api/v1/admin/leave/requests/' . $r->json('request.id'), ['note' => 'Came back early'])->assertOk();
        $this->assertSame(14.0, Leave::balance($me->fresh(), $sick, 2026)['available']);
        // Decline path.
        Sanctum::actingAs($me);
        $d = $this->postJson('/api/v1/admin/me/leave', ['leave_type_id' => $this->type('annual')->id, 'starts_on' => '2026-07-06', 'ends_on' => '2026-07-07'])->assertStatus(201);
        Sanctum::actingAs($mgr);
        $this->postJson('/api/v1/admin/leave/requests/' . $d->json('request.id') . '/decline', ['note' => 'Peak week'])->assertOk()->assertJsonPath('request.status', 'declined');
        Notification::assertSentTo($me, InAppNotification::class, fn ($n) => $n->title === 'Leave declined');
        Carbon::setTestNow();
    }

    public function test_calendar_person_view_adjustments_and_types_are_gated(): void
    {
        $a = $this->staff(); $b = $this->staff([], $this->other);
        LeaveRequest::factory()->create(['user_id' => $a->id, 'starts_on' => now()->addDays(3)->toDateString(), 'ends_on' => now()->addDays(4)->toDateString(), 'status' => 'approved']);
        LeaveRequest::factory()->create(['user_id' => $b->id, 'starts_on' => now()->addDays(3)->toDateString(), 'ends_on' => now()->addDays(4)->toDateString(), 'status' => 'approved']);

        $viewer = $this->staff(['attendance.view_team', 'hr.view']);
        Sanctum::actingAs($viewer);
        $cal = $this->getJson('/api/v1/admin/leave/calendar?from=' . now()->toDateString() . '&to=' . now()->addDays(10)->toDateString())->assertOk();
        $this->assertSame([$a->id], collect($cal->json('data'))->pluck('user.id')->all());
        $this->getJson("/api/v1/admin/people/{$a->id}/leave")->assertOk()->assertJsonPath('can_adjust', false);
        $this->getJson("/api/v1/admin/people/{$b->id}/leave")->assertStatus(403);
        $this->postJson('/api/v1/admin/leave/adjustments', ['user_id' => $a->id, 'leave_type_id' => $this->type('annual')->id, 'year' => now()->year, 'days' => 2, 'reason' => 'carry-over'])->assertStatus(403);
        $this->putJson('/api/v1/admin/leave/types/' . $this->type('annual')->id, ['days_per_year' => 24])->assertStatus(403);

        Sanctum::actingAs($this->staff(['hr.view', 'hr.edit']));
        $this->postJson('/api/v1/admin/leave/adjustments', ['user_id' => $a->id, 'leave_type_id' => $this->type('annual')->id, 'year' => now()->year, 'days' => 2, 'reason' => 'carry-over'])->assertStatus(201);
        $this->postJson('/api/v1/admin/leave/adjustments', ['user_id' => $a->id, 'leave_type_id' => $this->type('annual')->id, 'year' => now()->year, 'days' => 0, 'reason' => 'nothing'])->assertStatus(422);
        $this->putJson('/api/v1/admin/leave/types/' . $this->type('annual')->id, ['days_per_year' => 24])->assertOk()->assertJsonPath('type.days_per_year', 24);
        $this->assertEquals(2, Leave::balance($a->fresh(), $this->type('annual'), now()->year)['adjusted']);

        // The roster marks the leave day.
        Sanctum::actingAs($this->staff(['attendance.view_team']));
        $week = now()->addDays(3)->startOfWeek()->toDateString();
        $w = $this->getJson('/api/v1/admin/roster?outlet_id=' . $this->shop->id . '&week=' . $week)->assertOk();
        $this->assertSame('approved', $w->json("leave.{$a->id}." . now()->addDays(3)->toDateString() . '.status'));
    }

    public function test_seeded_types_follow_the_statutory_floor(): void
    {
        Sanctum::actingAs($this->staff());
        $t = collect($this->getJson('/api/v1/admin/leave/types')->assertOk()->json('data'))->keyBy('code');
        $this->assertEquals(21, $t['annual']['days_per_year']);
        $this->assertSame('monthly', $t['annual']['accrual']);
        $this->assertTrue($t['maternity']['counts_calendar_days']);
        $this->assertEquals(90, $t['maternity']['days_per_year']);
        $this->assertNull($t['unpaid']['days_per_year']);
        $this->assertFalse($t['unpaid']['is_paid']);
    }
}
