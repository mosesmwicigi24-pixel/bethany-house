<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\TimeEntry;
use App\Models\User;
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
 * Bethany People PR 1 — the phone punch rules, from an independent fixture.
 *
 * Bethany House sits at (-1.2921, 36.8219) with a 50 m zone. One degree of
 * latitude is ~111,320 m, so 0.0001° ≈ 11.1 m north; distances below are
 * built from that, not from the haversine under test.
 */
class TimeClockPhoneTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -1.2921;
    private const LNG = 36.8219;
    private const M_PER_DEG = 111_320;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create([
            'latitude' => self::LAT, 'longitude' => self::LNG, 'geofence_radius_meters' => 50, 'is_active' => true,
        ]);
    }

    private function staff(array $permissions = []): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        if ($permissions) {
            $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
            foreach ($permissions as $p) {
                $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
            }
            $u->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => $this->outlet->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        return $u->fresh();
    }

    /** Latitude $metres north of the outlet. */
    private function latAt(float $metres): float
    {
        return self::LAT + $metres / self::M_PER_DEG;
    }

    private function punch(array $overrides = []): array
    {
        return array_merge(['outlet_id' => $this->outlet->id, 'latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 8], $overrides);
    }

    public function test_inside_the_zone_is_accepted_clean(): void
    {
        Sanctum::actingAs($this->staff());
        $r = $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['latitude' => $this->latAt(30)]));
        $r->assertStatus(201)->assertJsonPath('entry.flags', [])->assertJsonPath('entry.status', 'active');
        $this->assertEqualsWithDelta(30, (float) $r->json('entry.clock_in_distance_meters'), 1.0);
        $this->assertSame(8.0, (float) $r->json('entry.clock_in_accuracy_meters'));
    }

    public function test_outside_the_zone_is_refused_for_staff(): void
    {
        Sanctum::actingAs($this->staff());
        $r = $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['latitude' => $this->latAt(120)]));
        $r->assertStatus(422)->assertJsonPath('requires_override', true)->assertJsonPath('allowed_radius_meters', 50);
        $this->assertSame(0, TimeEntry::count());
    }

    public function test_just_outside_within_phone_uncertainty_is_accepted_and_flagged_edge(): void
    {
        Sanctum::actingAs($this->staff());
        // 70 m away with 25 m accuracy: 70 <= 50 + min(25, 50) → edge.
        $r = $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['latitude' => $this->latAt(70), 'accuracy' => 25]));
        $r->assertStatus(201)->assertJsonPath('entry.flags', ['edge']);

        // The allowance is capped at the radius: 110 m with a claimed 500 m accuracy is still outside.
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['latitude' => $this->latAt(110), 'accuracy' => 500]))
            ->assertStatus(422);
    }

    public function test_imprecise_fix_inside_is_accepted_and_flagged(): void
    {
        Sanctum::actingAs($this->staff());
        $r = $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['accuracy' => 80]));
        $r->assertStatus(201)->assertJsonPath('entry.flags', ['accuracy_low']);
    }

    public function test_mock_location_is_flagged_not_refused(): void
    {
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['mock_location' => true]))
            ->assertStatus(201)->assertJsonPath('entry.flags', ['mock_location']);
    }

    public function test_late_punch_keeps_the_phone_time_and_is_flagged(): void
    {
        Sanctum::actingAs($this->staff());
        $sent = now()->subMinutes(40);
        $r = $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['recorded_at' => $sent->toIso8601String()]));
        $r->assertStatus(201)->assertJsonPath('entry.flags', ['sent_late']);
        $entry = TimeEntry::first();
        $this->assertEqualsWithDelta($sent->timestamp, $entry->clock_in_at->timestamp, 2);
        $this->assertEqualsWithDelta($sent->timestamp, $entry->clock_in_recorded_at->timestamp, 2);

        // A phone time in the future is not believed: the punch counts from now.
        $entry->delete();
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['recorded_at' => now()->addHours(3)->toIso8601String()]))
            ->assertStatus(201);
        $this->assertEqualsWithDelta(now()->timestamp, TimeEntry::first()->clock_in_at->timestamp, 2);
    }

    public function test_a_punch_within_the_sync_window_is_not_late(): void
    {
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['recorded_at' => now()->subMinutes(2)->toIso8601String()]))
            ->assertStatus(201)->assertJsonPath('entry.flags', []);
    }

    public function test_clock_out_is_never_refused_by_distance_and_closes_flagged_when_flags_exist(): void
    {
        $user = $this->staff();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch(['accuracy' => 80]))->assertStatus(201);

        $r = $this->postJson('/api/v1/admin/time-clock/clock-out', ['latitude' => $this->latAt(5000), 'longitude' => self::LNG, 'accuracy' => 10]);
        $r->assertOk()->assertJsonPath('entry.status', 'flagged');
        $entry = TimeEntry::first();
        $this->assertSame(['accuracy_low'], $entry->flags);
        $this->assertStringContainsString('less precise', $entry->flagged_reason);

        // A clean shift closes completed.
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertStatus(201);
        $this->postJson('/api/v1/admin/time-clock/clock-out', ['latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 10])
            ->assertOk()->assertJsonPath('entry.status', 'completed');
    }

    public function test_double_tap_cannot_open_two_shifts(): void
    {
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertStatus(201);
        $this->postJson('/api/v1/admin/time-clock/clock-in', $this->punch())->assertStatus(422);
        $this->assertSame(1, TimeEntry::count());
    }

    public function test_status_carries_week_total_last_punch_and_rules(): void
    {
        $user = $this->staff();
        TimeEntry::factory()->completed(480)->create(['user_id' => $user->id, 'outlet_id' => $this->outlet->id,
            'clock_in_at' => now()->startOfWeek()->addHours(8), 'clock_out_at' => now()->startOfWeek()->addHours(16)]);
        TimeEntry::factory()->completed(120)->create(['user_id' => $user->id, 'outlet_id' => $this->outlet->id,
            'clock_in_at' => now()->startOfWeek()->subDays(2), 'clock_out_at' => now()->startOfWeek()->subDays(2)->addHours(2)]);
        Sanctum::actingAs($user);
        $r = $this->getJson('/api/v1/admin/time-clock/status')->assertOk();
        $this->assertSame(480, $r->json('week_worked_minutes'));
        $this->assertNotNull($r->json('last_entry'));
        $this->assertSame('22:00', $r->json('rules.auto_clock_out_time'));
        $this->assertSame(50, $r->json('outlets.0.geofence_radius_meters'));
    }

    public function test_auto_clock_out_closes_at_the_cutoff_and_flags(): void
    {
        config(['attendance.auto_clock_out_time' => '22:00']);
        $user = $this->staff();
        $yesterday = now()->subDay()->setTime(8, 0);
        $e = TimeEntry::factory()->create(['user_id' => $user->id, 'outlet_id' => $this->outlet->id, 'clock_in_at' => $yesterday,
            'breaks' => [['started_at' => $yesterday->copy()->addHours(4)->toIso8601String(), 'ended_at' => null]]]);
        // A shift that began today before the cut-off, cut-off not yet reached: untouched.
        $fresh = TimeEntry::factory()->create(['user_id' => $this->staff()->id, 'outlet_id' => $this->outlet->id,
            'clock_in_at' => now()->subMinutes(30)]);

        Artisan::call('attendance:auto-clock-out', ['--dry-run' => true]);
        $this->assertSame('active', $e->fresh()->status);

        Artisan::call('attendance:auto-clock-out');
        $e->refresh();
        $this->assertSame('flagged', $e->status);
        $this->assertSame(['auto_clock_out'], $e->flags);
        $this->assertSame('22:00', $e->clock_out_at->format('H:i'));
        $this->assertSame($yesterday->toDateString(), $e->clock_out_at->toDateString());
        // 08:00 → 22:00 is 840 min; the open break closed at the cut-off: 12:00 → 22:00 = 600 off.
        $this->assertSame(600, $e->total_break_minutes);
        $this->assertSame(240, $e->worked_minutes);
        $this->assertNotNull($e->auto_closed_at);
        if (now()->format('H:i') < '22:00') {
            $this->assertSame('active', $fresh->fresh()->status);
        }

        // Idempotent.
        Artisan::call('attendance:auto-clock-out');
        $this->assertSame(1, TimeEntry::where('status', 'flagged')->count());
    }

    public function test_live_board_is_scoped_to_managed_outlets(): void
    {
        $other = Outlet::factory()->create(['latitude' => 0, 'longitude' => 0]);
        $a = $this->staff();
        $b = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        TimeEntry::factory()->create(['user_id' => $a->id, 'outlet_id' => $this->outlet->id, 'flags' => ['edge']]);
        TimeEntry::factory()->create(['user_id' => $b->id, 'outlet_id' => $other->id]);

        $manager = $this->staff(['attendance.view_team']);
        Sanctum::actingAs($manager);
        $r = $this->getJson('/api/v1/admin/attendance/live')->assertOk();
        $this->assertSame(1, $r->json('count'));
        $this->assertSame(1, $r->json('flagged'));
        $this->assertSame($a->id, $r->json('data.0.user.id'));

        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/admin/attendance/live')->assertStatus(403);

        $all = $this->staff(['attendance.view_team', 'outlets.all_access']);
        Sanctum::actingAs($all);
        $this->assertSame(2, $this->getJson('/api/v1/admin/attendance/live')->json('count'));
    }

    public function test_manager_marking_completed_clears_the_flags(): void
    {
        $user = $this->staff();
        $e = TimeEntry::factory()->completed()->create(['user_id' => $user->id, 'outlet_id' => $this->outlet->id,
            'status' => 'flagged', 'flags' => ['edge'], 'flagged_reason' => 'x']);
        Sanctum::actingAs($this->staff(['attendance.view_team', 'attendance.manage']));
        $this->putJson("/api/v1/admin/attendance/entries/{$e->id}", ['status' => 'completed'])->assertOk();
        $e->refresh();
        $this->assertSame('completed', $e->status);
        $this->assertNull($e->flags);
        $this->assertNull($e->flagged_reason);
    }

    public function test_new_outlets_default_to_a_50_m_zone(): void
    {
        $o = Outlet::factory()->create();
        $this->assertSame(50, $o->fresh()->geofence_radius_meters);
    }
}
